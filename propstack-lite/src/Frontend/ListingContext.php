<?php

namespace PropstackLite\Frontend;

use PropstackLite\Routing\UrlGenerator;
use PropstackLite\Storage\PropertySearchResult;

/**
 * Erkennt, ob der laufende Request eine interaktive Immobilienliste zeigt, und liefert deren
 * validierten Zustand – für die SEO-Schicht, die im <head> (also vor dem Shortcode) entscheiden muss.
 *
 * Erkennung (nach `wp`): Einzelseite/-beitrag, deren Inhalt `[propstack_list]` enthält (auch
 * verschachtelt in Page-Builder-Shortcodes), oder deren Permalink der Übersichts-URL entspricht.
 * Erfasst ein Page-Builder den Shortcode anders (z. B. kodierte Code-Blöcke), kann ein Theme die
 * Attribute über den Filter `psl_listing_page_atts` liefern. Statische Listen (show_filters,
 * show_sort und pagination aus) gelten nicht als Suchseite.
 */
final class ListingContext {

	private bool $resolved = false;
	private ?ListingRequest $request = null;
	private ?string $pageUrl = null;

	public function __construct( private UrlGenerator $urls, private ListingService $service ) {}

	public function request(): ?ListingRequest {
		$this->resolve();
		return $this->request;
	}

	/** Permalink der Listenseite (Basis für Canonical und Pagination). */
	public function pageUrl(): ?string {
		$this->resolve();
		return $this->pageUrl;
	}

	public function result(): ?PropertySearchResult {
		$request = $this->request();
		return null === $request ? null : $this->service->search( $request->criteria() );
	}

	private function resolve(): void {
		if ( $this->resolved ) {
			return;
		}
		if ( ! did_action( 'wp' ) ) {
			return; // Hauptquery noch nicht bekannt – nicht cachen
		}
		$this->resolved = true;

		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$pageUrl = (string) get_permalink( $post );
		$atts    = self::shortcodeAtts( (string) $post->post_content );
		if ( null === $atts && untrailingslashit( strtok( $pageUrl, '?' ) ) === untrailingslashit( $this->urls->overviewUrl() ) ) {
			$atts = []; // Übersichtsseite, Shortcode nicht im Inhalt erkennbar → Standardattribute
		}
		$atts = apply_filters( 'psl_listing_page_atts', $atts, $post );
		if ( ! is_array( $atts ) ) {
			return;
		}

		$config = ListingConfig::fromAtts( array_merge( ListShortcode::DEFAULTS, array_change_key_case( $atts, CASE_LOWER ) ) );
		if ( ! $config->isInteractive() ) {
			return;
		}
		$this->pageUrl = $pageUrl;
		$this->request = ListingRequest::fromQuery( wp_unslash( $_GET ), $config ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- öffentliche GET-Suche, Werte werden in ListingRequest gewhitelistet
	}

	/** Attribute des ersten `[propstack_list]` im Inhalt oder null. @return array<string, string>|null */
	public static function shortcodeAtts( string $content ): ?array {
		if ( ! str_contains( $content, '[' . ListShortcode::TAG ) ) {
			return null;
		}
		if ( ! preg_match( '/' . get_shortcode_regex( [ ListShortcode::TAG ] ) . '/', $content, $m ) || '[' === $m[1] ) {
			return null; // nicht gefunden bzw. maskiert ([[propstack_list]])
		}
		$atts = shortcode_parse_atts( $m[3] );
		return is_array( $atts ) ? $atts : [];
	}
}
