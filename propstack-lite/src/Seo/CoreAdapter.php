<?php

namespace PropstackLite\Seo;

/**
 * Ohne SEO-Plugin: Das Plugin gibt alle Head-Tags selbst aus – je genau einmal:
 * <title> (über WordPress' title-tag), meta description, canonical, robots (über wp_robots),
 * Open Graph, Twitter Card und ein JSON-LD-Block.
 *
 * WordPress selbst gibt auf der Route keinen Canonical aus (kein is_singular()).
 */
final class CoreAdapter {

	public function __construct( private SeoContext $context ) {}

	public function register(): void {
		add_filter( 'pre_get_document_title', [ $this, 'title' ], 20 );
		add_filter( 'wp_robots', [ $this, 'robots' ], 20 );
		add_action( 'wp_head', [ $this, 'head' ], 1 );
		// Immobilienübersicht (normale WordPress-Seite): nur Canonical, Robots und Seitenzusatz ergänzen.
		add_filter( 'get_canonical_url', [ $this, 'listingCanonical' ], 20 );
		add_filter( 'document_title_parts', [ $this, 'listingTitleParts' ], 20 );
	}

	/** WordPress' eigener Canonical der Seite (rel_canonical) → selbstreferenzierende Listen-URL. */
	public function listingCanonical( mixed $url ): mixed {
		$listing = $this->context->listing();
		return null === $listing ? $url : $listing->canonical;
	}

	public function listingTitleParts( mixed $parts ): mixed {
		$listing = $this->context->listing();
		if ( null === $listing || null === $listing->titleSuffix || ! is_array( $parts ) ) {
			return $parts;
		}
		$parts['page'] = $listing->titleSuffix;
		return $parts;
	}

	/** Vollständiger Titel inkl. Marke; pre_get_document_title wird ungefiltert ausgegeben → escapen. */
	public function title( mixed $title ): mixed {
		$data = $this->context->data();
		return null === $data ? $title : esc_html( $data->title );
	}

	public function robots( array $robots ): array {
		$data = $this->context->data() ?? $this->listingNoindex();
		if ( null === $data ) {
			return $robots;
		}
		return self::applyRobots( $robots, $data );
	}

	/** wp_robots-Array: index/noindex und follow/nofollow exklusiv setzen, sonstige Direktiven behalten. */
	public static function applyRobots( array $robots, SeoData|ListingSeoData $data ): array {
		unset( $robots['index'], $robots['noindex'], $robots['follow'], $robots['nofollow'] );
		$robots[ $data->robots['index'] ? 'index' : 'noindex' ]   = true;
		$robots[ $data->robots['follow'] ? 'follow' : 'nofollow' ] = true;
		if ( ! $data->robots['index'] ) {
			unset( $robots['max-image-preview'] );
		}
		return $robots;
	}

	public function head(): void {
		$data = $this->context->data();
		if ( null === $data ) {
			return;
		}
		echo self::render( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escaped jedes Attribut
	}

	/** HTML der Head-Tags (ohne <title> und robots – die kommen von WordPress). */
	public static function render( SeoData $data ): string {
		$out = "<!-- Propstack Listings Lite SEO -->\n";
		if ( null !== $data->description ) {
			$out .= sprintf( '<meta name="description" content="%s" />' . "\n", esc_attr( $data->description ) );
		}
		if ( null !== $data->canonical ) {
			$out .= sprintf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $data->canonical ) );
		}
		foreach ( (array) $data->openGraph as $key => $value ) {
			$content = in_array( $key, [ 'url', 'image' ], true ) ? esc_url( $value ) : esc_attr( $value );
			$out    .= sprintf( '<meta property="og:%s" content="%s" />' . "\n", esc_attr( $key ), $content );
		}
		foreach ( (array) $data->twitter as $key => $value ) {
			$content = 'image' === $key ? esc_url( $value ) : esc_attr( $value );
			$out    .= sprintf( '<meta name="twitter:%s" content="%s" />' . "\n", esc_attr( $key ), $content );
		}
		if ( null !== $data->jsonLd ) {
			$out .= '<script type="application/ld+json">' . self::json( $data->jsonLd ) . "</script>\n";
		}
		return $out . "<!-- /Propstack Listings Lite SEO -->\n";
	}

	/** JSON für <script>: `<`, `>`, `&`, `'` als \u-Escapes → kein Ausbruch aus dem Script-Block. */
	public static function json( array $data ): string {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
	}

	/**
	 * Übersicht: nur herabstufen (noindex bei Filtern/Sortierung/ungültiger Seite), nie eine
	 * redaktionelle noindex-Einstellung der Seite oder „Suchmaschinen abhalten“ auf index heben.
	 */
	private function listingNoindex(): ?ListingSeoData {
		$listing = $this->context->listing();
		return null !== $listing && ! $listing->isIndexable() ? $listing : null;
	}
}
