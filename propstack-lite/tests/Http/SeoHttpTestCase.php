<?php

namespace PropstackLite\Tests\Http;

use PropstackLite\Domain\Property;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Routing\UrlGenerator;
use PropstackLite\Seo\SeoPlugins;
use PropstackLite\Tests\Integration\IntegrationTestCase;

/**
 * Basis der SEO-HTTP-Tests. Jeder Modus läuft gegen dieselbe Testinstanz mit anderem Plugin-Zustand:
 *
 *   Core      keine SEO-Plugins aktiv                 → SeoCoreTest
 *   Yoast     nur wordpress-seo aktiv                 → SeoYoastTest
 *   RankMath  nur seo-by-rank-math aktiv              → SeoRankMathTest
 *   Konflikt  beide aktiv                             → SeoConflictTest
 *
 * Tests, deren Modus nicht dem Instanzzustand entspricht, werden übersprungen
 * (Ablauf in docs/testing.md: Plugins per WP-CLI umschalten, Suite erneut starten).
 */
abstract class SeoHttpTestCase extends IntegrationTestCase {

	protected const PUBLIC   = 701;
	protected const RESERVED = 702;
	protected const SOLD     = 703;
	protected const OTHER    = 799;

	protected const ID_ACTIVE     = 990000101;
	protected const ID_RESERVED   = 990000102;
	protected const ID_SOLD       = 990000103;
	protected const ID_REMOVED    = 990000104;
	protected const ID_NOT_PUBLIC = 990000105;
	protected const ID_ON_REQUEST = 990000106;
	protected const ID_XSS        = 990000107;
	protected const ID_UNKNOWN    = 990000999;

	protected const XSS = '<script>alert(1)</script>';

	private const YOAST_FILE    = 'wordpress-seo/wp-seo.php';
	private const RANKMATH_FILE = 'seo-by-rank-math/rank-math.php';

	protected string $base;

	/** Erwarteter Modus der Testklasse: 'core', 'yoast', 'rankmath' oder 'conflict'. */
	abstract protected static function mode(): string;

	protected function setUp(): void {
		$base = getenv( 'PSL_TEST_BASE_URL' );
		if ( ! is_string( $base ) || '' === $base ) {
			$this->markTestSkipped( 'PSL_TEST_BASE_URL nicht gesetzt (laufender Testserver erforderlich).' );
		}
		if ( ! function_exists( 'get_option' ) ) {
			$this->markTestSkipped( 'WordPress erforderlich (PSL_WP_LOAD).' );
		}
		$active = (array) get_option( 'active_plugins', [] );
		$yoast  = in_array( self::YOAST_FILE, $active, true );
		$rm     = in_array( self::RANKMATH_FILE, $active, true );
		$state  = $yoast && $rm ? 'conflict' : ( $yoast ? 'yoast' : ( $rm ? 'rankmath' : 'core' ) );
		if ( static::mode() !== $state ) {
			$this->markTestSkipped( 'Instanz läuft im SEO-Modus „' . $state . '“, Test benötigt „' . static::mode() . '“.' );
		}
		parent::setUp();
		$this->base = rtrim( $base, '/' );
		$this->assertSame( $this->base, rtrim( home_url(), '/' ), 'Testserver und PSL_WP_LOAD müssen dieselbe Instanz sein' );
		delete_option( 'psl_test_sitemap_max_urls' );
		$this->seed();
		\PropstackLite\Seo\Sitemap\SitemapCache::flush(); // Testdaten am Sync vorbei → Caches wie nach einem Sync leeren
	}

	protected function tearDown(): void {
		delete_option( 'psl_test_sitemap_max_urls' );
		parent::tearDown();
	}

	/* ------------------------------------------------------------- Testdaten */

	private function seed(): void {
		$this->settings(
			[
				'public_status_ids'   => [ self::PUBLIC, self::RESERVED ],
				'reserved_status_ids' => [ self::RESERVED ],
				'sold_status_ids'     => [ self::SOLD ],
			]
		);
		$mapper = new PropertyMapper();
		$now    = gmdate( 'Y-m-d H:i:s' );

		// Aktiv: verborgene Adresse, Ortsteil, Bilder (privat + Grundriss als Köder), Merkmale.
		$fixture = json_decode( (string) file_get_contents( PSL_FIXTURES . '/unit-full.json' ), true );
		$fixture['id']              = self::ID_ACTIVE;
		$fixture['property_status'] = [ 'id' => self::PUBLIC, 'name' => 'Website' ];
		$this->store->upsertActive( $mapper->map( $fixture ), $now );

		$this->store->upsertActive( $mapper->map( self::raw( self::ID_RESERVED, self::RESERVED, [ 'rs_type' => 'HOUSE', 'title' => [ 'label' => 'T', 'value' => 'Reserviertes Haus' ] ] ) ), $now );

		$this->store->upsertActive( $mapper->map( self::raw( self::ID_SOLD, self::PUBLIC, [ 'marketing_type' => 'RENT', 'base_rent' => [ 'label' => 'Kaltmiete', 'value' => 900.0 ], 'price' => null ] ) ), $now );
		$this->store->markSold( self::ID_SOLD, self::SOLD, gmdate( 'Y-m-d H:i:s', time() - 5 * DAY_IN_SECONDS ) );

		$this->store->upsertActive( $mapper->map( self::raw( self::ID_REMOVED, self::PUBLIC ) ), $now );
		$this->store->markRemoved( self::ID_REMOVED, self::OTHER, $now );

		$this->store->upsertActive( $mapper->map( self::raw( self::ID_NOT_PUBLIC, self::OTHER ) ), $now );

		$this->store->upsertActive(
			$mapper->map(
				self::raw(
					self::ID_ON_REQUEST,
					self::PUBLIC,
					[
						'price'            => [ 'label' => 'Preis', 'value' => null ],
						'price_on_inquiry' => [ 'label' => 'Preis auf Anfrage', 'value' => true ],
						'images'           => [],
					]
				)
			),
			$now
		);

		// Defense in Depth: unbereinigte Werte direkt im Store (am Mapper vorbei).
		$this->store->upsertActive(
			Property::fromArray(
				[
					'id'            => self::ID_XSS,
					'slug'          => 'xss-seo',
					'title'         => 'Titel ' . self::XSS . ' "quote\' &amp;',
					'statusId'      => self::PUBLIC,
					'marketingType' => 'BUY',
					'rsType'        => 'APARTMENT',
					'price'         => 1000.0,
					'rooms'         => 2.0,
					'address'       => [ 'hidden' => true, 'city' => 'Ort ' . self::XSS, 'district' => '"><script>alert(2)</script>' ],
					'images'        => [ [ 'id' => 1, 'title' => 'Bild "><script>alert(3)</script>', 'big' => 'https://images.propstack.de/p/x"onerror="alert(4).jpg' ] ],
				]
			),
			$now
		);
	}

	/* ---------------------------------------------------------------- Helfer */

	protected function url( int $id ): string {
		return ( new UrlGenerator() )->canonicalUrl( $this->store->find( $id ) );
	}

	/** @return array{status: int, headers: array<string, string>, body: string} */
	protected function get( string $url ): array {
		$ch = curl_init( $url );
		curl_setopt_array( $ch, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30 ] );
		$raw     = (string) curl_exec( $ch );
		$status  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$hsize   = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$headers = [];
		foreach ( explode( "\r\n", substr( $raw, 0, $hsize ) ) as $line ) {
			if ( str_contains( $line, ':' ) ) {
				[ $k, $v ]                          = explode( ':', $line, 2 );
				$headers[ strtolower( trim( $k ) ) ] = trim( $v );
			}
		}
		return [ 'status' => $status, 'headers' => $headers, 'body' => substr( $raw, $hsize ) ];
	}

	protected static function head( string $html ): string {
		return preg_match( '#<head[^>]*>(.*?)</head>#s', $html, $m ) ? $m[1] : '';
	}

	/** Anzahl der Head-Tags je Art – Grundlage für „keine Dubletten“. @return array<string, int> */
	protected static function tagCounts( string $head ): array {
		return [
			'title'       => preg_match_all( '#<title[\s>]#i', $head ),
			'description' => preg_match_all( '#<meta\s+name=["\']description["\']#i', $head ),
			'canonical'   => preg_match_all( '#<link\s+rel=["\']canonical["\']#i', $head ),
			'robots'      => preg_match_all( '#<meta\s+name=["\']robots["\']#i', $head ),
			'og:title'    => preg_match_all( '#property=["\']og:title["\']#i', $head ),
			'og:url'      => preg_match_all( '#property=["\']og:url["\']#i', $head ),
			'og:image'    => preg_match_all( '#property=["\']og:image["\']#i', $head ),
			'og:locale'   => preg_match_all( '#property=["\']og:locale["\']#i', $head ),
			'twitter:card' => preg_match_all( '#name=["\']twitter:card["\']#i', $head ),
			'jsonld'      => preg_match_all( '#<script[^>]+application/ld\+json#i', $head ),
		];
	}

	protected static function meta( string $head, string $attr, string $name ): ?string {
		if ( preg_match( '#<meta\s+' . $attr . '=["\']' . preg_quote( $name, '#' ) . '["\']\s+content=["\']([^"\']*)["\']#i', $head, $m ) ) {
			return html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return null;
	}

	protected static function title( string $head ): ?string {
		return preg_match( '#<title[^>]*>(.*?)</title>#s', $head, $m ) ? html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : null;
	}

	protected static function canonical( string $head ): ?string {
		return preg_match( '#<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)["\']#i', $head, $m ) ? html_entity_decode( $m[1] ) : null;
	}

	/** Alle Schema-Knoten aller JSON-LD-Blöcke (@graph aufgelöst). @return list<array<string, mixed>> */
	protected static function schemaNodes( string $head ): array {
		preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#s', $head, $m );
		$nodes = [];
		foreach ( $m[1] as $json ) {
			$data = json_decode( $json, true );
			self::assertIsArray( $data, 'JSON-LD muss gültiges JSON sein' );
			foreach ( isset( $data['@graph'] ) ? $data['@graph'] : [ $data ] as $node ) {
				$nodes[] = $node;
			}
		}
		return $nodes;
	}

	/** @return list<array<string, mixed>> */
	protected static function nodesOfType( array $nodes, string $type ): array {
		return array_values( array_filter( $nodes, static fn ( $n ) => in_array( $type, (array) ( $n['@type'] ?? [] ), true ) ) );
	}

	/* ----------------------------------------------------- Gemeinsame Prüfungen */

	/** Je genau ein Title/Description/Canonical/Robots/OG-Set/JSON-LD-Block und korrekte Werte. */
	protected function assertActiveHead( ?string $expectTitle = null ): void {
		$canonical = $this->url( self::ID_ACTIVE );
		$r         = $this->get( $canonical );
		$this->assertSame( 200, $r['status'] );
		$head   = self::head( $r['body'] );
		$counts = self::tagCounts( $head );
		foreach ( $counts as $tag => $n ) {
			$this->assertSame( 1, $n, "genau ein {$tag}: " . json_encode( $counts ) );
		}
		$brand = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$this->assertSame( $expectTitle ?? '3-Zimmer-Wohnung kaufen in Berlin-Prenzlauer Berg | ' . $brand, self::title( $head ) );
		$this->assertSame( $canonical, self::canonical( $head ) );
		$this->assertSame( $canonical, self::meta( $head, 'property', 'og:url' ) );
		$this->assertStringStartsWith( 'Wohnung zum Kauf in Berlin-Prenzlauer Berg: 3 Zimmer', (string) self::meta( $head, 'name', 'description' ) );
		$this->assertMatchesRegularExpression( '#<meta\s+name=["\']robots["\']\s+content=["\'][^"\']*\bindex\b[^"\']*\bfollow\b#', $head );
		$this->assertStringNotContainsString( 'noindex', $head );
		$this->assertSame( 'de_DE', self::meta( $head, 'property', 'og:locale' ) );
		$this->assertSame( 'https://images.propstack.de/p/full/101-big.jpg', self::meta( $head, 'property', 'og:image' ) );
		$this->assertSame( 'summary_large_image', self::meta( $head, 'name', 'twitter:card' ) );
		$this->assertStringContainsString( '<h1', $r['body'] );
		$this->assertMatchesRegularExpression( '#<h1[^>]*>\s*Großzügige 3-Zimmer-Wohnung mit Südbalkon#', $r['body'], 'H1 bleibt der Propstack-Titel' );

		// Datenschutz im Head: verborgene Adresse, private Bilder, Grundrisse.
		foreach ( [ 'Versteckweg', '52.5432', '13.4211', 'PRIVATE', 'NOEXPOSE', 'FLOORPLAN', 'streetAddress', 'GeoCoordinates' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $head, "Head enthält {$needle}" );
		}
		$this->assertValidSchema( self::schemaNodes( $head ), $canonical );
	}

	/** Schema-Validierung: Pflichtfelder, Preise > 0, Typen, aufgelöste @id-Referenzen. */
	protected function assertValidSchema( array $nodes, string $canonical ): void {
		$listings = self::nodesOfType( $nodes, 'RealEstateListing' );
		$this->assertCount( 1, $listings, 'genau ein RealEstateListing' );
		$listing = $listings[0];
		$this->assertSame( $canonical, $listing['url'] );
		$this->assertNotEmpty( $listing['name'] );
		$this->assertSame( 'Großzügige 3-Zimmer-Wohnung mit Südbalkon', $listing['name'] );
		$this->assertSame( 'Offer', $listing['offers']['@type'] );
		$this->assertSame( 'EUR', $listing['offers']['priceCurrency'] );
		$this->assertGreaterThan( 0, $listing['offers']['price'] );

		$ids = array_filter( array_map( static fn ( $n ) => $n['@id'] ?? null, $nodes ) );
		$this->assertSame( count( $ids ), count( array_unique( $ids ) ), 'keine doppelten @id' );
		foreach ( [ 'about', 'offeredBy' ] as $ref ) {
			$this->assertContains( $listing[ $ref ]['@id'], $ids, "@id-Referenz {$ref} ist aufgelöst" );
		}
		$place = self::nodesOfType( $nodes, 'Apartment' );
		$this->assertCount( 1, $place );
		$this->assertSame( [ '@type' => 'PostalAddress', 'postalCode' => '10437', 'addressLocality' => 'Berlin' ], $place[0]['address'] );
		$agent = self::nodesOfType( $nodes, 'RealEstateAgent' );
		$this->assertCount( 1, $agent );
		$this->assertSame( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ), $agent[0]['name'] );
		$this->assertCount( 1, self::nodesOfType( $nodes, 'BreadcrumbList' ), 'genau eine BreadcrumbList' );
		foreach ( self::nodesOfType( $nodes, 'BreadcrumbList' )[0]['itemListElement'] as $item ) {
			$url = is_array( $item['item'] ?? null ) ? ( $item['item']['@id'] ?? null ) : ( $item['item'] ?? null );
			$this->assertNotEmpty( $url, 'BreadcrumbList: jeder Eintrag braucht eine URL (Google Rich Results)' );
		}
		$json = (string) json_encode( $nodes );
		$this->assertDoesNotMatchRegularExpression( '/"price":\s*"?0(\.0+)?"?[,}]/', $json, 'nie price 0' );
	}

	protected function assertReservedIndexable(): void {
		$r    = $this->get( $this->url( self::ID_RESERVED ) );
		$head = self::head( $r['body'] );
		$this->assertSame( 200, $r['status'] );
		$this->assertStringNotContainsString( 'noindex', $head );
		$this->assertSame( 1, self::tagCounts( $head )['canonical'] );
	}

	/** @param bool $canonicalRequired Core: Canonical auf sich selbst; SEO-Plugins dürfen ihn bei noindex weglassen. */
	protected function assertSoldNoindex( bool $canonicalRequired = true ): void {
		$canonical = $this->url( self::ID_SOLD );
		$r         = $this->get( $canonical );
		$head      = self::head( $r['body'] );
		$this->assertSame( 200, $r['status'] );
		$this->assertSame( 'noindex, follow', $r['headers']['x-robots-tag'] ?? null );
		$this->assertSame( 1, self::tagCounts( $head )['robots'] );
		$this->assertMatchesRegularExpression( '#<meta\s+name=["\']robots["\']\s+content=["\'][^"\']*noindex[^"\']*\bfollow#', $head );
		$found = self::canonical( $head );
		if ( $canonicalRequired || null !== $found ) {
			$this->assertSame( $canonical, $found, 'Verkauft-Phase: Canonical (falls vorhanden) auf sich selbst' );
		}
		$this->assertLessThanOrEqual( 1, self::tagCounts( $head )['canonical'] );
	}

	protected function assertGoneHead(): void {
		$r    = $this->get( $this->url( self::ID_REMOVED ) );
		$head = self::head( $r['body'] );
		$this->assertSame( 410, $r['status'] );
		$this->assertArrayNotHasKey( 'location', $r['headers'], '410 ohne Weiterleitung' );
		$this->assertSame( 0, self::tagCounts( $head )['canonical'], '410 ohne Canonical' );
		$this->assertMatchesRegularExpression( '#<meta\s+name=["\']robots["\']\s+content=["\'][^"\']*noindex#', $head );
		$this->assertSame( [], self::nodesOfType( self::schemaNodes( $head ), 'RealEstateListing' ) );
		$this->assertStringNotContainsString( 'og:url', $head );
	}

	protected function assert404Normal(): void {
		$r    = $this->get( $this->base . '/immobilien/x-' . self::ID_UNKNOWN . '/' );
		$head = self::head( $r['body'] );
		$this->assertSame( 404, $r['status'] );
		$this->assertSame( 0, self::tagCounts( $head )['canonical'], '404 ohne Canonical' );
		$this->assertStringNotContainsString( 'RealEstateListing', $head );
		$this->assertStringNotContainsString( 'Propstack Listings Lite SEO', $head );
	}

	protected function assertCanonicalIgnoresCampaignParams(): void {
		$canonical = $this->url( self::ID_ACTIVE );
		$r         = $this->get( $canonical . '?utm_source=google&utm_campaign=x&gclid=abc123&fbclid=z' );
		$head      = self::head( $r['body'] );
		$this->assertSame( 200, $r['status'] );
		$this->assertSame( $canonical, self::canonical( $head ) );
		$this->assertSame( $canonical, self::meta( $head, 'property', 'og:url' ) );
		$this->assertStringNotContainsString( 'gclid', $head );
		$this->assertStringNotContainsString( 'utm_', $head );
	}

	protected function assertLegacyStill301(): void {
		$r = $this->get( $this->base . '/immobilie/alt-' . self::ID_ACTIVE . '/' );
		$this->assertSame( 301, $r['status'] );
		$this->assertSame( $this->url( self::ID_ACTIVE ), $r['headers']['location'] ?? null );
	}

	protected function assertPriceOnRequestHasNoOffer(): void {
		$head  = self::head( $this->get( $this->url( self::ID_ON_REQUEST ) )['body'] );
		$nodes = self::schemaNodes( $head );
		$this->assertArrayNotHasKey( 'offers', self::nodesOfType( $nodes, 'RealEstateListing' )[0] );
		$this->assertStringNotContainsString( 'og:image"', str_replace( "'", '"', $head ), 'ohne Bild kein og:image' );
		$this->assertDoesNotMatchRegularExpression( '#property=["\']og:image["\']#', $head );
	}

	protected function assertHeadXssSafe(): void {
		$r    = $this->get( $this->url( self::ID_XSS ) );
		$head = self::head( $r['body'] );
		$this->assertSame( 200, $r['status'] );
		foreach ( [ '<script>alert(', 'onerror="alert', '"><script', '"onerror=' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $r['body'], "XSS im Dokument: {$needle}" );
		}
		// JSON-LD-Blöcke enden nicht vorzeitig und sind gültiges JSON.
		preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#s', $head, $m );
		foreach ( $m[1] as $json ) {
			$this->assertIsArray( json_decode( $json, true ) );
			$this->assertStringNotContainsString( '<', $json, 'kein rohes < im JSON-LD' );
		}
	}

	protected function assertNoPropstackRequests( array $extraUrls = [] ): void {
		if ( ! file_exists( WP_CONTENT_DIR . '/mu-plugins/psl-http-spy.php' ) ) {
			$this->markTestSkipped( 'mu-plugin psl-http-spy.php nicht installiert.' );
		}
		$log = WP_CONTENT_DIR . '/psl-http-spy.log';
		file_put_contents( $log, '' );
		$urls = array_merge( [ $this->url( self::ID_ACTIVE ), $this->url( self::ID_SOLD ), $this->url( self::ID_REMOVED ), $this->url( self::ID_XSS ) ], $extraUrls );
		foreach ( $urls as $url ) {
			$this->get( $url );
		}
		$lines = array_filter( explode( "\n", (string) file_get_contents( $log ) ) );
		$hits  = array_filter( $lines, static fn ( $l ) => str_contains( $l, '[web' ) && str_contains( $l, 'propstack' ) );
		$this->assertSame( [], array_values( $hits ), 'Besucher-Requests dürfen Propstack nicht abfragen' );
	}

	/* --------------------------------------------------------------- Sitemaps */

	/** @return list<array{loc: string, lastmod: ?string}> */
	protected function sitemapUrls( string $url ): array {
		$r = $this->get( $url );
		$this->assertSame( 200, $r['status'], "Sitemap {$url}" );
		$this->assertStringContainsString( 'xml', strtolower( $r['headers']['content-type'] ?? '' ) );
		$xml = simplexml_load_string( $r['body'] );
		$this->assertNotFalse( $xml, 'gültiges XML' );
		$out = [];
		foreach ( $xml->children() as $entry ) {
			$out[] = [ 'loc' => (string) $entry->loc, 'lastmod' => isset( $entry->lastmod ) ? (string) $entry->lastmod : null ];
		}
		return $out;
	}

	/** Inhalt einer Propstack-Sitemap: nur aktive/reservierte öffentliche Objekte, URL = Canonical. */
	protected function assertSitemapContent( array $entries ): void {
		$locs = array_column( $entries, 'loc' );
		sort( $locs );
		$expected = [ $this->url( self::ID_ACTIVE ), $this->url( self::ID_RESERVED ), $this->url( self::ID_ON_REQUEST ), $this->url( self::ID_XSS ) ];
		sort( $expected );
		$this->assertSame( $expected, $locs );
		foreach ( $locs as $loc ) {
			$this->assertStringStartsWith( $this->base . '/immobilien/', $loc, 'Unterverzeichnis /Picaflor/' );
			$this->assertStringNotContainsString( '?', $loc );
		}
		foreach ( $entries as $entry ) {
			$this->assertNotEmpty( $entry['lastmod'] );
			$this->assertNotFalse( strtotime( (string) $entry['lastmod'] ) );
		}
	}
}
