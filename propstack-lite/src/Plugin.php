<?php

namespace PropstackLite;

use PropstackLite\Admin\Notices;
use PropstackLite\Admin\SettingsPage;
use PropstackLite\Api\Client;
use PropstackLite\Api\UnitsEndpoint;
use PropstackLite\Cli\Command;
use PropstackLite\Frontend\DetailController;
use PropstackLite\Frontend\ListShortcode;
use PropstackLite\Frontend\TemplateLoader;
use PropstackLite\Leads\Cf7Integration;
use PropstackLite\Leads\InquiryMailFormatter;
use PropstackLite\Leads\RateLimiter;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Rest\WebhookController;
use PropstackLite\Seo\SeoContext;
use PropstackLite\Seo\SeoIntegration;
use PropstackLite\Seo\Sitemap\SitemapSource;
use PropstackLite\Routing\Router;
use PropstackLite\Routing\UrlGenerator;
use PropstackLite\Storage\PropertyStore;
use PropstackLite\Storage\Schema;
use PropstackLite\Support\Clock;
use PropstackLite\Support\Logger;
use PropstackLite\Sync\Scheduler;
use PropstackLite\Sync\SyncLock;
use PropstackLite\Sync\SyncService;
use PropstackLite\Sync\SyncState;
use PropstackLite\Theme\AvadaAdapter;

/** Verdrahtung und Hook-Registrierung. Dienste werden erst bei Bedarf erzeugt. */
final class Plugin {

	private static ?Plugin $instance = null;

	private Settings $settings;
	private ?SyncService $syncService = null;
	private bool $booted = false;

	private function __construct() {
		$this->settings = new Settings();
	}

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Schema::maybeUpgrade();

		$runIncremental = fn () => $this->syncService()->runIncremental();
		$runFull        = fn () => $this->syncService()->runFull();

		( new Scheduler( $this->settings ) )->register( $runIncremental, $runFull );
		Scheduler::schedule(); // selbstheilend, falls Events fehlen (z. B. nach Migration)
		( new WebhookController( $this->settings ) )->register( $runIncremental );

		$store     = PropertyStore::create();
		$urls      = new UrlGenerator();
		$templates = new TemplateLoader();

		( new Router() )->register();
		( new DetailController( $store, $this->settings, $urls, $templates, new Clock() ) )->register();
		( new ListShortcode( $store, $this->settings, $templates, $urls ) )->register();
		( new SeoIntegration( new SeoContext( $urls ), new SitemapSource( $store, $this->settings, $urls ) ) )->register();

		// Immobilienanfragen nur mit aktivem Contact Form 7 (alle aktiven Plugins sind zu plugins_loaded geladen).
		if ( Cf7Integration::isAvailable() ) {
			( new Cf7Integration(
				$this->settings,
				$store,
				$urls,
				new Clock(),
				new Logger(),
				new InquiryMailFormatter(),
				new RateLimiter( wp_salt( 'nonce' ) )
			) )->register();
		}

		// Theme-Adapter erst nach dem Laden des Themes prüfen (Theme-Klassen existieren vorher nicht).
		add_action(
			'after_setup_theme',
			static function (): void {
				if ( AvadaAdapter::isActive() ) {
					( new AvadaAdapter() )->register();
				}
			}
		);

		if ( is_admin() ) {
			( new SettingsPage( $this->settings, $this ) )->register();
			( new Notices( $this->settings ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'psl', new Command( $this ) );
		}
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function unitsEndpoint(): UnitsEndpoint {
		return new UnitsEndpoint( new Client( $this->settings->apiKey() ) );
	}

	public function syncService(): SyncService {
		return $this->syncService ??= new SyncService(
			$this->unitsEndpoint(),
			new PropertyMapper(),
			PropertyStore::create(),
			$this->settings,
			new SyncLock(),
			new SyncState(),
			new Logger(),
			new Clock()
		);
	}

	public static function activate(): void {
		Schema::install();
		Settings::migrateLegacy();
		// Bei der Aktivierung ist plugins_loaded bereits gelaufen: eigene Cron-Zeitpläne hier registrieren.
		add_filter( 'cron_schedules', [ new Scheduler( new Settings() ), 'addSchedules' ] );
		Scheduler::schedule();
		Scheduler::scheduleFullSoon();
		// Erst Regeln registrieren, dann flushen (init ist bei der Aktivierung schon gelaufen).
		Router::addRules();
		flush_rewrite_rules();
		update_option( Router::RULES_VERSION_OPTION, Router::RULES_VERSION, true );
	}

	public static function deactivate(): void {
		WebhookController::unschedule();
		( new SyncLock() )->release();
		// Regeln aus dem laufenden Request entfernen, sonst schreibt der Flush sie erneut.
		Router::removeRules();
		flush_rewrite_rules();
		delete_option( Router::RULES_VERSION_OPTION );
	}
}
