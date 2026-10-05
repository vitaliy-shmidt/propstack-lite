<?php

namespace PropstackLite\Frontend;

use PropstackLite\Routing\RouteDecision;
use PropstackLite\Routing\RouteResolver;
use PropstackLite\Routing\Router;
use PropstackLite\Routing\UrlGenerator;
use PropstackLite\Settings;
use PropstackLite\Storage\PropertyStore;
use PropstackLite\Support\Clock;

/**
 * Steuert Immobilien-Detailseiten – ausschließlich aus dem lokalen PropertyStore, nie über die API.
 *
 * Ablauf im WordPress-Request:
 *  parse_query        Hauptquery als eigene Route markieren (kein is_home)
 *  posts_pre_query    überflüssige wp_posts-Abfrage der Hauptquery überspringen
 *  pre_handle_404     Statusentscheidung; unbekannte ID → set_404 (Theme-404)
 *  template_redirect  Legacy- und Kanonisierungs-301, 410-Status, X-Robots-Tag, ViewModel
 *  template_include   single-property.php bzw. property-gone.php (Theme-Override möglich)
 *
 * Es wird KEIN virtuelles WP_Post erzeugt: Die Route ist für WordPress weder Seite noch Beitrag
 * (is_singular() = false). Themes erhalten über get_header()/get_footer() ihren normalen Rahmen.
 */
final class DetailController {

	public const STYLE_HANDLE = 'propstack-lite-detail';

	private static ?DetailController $current = null;

	private ?RouteDecision $decision = null;
	private ?array $view = null;

	public function __construct(
		private PropertyStore $store,
		private Settings $settings,
		private UrlGenerator $urls,
		private TemplateLoader $templates,
		private Clock $clock
	) {}

	public function register(): void {
		self::$current = $this;
		add_action( 'parse_query', [ $this, 'adjustQuery' ] );
		add_filter( 'posts_pre_query', [ $this, 'skipMainQuery' ], 10, 2 );
		add_filter( 'pre_handle_404', [ $this, 'handle404' ], 10, 2 );
		add_filter( 'redirect_canonical', [ $this, 'disableCoreCanonical' ] );
		add_action( 'template_redirect', [ $this, 'templateRedirect' ], 1 );
		add_filter( 'template_include', [ $this, 'templateInclude' ], 99 );
		add_filter( 'document_title_parts', [ $this, 'documentTitle' ] );
		add_filter( 'body_class', [ $this, 'bodyClass' ] );
		add_filter( 'wp_robots', [ $this, 'robots' ] );
		add_action( 'wp_head', [ $this, 'printCanonical' ], 1 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueueAssets' ] );
	}

	/** Controller des laufenden Requests (für Templates). */
	public static function current(): ?self {
		return self::$current;
	}

	/** ViewModel der Detailseite (nur bei HTTP 200), sonst null. */
	public function view(): ?array {
		return $this->view;
	}

	public function templates(): TemplateLoader {
		return $this->templates;
	}

	public function decision(): RouteDecision {
		if ( null === $this->decision ) {
			$resolver       = new RouteResolver( $this->settings->publicStatusIds(), $this->settings->reservedStatusIds(), $this->clock->now() );
			$this->decision = $resolver->resolve( $this->store->find( Router::requestedId() ) );
		}
		return $this->decision;
	}

	/* ------------------------------------------------------------------ Query */

	public function adjustQuery( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || ! $this->isRoute( $query ) ) {
			return;
		}
		$query->is_home       = false;
		$query->is_front_page = false;
		$query->is_archive    = false;
		$query->is_page       = false;
		$query->is_singular   = false;
	}

	public function skipMainQuery( mixed $posts, \WP_Query $query ): mixed {
		if ( $query->is_main_query() && $this->isRoute( $query ) ) {
			$query->found_posts   = 0;
			$query->max_num_pages = 0;
			return [];
		}
		return $posts;
	}

	public function handle404( mixed $preempt, \WP_Query $query ): mixed {
		if ( ! $this->isRoute( $query ) ) {
			return $preempt;
		}
		if ( 404 === $this->decision()->httpStatus ) {
			$query->set_404();
			status_header( 404 );
			nocache_headers();
		}
		return true; // WordPress soll die Route nicht selbst als 404 werten.
	}

	public function disableCoreCanonical( mixed $redirectUrl ): mixed {
		return Router::isPropertyRequest() ? false : $redirectUrl;
	}

	/* --------------------------------------------------------------- Response */

	public function templateRedirect(): void {
		if ( ! Router::isPropertyRequest() ) {
			return;
		}
		$decision = $this->decision();
		$stored   = $decision->stored;

		if ( Router::isLegacyRequest() ) {
			if ( null !== $stored ) {
				$this->redirect( $this->urls->legacyTarget( $stored ) );
			}
			return; // unbekannte ID: Theme-404 (bereits gesetzt)
		}

		if ( 410 === $decision->httpStatus ) {
			status_header( 410 );
			header( 'X-Robots-Tag: noindex, follow', true );
			$this->view = null;
			return;
		}

		if ( ! $decision->isRenderable() || null === $stored || null === $stored->property ) {
			return;
		}

		$canonical = $this->urls->canonicalUrl( $stored );
		if ( $this->urls->usesPrettyPermalinks() && $this->requestPath() !== (string) wp_parse_url( $canonical, PHP_URL_PATH ) ) {
			$this->redirect( $canonical );
		}

		if ( $decision->isNoindex() ) {
			header( 'X-Robots-Tag: noindex, follow', true );
		}

		$view       = PropertyViewModel::build( $stored->property, $decision, $canonical, $this->urls->overviewUrl() );
		$this->view = (array) apply_filters( 'psl_property_view_model', $view, $stored->property, $decision );
	}

	public function templateInclude( string $template ): string {
		if ( ! Router::isPropertyRequest() ) {
			return $template;
		}
		$decision = $this->decision();
		if ( 410 === $decision->httpStatus ) {
			return (string) apply_filters( 'psl_property_template', $this->templates->locate( 'property-gone.php' ), $decision );
		}
		if ( $decision->isRenderable() && null !== $this->view ) {
			return (string) apply_filters( 'psl_property_template', $this->templates->locate( 'single-property.php' ), $decision );
		}
		return $template; // 404: Theme-Template
	}

	/* -------------------------------------------------------------- Head/Body */

	public function documentTitle( array $parts ): array {
		if ( ! Router::isPropertyRequest() ) {
			return $parts;
		}
		$decision = $this->decision();
		if ( null !== $this->view ) {
			$parts['title'] = $this->view['title']; // wp_get_document_title() escaped die Teile selbst
		} elseif ( 410 === $decision->httpStatus ) {
			$parts['title'] = 'Immobilie nicht mehr verfügbar';
		}
		return $parts;
	}

	public function bodyClass( array $classes ): array {
		if ( ! Router::isPropertyRequest() || 404 === $this->decision()->httpStatus ) {
			return $classes;
		}
		$classes[] = 'psl-property';
		$classes[] = 'psl-property--' . sanitize_html_class( $this->decision()->state );
		return $classes;
	}

	public function robots( array $robots ): array {
		if ( Router::isPropertyRequest() && $this->decision()->isNoindex() ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}

	/** Basis-Canonical (Phase 2). Abstimmung mit Yoast/Rank Math folgt in Phase 5. */
	public function printCanonical(): void {
		if ( null !== $this->view && Router::isPropertyRequest() ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $this->view['canonicalUrl'] ) );
		}
	}

	public function enqueueAssets(): void {
		if ( ! Router::isPropertyRequest() || 404 === $this->decision()->httpStatus ) {
			return;
		}
		wp_enqueue_style( self::STYLE_HANDLE, PSL_URL . 'assets/css/psl-detail.css', [], PSL_VERSION );
	}

	/* ---------------------------------------------------------------- Helfer */

	private function isRoute( \WP_Query $query ): bool {
		$id = $query->get( Router::QV_ID );
		return is_scalar( $id ) && (bool) preg_match( '/^[0-9]{1,18}$/', (string) $id ) && (int) $id > 0;
	}

	/** Pfad der aktuellen Anfrage (dekodiert, ohne Query-String). */
	private function requestPath(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return rawurldecode( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
	}

	/** 301 auf eine eigene URL; vorhandene Kampagnen-Parameter (utm_*, gclid …) bleiben erhalten. */
	private function redirect( string $target ): void {
		$query = [];
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! is_string( $key ) || ! is_string( $value ) || in_array( $key, [ 'ps_id', Router::QV_ID, Router::QV_SLUG, Router::QV_LEGACY ], true ) ) {
				continue;
			}
			$query[ $key ] = rawurlencode( wp_unslash( $value ) );
		}
		if ( [] !== $query ) {
			$target = add_query_arg( $query, $target );
		}
		wp_safe_redirect( $target, 301, 'Propstack Listings Lite' );
		exit;
	}
}
