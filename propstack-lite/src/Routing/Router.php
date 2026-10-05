<?php

namespace PropstackLite\Routing;

/**
 * Rewrite-Regeln und Query-Vars für Immobilien-Detailseiten.
 *
 * Regeln (Priorität „top“):
 *   ^immobilien/(?:{slug}-)?{id}/?$  → psl_property={id}&psl_slug={slug}
 *   ^immobilie/(?:{slug}-)?{id}/?$   → psl_property={id}&psl_legacy=1      (URLs aus 0.2.x)
 * Zusätzlich `/immobilie/?ps_id={id}` über den `request`-Filter (Query-String ist für Rewrite-Regeln unsichtbar).
 *
 * Lifecycle: Regeln werden bei jedem `init` registriert (billig, ohne Flush). Geflusht wird nur bei
 * Aktivierung/Deaktivierung und einmalig, wenn sich RULES_VERSION ändert (Plugin-Update).
 */
final class Router {

	public const QV_ID     = 'psl_property';
	public const QV_SLUG   = 'psl_slug';
	public const QV_LEGACY = 'psl_legacy';

	public const RULES_VERSION        = '1';
	public const RULES_VERSION_OPTION = 'psl_rewrite_version';

	/** IDs: 1–18 Ziffern (kein Integer-Überlauf). */
	private const ID_PATTERN = '([0-9]{1,18})';

	public function register(): void {
		add_action( 'init', [ $this, 'onInit' ] );
		add_filter( 'query_vars', [ $this, 'queryVars' ] );
		add_filter( 'request', [ $this, 'mapLegacyQuery' ] );
		add_filter( 'do_redirect_guess_404_permalink', [ $this, 'disableGuessForLegacy' ] );
	}

	/**
	 * Legacy-Pfade ohne gültige ID sollen ein echtes 404 liefern. WordPress würde sonst per
	 * redirect_guess_404_permalink() auf die ähnlich benannte Übersicht /immobilien/ „raten“.
	 */
	public function disableGuessForLegacy( mixed $doGuess ): mixed {
		global $wp;
		$path = $wp instanceof \WP ? trim( (string) $wp->request, '/' ) : '';
		return ( 'immobilie' === $path || str_starts_with( $path, 'immobilie/' ) ) ? false : $doGuess;
	}

	public function onInit(): void {
		self::addRules();
		if ( get_option( self::RULES_VERSION_OPTION ) !== self::RULES_VERSION ) {
			flush_rewrite_rules( false );
			update_option( self::RULES_VERSION_OPTION, self::RULES_VERSION, true );
		}
	}

	/** @return array<string, string> Regex => Query */
	public static function rules(): array {
		return [
			'^' . UrlGenerator::BASE . '/(?:([^/]+)-)?' . self::ID_PATTERN . '/?$' =>
				'index.php?' . self::QV_ID . '=$matches[2]&' . self::QV_SLUG . '=$matches[1]',
			'^immobilie/(?:([^/]+)-)?' . self::ID_PATTERN . '/?$' =>
				'index.php?' . self::QV_ID . '=$matches[2]&' . self::QV_LEGACY . '=1',
		];
	}

	public static function addRules(): void {
		foreach ( self::rules() as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/** Entfernt die Regeln aus dem laufenden Request (Deaktivierung), damit der anschließende Flush sie nicht erneut schreibt. */
	public static function removeRules(): void {
		global $wp_rewrite;
		if ( ! $wp_rewrite instanceof \WP_Rewrite ) {
			return;
		}
		foreach ( array_keys( self::rules() ) as $regex ) {
			unset( $wp_rewrite->extra_rules_top[ $regex ] );
		}
	}

	public function queryVars( array $vars ): array {
		return array_merge( $vars, [ self::QV_ID, self::QV_SLUG, self::QV_LEGACY ] );
	}

	/**
	 * Legacy `/immobilie/?ps_id=123` (0.2.x). Greift nur bei genau diesem Pfad und rein numerischer ID;
	 * sonst bleibt die Anfrage unverändert (bestehende Seite oder normales 404).
	 */
	public function mapLegacyQuery( array $vars ): array {
		$psId = isset( $_GET['ps_id'] ) && is_string( $_GET['ps_id'] ) ? $_GET['ps_id'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur lesende Weiterleitung.
		if ( '' === $psId ) {
			return $vars;
		}
		// Angefragter Pfad relativ zur Installation – unabhängig davon, ob WordPress ihn als Seite
		// (pagename) oder Beitrag (name) interpretiert, und ob die alte Seite noch existiert.
		global $wp;
		$path = $wp instanceof \WP ? trim( (string) $wp->request, '/' ) : '';
		if ( 'immobilie' !== $path ) {
			return $vars;
		}
		if ( ! preg_match( '/^[0-9]{1,18}$/', $psId ) ) {
			return $vars;
		}
		return [ self::QV_ID => $psId, self::QV_LEGACY => '1' ];
	}

	public static function requestedId(): int {
		$id = get_query_var( self::QV_ID );
		return is_scalar( $id ) && preg_match( '/^[0-9]{1,18}$/', (string) $id ) ? (int) $id : 0;
	}

	public static function isPropertyRequest(): bool {
		return self::requestedId() > 0;
	}

	public static function isLegacyRequest(): bool {
		return '1' === (string) get_query_var( self::QV_LEGACY );
	}
}
