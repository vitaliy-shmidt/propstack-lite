<?php

namespace PropstackLite;

use PropstackLite\Admin\Notices;
use PropstackLite\Admin\SettingsPage;
use PropstackLite\Api\Client;
use PropstackLite\Api\UnitsEndpoint;
use PropstackLite\Cli\Command;
use PropstackLite\Frontend\ListShortcode;
use PropstackLite\Frontend\TemplateLoader;
use PropstackLite\Frontend\UrlGenerator;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Rest\WebhookController;
use PropstackLite\Storage\PropertyStore;
use PropstackLite\Storage\Schema;
use PropstackLite\Support\Clock;
use PropstackLite\Support\Logger;
use PropstackLite\Sync\Scheduler;
use PropstackLite\Sync\SyncLock;
use PropstackLite\Sync\SyncService;
use PropstackLite\Sync\SyncState;

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

		( new ListShortcode( PropertyStore::create(), $this->settings, new TemplateLoader(), new UrlGenerator() ) )->register();

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
		// Rewrite-Regeln der Version 0.2.x (/immobilie/…) entfernen.
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		WebhookController::unschedule();
		( new SyncLock() )->release();
		flush_rewrite_rules();
	}
}
