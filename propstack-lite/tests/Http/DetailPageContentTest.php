<?php

namespace PropstackLite\Tests\Http;

use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Routing\UrlGenerator;
use PropstackLite\Tests\Integration\IntegrationTestCase;

/**
 * Phase 3: Inhalt der vollständigen Detailseite über echte HTTP-Requests.
 * Voraussetzungen wie DetailRoutingTest (PSL_WP_LOAD, PSL_TEST_BASE_URL, mu-plugins aus tests/Support).
 */
final class DetailPageContentTest extends IntegrationTestCase {

	private const PUBLIC   = 711;
	private const RESERVED = 712;

	private const ID_FULL     = 990000101;
	private const ID_MINIMAL  = 990000102;
	private const ID_XSS      = 990000103;
	private const ID_RESERVED = 990000104;

	private const XSS = '<script>alert(1)</script>';

	private string $base;

	protected function setUp(): void {
		$base = getenv( 'PSL_TEST_BASE_URL' );
		if ( ! is_string( $base ) || '' === $base ) {
			$this->markTestSkipped( 'PSL_TEST_BASE_URL nicht gesetzt (laufender Testserver erforderlich).' );
		}
		parent::setUp();
		$this->base = rtrim( $base, '/' );
		$this->seed();
	}

	protected function tearDown(): void {
		delete_option( 'psl_test_status_label' );
		parent::tearDown();
	}

	private static function full(): array {
		return json_decode( (string) file_get_contents( PSL_FIXTURES . '/unit-full.json' ), true );
	}

	private function seed(): void {
		$this->settings( [ 'public_status_ids' => [ self::PUBLIC, self::RESERVED ], 'reserved_status_ids' => [ self::RESERVED ] ] );
		$mapper = new PropertyMapper();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$status = static fn ( int $id ) => [ 'id' => $id, 'name' => 'Test' ];

		$this->store->upsertActive( $mapper->map( array_merge( self::full(), [ 'id' => self::ID_FULL, 'property_status' => $status( self::PUBLIC ) ] ) ), $now );

		$this->store->upsertActive(
			$mapper->map( [ 'id' => self::ID_MINIMAL, 'property_status' => $status( self::PUBLIC ), 'marketing_type' => 'BUY', 'rs_type' => 'HOUSE', 'zip_code' => '14467', 'city' => 'Potsdam', 'hide_address' => true ] ),
			$now
		);

		$xss = array_merge( self::full(), [ 'id' => self::ID_XSS, 'property_status' => $status( self::PUBLIC ) ] );
		foreach ( [ 'description_note', 'furnishing_note', 'location_note', 'other_note' ] as $key ) {
			$xss[ $key ]['value'] = 'Text ' . self::XSS . ' <img src=x onerror=alert(2)> <a href="javascript:alert(3)">Link</a> Ende';
		}
		$xss['courtage_note']['value'] = 'Hinweis ' . self::XSS;
		$xss['broker']['name']         = 'Makler ' . self::XSS;
		$xss['broker']['position']     = '<b onmouseover=alert(4)>Position</b>';
		$xss['images'][0]['title']     = 'Bild "><script>alert(5)</script>';
		$xss['images'][1]['title']     = "Bild' onerror='alert(6)";
		$this->store->upsertActive( $mapper->map( $xss ), $now );

		$this->store->upsertActive( $mapper->map( array_merge( self::full(), [ 'id' => self::ID_RESERVED, 'property_status' => $status( self::RESERVED ) ] ) ), $now );
	}

	private function url( int $id ): string {
		return ( new UrlGenerator() )->canonicalUrl( $this->store->find( $id ) );
	}

	private function body( string $url ): string {
		$ch = curl_init( $url );
		curl_setopt_array( $ch, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30 ] );
		$body   = (string) curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$this->assertSame( 200, $status, $url );
		return $body;
	}

	/** HTML des Artikels (ohne Theme-Header/-Footer). */
	private static function article( string $body ): string {
		$start = strpos( $body, '<article id="psl-property-' );
		$end   = strpos( $body, '</article>', (int) $start );
		return false === $start || false === $end ? '' : substr( $body, $start, $end - $start );
	}

	private static function between( string $html, string $startNeedle, string $endNeedle ): string {
		$start = strpos( $html, $startNeedle );
		if ( false === $start ) {
			return '';
		}
		$end = strpos( $html, $endNeedle, $start );
		return substr( $html, $start, ( false === $end ? strlen( $html ) : $end ) - $start );
	}

	/* ---------------------------------------------------------------- Tests */

	public function test_full_object_renders_all_sections(): void {
		$body    = $this->body( $this->url( self::ID_FULL ) );
		$article = self::article( $body );

		$this->assertStringContainsString( '<nav class="psl-breadcrumb" aria-label="Brotkrümelnavigation">', $article );
		$this->assertStringContainsString( '<span aria-current="page">Großzügige 3-Zimmer-Wohnung mit Südbalkon</span>', $article );
		$this->assertSame( 1, substr_count( $body, '<h1' ), 'genau eine H1 auf der Seite (Theme-Titel ausgenommen)' );
		foreach ( [ 'Eckdaten', 'Objektbeschreibung', 'Ausstattung', 'Lage', 'Energie', 'Grundrisse', 'Sonstige Angaben', 'Ihr Ansprechpartner', 'Interesse an dieser Immobilie?' ] as $heading ) {
			$this->assertMatchesRegularExpression( '#<h2[^>]*>' . preg_quote( $heading, '#' ) . '</h2>#', $article, "Abschnitt fehlt: {$heading}" );
		}
		foreach ( [ 'Preise &amp; Kosten', 'Flächen &amp; Räume', 'Objekt &amp; Zustand' ] as $group ) {
			$this->assertStringContainsString( $group, $article );
		}
		$this->assertStringContainsString( '429.000 €', $article );
		$this->assertStringContainsString( '310,50 € / Monat', $article );
		$this->assertStringContainsString( '98,4 kWh/(m²·a)', $article );
		$this->assertStringContainsString( 'Haustiere nach Vereinbarung', $article );
		$this->assertStringContainsString( 'href="#psl-contact">Anfrage senden</a>', $article );
		$this->assertStringContainsString( 'id="psl-contact"', $article );
		$this->assertStringContainsString( '<dialog class="psl-lightbox"', $body );
		$this->assertStringNotContainsString( 'target="_blank"', $article );
		$this->assertStringNotContainsString( 'A_PLUS', $article );
		$this->assertStringNotContainsString( 'ROOF_STOREY', $article, 'keine Roh-Enums' );
	}

	public function test_gallery_markup_images_and_lightbox_controls(): void {
		$body    = $this->body( $this->url( self::ID_FULL ) );
		$gallery = self::between( $body, 'data-psl-gallery="main"', '</section>' );

		$this->assertSame( 3, substr_count( $gallery, 'data-psl-index=' ), '3 öffentliche Galeriebilder' );
		$this->assertMatchesRegularExpression( '#<img class="psl-gallery__image" src="[^"]+101-medium\.jpg"\s+srcset="[^"]+600w, [^"]+1920w"\s+sizes="[^"]+"\s+alt="Wohnzimmer mit Dielen" fetchpriority="high"#', $gallery );
		$this->assertStringNotContainsString( 'fetchpriority="high" loading="lazy"', $gallery );
		$this->assertSame( 2, substr_count( $gallery, 'loading="lazy"' ), 'Vorschaubilder lazy' );
		$this->assertStringContainsString( 'width="280" height="280"', $gallery );
		$this->assertStringContainsString( 'aria-label="Bild 1 von 3 vergrößern"', $gallery );
		$this->assertStringContainsString( '<button type="button" class="psl-gallery__all" data-psl-open="0" hidden>', $gallery );
		$this->assertStringNotContainsString( 'FLOORPLAN', $gallery, 'Grundriss nicht in der Galerie' );

		$plans = self::between( $body, 'data-psl-gallery="floorplans"', '</section>' );
		$this->assertStringContainsString( '106-FLOORPLAN', $plans );
		$this->assertStringNotContainsString( 'PRIVATEPLAN', $plans );

		$dialog = self::between( $body, '<dialog class="psl-lightbox"', '</dialog>' );
		foreach ( [ 'aria-label="Bildansicht schließen"', 'aria-label="Vorheriges Bild"', 'aria-label="Nächstes Bild"', 'aria-live="polite"' ] as $a11y ) {
			$this->assertStringContainsString( $a11y, $dialog );
		}
		$this->assertSame( 3, substr_count( $dialog, '<button type="button"' ), 'Steuerelemente sind Buttons' );
	}

	public function test_privacy_in_full_page_html(): void {
		$body = $this->body( $this->url( self::ID_FULL ) );
		foreach ( [ 'Versteckweg', '52.5432', '13.4211', 'PRIVATE', 'NOEXPOSE', 'PRIVATEPLAN', 'Eigentümerfoto', 'max.intern@example.com', '5555555', 'ph-full', 'OLD-FULL', 'INTERN: Schlüssel', 'FULL-INTERNAL-TOKEN', 'Alleinauftrag', 'crm.propstack.de', 'VW7-WE4' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $body, "Darf nicht im HTML stehen: {$needle}" );
		}
		$this->assertStringContainsString( '10437 Berlin (Prenzlauer Berg)', $body );
		$this->assertStringContainsString( 'kontakt@example.com', $body, 'öffentliche E-Mail' );
		$this->assertStringContainsString( 'tel:+49306666666', $body, 'öffentliche Telefonnummer' );
		$this->assertStringContainsString( 'Dr. Max Beispiel', $body );
	}

	public function test_minimal_object_has_no_empty_sections(): void {
		$body    = $this->body( $this->url( self::ID_MINIMAL ) );
		$article = self::article( $body );

		foreach ( [ 'Objektbeschreibung', 'Ausstattung', 'Energie', 'Grundrisse', 'Sonstige Angaben', 'Ihr Ansprechpartner' ] as $heading ) {
			$this->assertDoesNotMatchRegularExpression( '#<h2[^>]*>' . preg_quote( $heading, '#' ) . '</h2>#', $article, "leere Sektion: {$heading}" );
		}
		$this->assertStringContainsString( 'psl-gallery--empty', $article, 'neutraler Bild-Platzhalter' );
		$this->assertStringNotContainsString( '<img', $article, 'kein kaputtes <img>' );
		$this->assertStringContainsString( 'Preis auf Anfrage', $article );
		$this->assertStringNotContainsString( '<dialog', $body );
		$this->assertStringNotContainsString( 'psl-gallery.js', $body, 'Galerie-Skript nur bei Bildern' );
		$this->assertStringNotContainsString( "rel='preconnect' href='https://images.propstack.de'", $body );
	}

	public function test_xss_payloads_are_neutralized(): void {
		update_option( 'psl_test_status_label', 'Reserviert ' . self::XSS );
		foreach ( [ self::ID_XSS, self::ID_RESERVED ] as $id ) {
			$body = $this->body( $this->url( $id ) );
			foreach ( [ '<script>alert(', 'onerror=alert', "onerror='alert", 'onmouseover=alert', 'javascript:alert' ] as $payload ) {
				$this->assertStringNotContainsString( $payload, $body, "Objekt {$id}: {$payload}" );
			}
		}
		$reserved = $this->body( $this->url( self::ID_RESERVED ) );
		$this->assertStringContainsString( 'psl-badge--reserved">Reserviert &lt;script&gt;alert(1)&lt;/script&gt;</span>', $reserved, 'Statuslabel aus Filter escaped' );
	}

	public function test_assets_only_on_detail_pages(): void {
		$detail = $this->body( $this->url( self::ID_FULL ) );
		$this->assertStringContainsString( 'psl-detail.css', $detail );
		$this->assertSame( 1, preg_match( '#<script[^>]*psl-gallery\.js[^>]*>#', $detail, $tag ), 'Galerie-Skript eingebunden' );
		$this->assertMatchesRegularExpression( '#\sdefer[\s>]#', $tag[0], 'Skript mit defer' );
		$this->assertStringContainsString( "href='https://images.propstack.de'", $detail, 'preconnect zum Bild-CDN' );
		$this->assertStringNotContainsString( 'jquery', strtolower( self::between( $detail, 'psl-gallery.js', '</script>' ) ) );

		$overview = $this->body( $this->base . '/immobilien/' );
		$home     = $this->body( $this->base . '/' );
		foreach ( [ $overview, $home ] as $page ) {
			$this->assertStringNotContainsString( 'psl-detail.css', $page );
			$this->assertStringNotContainsString( 'psl-gallery.js', $page );
		}
	}

	public function test_no_propstack_requests(): void {
		if ( ! file_exists( WP_CONTENT_DIR . '/mu-plugins/psl-http-spy.php' ) ) {
			$this->markTestSkipped( 'mu-plugin psl-http-spy.php nicht installiert.' );
		}
		$log = WP_CONTENT_DIR . '/psl-http-spy.log';
		file_put_contents( $log, '' );
		foreach ( [ self::ID_FULL, self::ID_MINIMAL, self::ID_XSS, self::ID_RESERVED ] as $id ) {
			$this->body( $this->url( $id ) );
		}
		$lines = array_filter( explode( "\n", (string) file_get_contents( $log ) ) );
		$this->assertSame( [], array_values( array_filter( $lines, static fn ( $l ) => str_contains( $l, 'propstack' ) ) ) );
		file_put_contents( sys_get_temp_dir() . '/psl-http-spy-result-phase3.txt', '4 Detailseiten, ' . count( $lines ) . " ausgehende Requests gesamt\n" );
	}
}
