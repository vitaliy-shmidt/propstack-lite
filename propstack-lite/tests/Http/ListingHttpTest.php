<?php

namespace PropstackLite\Tests\Http;

use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Tests\Integration\IntegrationTestCase;

/**
 * Phase 7: Immobilienübersicht über echte HTTP-Requests – Filter, Pagination, Sortierung, Leerzustand,
 * SEO (Canonical/Robots/Title) und Security. Läuft in jedem SEO-Modus (Core, Yoast, Rank Math, Konflikt);
 * modusabhängig ist nur das Format des Robots-Tags.
 *
 * Nutzt die WordPress-Seite `/immobilien/` der Testinstanz (Inhalt wird für den Test gesetzt und danach
 * wiederhergestellt) und 16 synthetische Objekte.
 */
final class ListingHttpTest extends IntegrationTestCase {

	private const PUBLIC   = 721;
	private const RESERVED = 722;
	private const OTHER    = 729;
	private const ID_BASE  = 990000700;

	private string $base;
	private string $list;
	private int $pageId     = 0;
	private bool $created   = false;
	private ?string $backup = null;
	private string $mode;

	protected function setUp(): void {
		$base = getenv( 'PSL_TEST_BASE_URL' );
		if ( ! is_string( $base ) || '' === $base ) {
			$this->markTestSkipped( 'PSL_TEST_BASE_URL nicht gesetzt (laufender Testserver erforderlich).' );
		}
		parent::setUp();
		$this->base = rtrim( $base, '/' );
		$this->assertSame( $this->base, rtrim( home_url(), '/' ), 'Testserver und PSL_WP_LOAD müssen dieselbe Instanz sein' );

		$active     = (array) get_option( 'active_plugins', [] );
		$yoast      = in_array( 'wordpress-seo/wp-seo.php', $active, true );
		$rm         = in_array( 'seo-by-rank-math/rank-math.php', $active, true );
		$this->mode = $yoast && $rm ? 'conflict' : ( $yoast ? 'yoast' : ( $rm ? 'rankmath' : 'core' ) );

		$page = get_page_by_path( 'immobilien' );
		if ( $page instanceof \WP_Post ) {
			$this->pageId = $page->ID;
			$this->backup = $page->post_content;
			wp_update_post( [ 'ID' => $page->ID, 'post_content' => '[propstack_list heading="h2"]' ] );
		} else {
			$this->pageId  = (int) wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Immobilien', 'post_name' => 'immobilien', 'post_content' => '[propstack_list heading="h2"]' ] );
			$this->created = true;
		}
		$this->list = (string) get_permalink( $this->pageId );
		$this->seed();
	}

	protected function tearDown(): void {
		if ( $this->created ) {
			wp_delete_post( $this->pageId, true );
		} elseif ( null !== $this->backup ) {
			wp_update_post( [ 'ID' => $this->pageId, 'post_content' => $this->backup ] );
		}
		parent::tearDown();
	}

	private function seed(): void {
		$this->settings( [ 'public_status_ids' => [ self::PUBLIC, self::RESERVED ], 'reserved_status_ids' => [ self::RESERVED ] ] );
		$m   = new PropertyMapper();
		$now = gmdate( 'Y-m-d H:i:s' );
		$num = static fn ( $v ) => [ 'label' => 'x', 'value' => $v ];
		// 14 öffentliche Objekte: 8 Kauf (Berlin/Potsdam), 6 Miete (Berlin/Hamburg); dazu ein nicht öffentliches.
		for ( $i = 1; $i <= 14; $i++ ) {
			$rent = $i > 8;
			$this->store->upsertActive(
				$m->map(
					self::raw(
						self::ID_BASE + $i,
						5 === $i ? self::RESERVED : self::PUBLIC,
						[
							'title'           => [ 'label' => 'T', 'value' => sprintf( 'Listenobjekt %02d', $i ) ],
							'marketing_type'  => $rent ? 'RENT' : 'BUY',
							'rs_type'         => 0 === $i % 4 ? 'HOUSE' : 'APARTMENT',
							'city'            => $rent ? ( $i % 2 ? 'Hamburg' : 'Berlin' ) : ( $i % 2 ? 'Berlin' : 'Potsdam' ),
							'price'           => $rent ? null : $num( 150000.0 + $i * 50000 ),
							'base_rent'       => $rent ? $num( 500.0 + $i * 100 ) : null,
							'living_space'    => $num( 40 + $i * 8 ),
							'number_of_rooms' => $num( 1 + ( $i % 5 ) ),
							'created_at'      => gmdate( 'c', strtotime( '2026-01-01' ) + $i * 86400 ),
						]
					)
				),
				$now
			);
		}
		$this->store->upsertActive( $m->map( self::raw( self::ID_BASE + 99, self::OTHER, [ 'title' => [ 'label' => 'T', 'value' => 'NICHT-OEFFENTLICH-LISTE' ] ] ) ), $now );
	}

	/* ---------------------------------------------------------------- Helfer */

	/** @return array{status: int, headers: array<string, string>, body: string} */
	private function get( string $url ): array {
		$ch = curl_init( $url );
		curl_setopt_array( $ch, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30 ] );
		$raw     = (string) curl_exec( $ch );
		$hsize   = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$headers = [];
		foreach ( explode( "\r\n", substr( $raw, 0, $hsize ) ) as $line ) {
			if ( str_contains( $line, ':' ) ) {
				[ $k, $v ]                          = explode( ':', $line, 2 );
				$headers[ strtolower( trim( $k ) ) ] = trim( $v );
			}
		}
		return [ 'status' => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ), 'headers' => $headers, 'body' => substr( $raw, $hsize ) ];
	}

	private function page( string $query = '' ): array {
		$r = $this->get( $this->list . $query );
		$this->assertSame( 200, $r['status'], "Status für {$query}" );
		$this->assertStringNotContainsString( 'Fatal error', $r['body'] );
		$this->assertStringNotContainsString( 'Warning:', $r['body'] );
		return $r;
	}

	private static function head( string $html ): string {
		return preg_match( '#<head[^>]*>(.*?)</head>#s', $html, $m ) ? $m[1] : '';
	}

	/** @return list<string> */
	private static function canonicals( string $html ): array {
		preg_match_all( '#<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']+)["\']#i', self::head( $html ), $m );
		return array_map( static fn ( $u ) => html_entity_decode( $u, ENT_QUOTES | ENT_HTML5 ), $m[1] );
	}

	/** @return list<list<string>> Direktiven je Robots-Tag */
	private static function robots( string $html ): array {
		preg_match_all( '#<meta\s+name=["\']robots["\']\s+content=["\']([^"\']*)["\']#i', self::head( $html ), $m );
		return array_map( static fn ( $c ) => array_map( 'trim', explode( ',', strtolower( $c ) ) ), $m[1] );
	}

	private static function resultCount( string $html ): string {
		return preg_match( '#class="psl-results__count"[^>]*>([^<]+)<#', $html, $m ) ? html_entity_decode( $m[1] ) : '';
	}

	/** @return list<string> Titel der Karten */
	private static function titles( string $html ): array {
		preg_match_all( '#class="psl-card__title">([^<]+)<#', $html, $m );
		return $m[1];
	}

	private function assertIndexable( array $r, string $canonical ): void {
		$canonicals = self::canonicals( $r['body'] );
		if ( 'conflict' === $this->mode ) {
			$this->assertContains( $canonical, $canonicals );
		} else {
			$this->assertSame( [ $canonical ], $canonicals, 'genau ein selbstreferenzierender Canonical' );
		}
		foreach ( self::robots( $r['body'] ) as $directives ) {
			$this->assertNotContains( 'noindex', $directives );
		}
		$this->assertArrayNotHasKey( 'x-robots-tag', $r['headers'] );
	}

	private function assertNoindexFollow( array $r, string $canonical ): void {
		$robots = self::robots( $r['body'] );
		$this->assertNotEmpty( $robots, 'Robots-Tag vorhanden' );
		foreach ( $robots as $directives ) {
			$this->assertContains( 'noindex', $directives );
			$this->assertNotContains( 'nofollow', $directives );
		}
		$this->assertSame( 'noindex, follow', $r['headers']['x-robots-tag'] ?? null );
		$canonicals = self::canonicals( $r['body'] );
		if ( in_array( $this->mode, [ 'yoast', 'conflict' ], true ) ) {
			// Yoast gibt auf noindex-Seiten grundsätzlich keinen Canonical aus; falls doch, dann selbstreferenzierend.
			$this->assertSame( [], array_values( array_diff( $canonicals, [ $canonical ] ) ), 'kein fremder Canonical' );
		} else {
			$this->assertSame( [ $canonical ], $canonicals, 'Self-Canonical der normalisierten URL' );
		}
	}

	/* ----------------------------------------------------------------- Tests */

	public function test_overview_unfiltered_is_indexable_with_pagination(): void {
		$r = $this->page();
		$this->assertIndexable( $r, $this->list );
		$this->assertSame( '14 Immobilien gefunden', self::resultCount( $r['body'] ) );
		$this->assertCount( 12, self::titles( $r['body'] ), 'Seitengröße 12' );
		$this->assertStringContainsString( 'Seite 1 von 2', $r['body'] );
		$this->assertStringContainsString( '<h2 class="psl-card__title">', $r['body'], 'heading-Attribut' );
		$this->assertStringContainsString( 'href="' . esc_url( $this->list . '?seite=2' ) . '" rel="next"', $r['body'] );
		$this->assertStringNotContainsString( 'NICHT-OEFFENTLICH-LISTE', $r['body'] );
		$this->assertStringContainsString( 'Reserviert', $r['body'] );
		$this->assertStringContainsString( 'psl-list.css', $r['body'] );
		$this->assertStringContainsString( 'psl-list.js', $r['body'] );
	}

	public function test_page_two_self_canonical_and_title(): void {
		$r = $this->page( '?seite=2' );
		$this->assertIndexable( $r, $this->list . '?seite=2' );
		$this->assertCount( 2, self::titles( $r['body'] ) );
		$this->assertSame( [ 'Listenobjekt 02', 'Listenobjekt 01' ], self::titles( $r['body'] ), 'Neueste zuerst, Rest auf Seite 2' );
		$this->assertMatchesRegularExpression( '#<title[^>]*>[^<]*Seite 2#', $r['body'], 'eindeutiger Title je Seite' );
		$this->assertStringContainsString( 'aria-current="page"', $r['body'] );
	}

	public function test_filters(): void {
		$cases = [
			'?marketing_type=buy'                                           => 8,
			'?marketing_type=rent'                                          => 6,
			'?property_type=apartment'                                      => 11,
			'?property_type=house'                                          => 3,
			'?city=Berlin'                                                  => 7,
			'?price_max=500000'                                             => 13, // Kauf ≤ 500.000 € und alle Kaltmieten
			'?living_space_min=80'                                          => 10,
			'?rooms_min=3'                                                  => 9,
			'?marketing_type=buy&city=Berlin&price_max=500000'              => 4,
			'?marketing_type=rent&property_type=apartment&living_space_min=100' => 5,
		];
		foreach ( $cases as $query => $expected ) {
			$r = $this->page( $query );
			$this->assertSame( $expected . ' Immobilien entsprechen Ihren Filtern.', self::resultCount( $r['body'] ), $query );
			$this->assertCount( min( 12, $expected ), self::titles( $r['body'] ), $query );
			$this->assertNoindexFollow( $r, $this->list . $query );
			$this->assertStringContainsString( 'Filter zurücksetzen', $r['body'] );
		}
	}

	public function test_filter_pagination_keeps_filters(): void {
		$r = $this->page( '?living_space_min=50&seite=2' );
		$this->assertCount( 1, self::titles( $r['body'] ), '13 Treffer → Seite 2 mit 1 Objekt' );
		$this->assertNoindexFollow( $r, $this->list . '?living_space_min=50&seite=2' );
		$this->assertStringContainsString( 'href="' . esc_url( $this->list . '?living_space_min=50' ) . '" rel="prev"', $r['body'] );
		$this->assertMatchesRegularExpression( '#name="living_space_min" value="50"#', $r['body'], 'Formular zeigt Zustand' );
	}

	public function test_sorting(): void {
		$asc = self::titles( $this->page( '?marketing_type=buy&sort=price_asc' )['body'] );
		$this->assertSame( 'Listenobjekt 01', $asc[0] );
		$this->assertSame( 'Listenobjekt 08', end( $asc ) );
		$this->assertSame( 'Listenobjekt 08', self::titles( $this->page( '?marketing_type=buy&sort=price_desc' )['body'] )[0] );
		$this->assertSame( 'Listenobjekt 14', self::titles( $this->page( '?sort=area_desc' )['body'] )[0] );
		$this->assertSame( 'Listenobjekt 01', self::titles( $this->page( '?sort=area_asc' )['body'] )[0] );

		$r = $this->page( '?sort=price_asc' );
		$this->assertNoindexFollow( $r, $this->list . '?sort=price_asc' );
	}

	public function test_empty_result_and_out_of_range_page(): void {
		$r = $this->page( '?city=Nirgendwo' );
		$this->assertStringContainsString( 'Für diese Filter wurden keine Immobilien gefunden.', $r['body'] );
		$this->assertStringContainsString( 'href="' . esc_url( $this->list ) . '">Filter zurücksetzen', $r['body'] );
		$this->assertNoindexFollow( $r, $this->list . '?city=Nirgendwo' );

		$far = $this->page( '?seite=50' );
		$this->assertStringContainsString( 'Diese Ergebnisseite ist nicht (mehr) vorhanden.', $far['body'] );
		$this->assertNoindexFollow( $far, $this->list . '?seite=50' );
	}

	public function test_tracking_parameters_do_not_change_seo_or_results(): void {
		$r = $this->page( '?utm_source=google&utm_medium=cpc&utm_campaign=herbst&gclid=Cj0TEST' );
		$this->assertIndexable( $r, $this->list );
		$this->assertSame( '14 Immobilien gefunden', self::resultCount( $r['body'] ) );
		$this->assertStringNotContainsString( 'Cj0TEST', $r['body'] );

		$f = $this->page( '?city=Berlin&utm_source=newsletter&fbclid=IwTEST' );
		$this->assertNoindexFollow( $f, $this->list . '?city=Berlin' );
		$this->assertSame( '7 Immobilien entsprechen Ihren Filtern.', self::resultCount( $f['body'] ) );

		$p = $this->page( '?seite=2&utm_source=google' );
		$this->assertIndexable( $p, $this->list . '?seite=2' );
	}

	public function test_hostile_and_invalid_parameters(): void {
		$queries = [
			'?city=' . rawurlencode( "Berlin' OR '1'='1" ),
			'?city=' . rawurlencode( '"><script>alert(1)</script>' ),
			'?sort=' . rawurlencode( 'price_asc; DROP TABLE wp_psl_properties' ),
			'?sort=data',
			'?price_min=-100&price_max=-1',
			'?price_max=99999999999999999999999',
			'?living_space_min=' . str_repeat( '9', 50 ),
			'?city[]=Berlin&marketing_type[]=buy',
			'?seite[]=1',
			'?per=100000',
			'?per[]=48',
			'?status=' . self::OTHER . '&state=removed&foo=bar',
			'?rooms_min=' . rawurlencode( '<svg onload=alert(2)>' ),
		];
		foreach ( $queries as $query ) {
			$r = $this->page( $query );
			$this->assertSame( '14 Immobilien gefunden', self::resultCount( $r['body'] ), "ungültige Werte ignoriert: {$query}" );
			$this->assertCount( 12, self::titles( $r['body'] ), $query );
			$this->assertStringNotContainsString( '<script>alert(1)', $r['body'] );
			$this->assertStringNotContainsString( 'onload=alert', $r['body'] );
			$this->assertStringNotContainsString( 'NICHT-OEFFENTLICH-LISTE', $r['body'] );
			$this->assertIndexable( $r, $this->list );
		}
		global $wpdb;
		$this->assertSame( '15', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}psl_properties" ), 'Tabelle unverändert' );
	}

	public function test_no_php_warnings_in_debug_log(): void {
		$log  = WP_CONTENT_DIR . '/debug.log';
		$size = file_exists( $log ) ? filesize( $log ) : 0;
		foreach ( [ '', '?city[]=x', '?seite[]=1&per[]=2&sort[]=x', '?price_min=abc&rooms_min=9', '?marketing_type=buy&seite=2' ] as $q ) {
			$this->page( $q );
		}
		clearstatcache();
		$new = file_exists( $log ) ? (string) file_get_contents( $log, false, null, $size ) : '';
		$this->assertDoesNotMatchRegularExpression( '/PHP (Warning|Notice|Deprecated|Fatal)/', $new );
	}

	public function test_no_propstack_requests(): void {
		if ( ! file_exists( WP_CONTENT_DIR . '/mu-plugins/psl-http-spy.php' ) ) {
			$this->markTestSkipped( 'mu-plugin psl-http-spy.php nicht installiert.' );
		}
		$log = WP_CONTENT_DIR . '/psl-http-spy.log';
		file_put_contents( $log, '' );
		foreach ( [ '', '?marketing_type=rent', '?city=Berlin&seite=1', '?seite=2', '?sort=price_desc', '?city=Nirgendwo' ] as $q ) {
			$this->page( $q );
		}
		$hits = array_filter( explode( "\n", (string) file_get_contents( $log ) ), static fn ( $l ) => str_contains( $l, '[web' ) && str_contains( $l, 'propstack' ) );
		$this->assertSame( [], array_values( $hits ), 'Übersicht, Filter, Pagination, Sortierung: 0 Propstack-Requests' );
	}
}
