<?php

namespace PropstackLite\Tests\Integration;

use PropstackLite\Frontend\ListingConfig;
use PropstackLite\Frontend\ListingRequest;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Storage\PropertySearchCriteria;
use PropstackLite\Storage\Schema;

/**
 * Phase 7: Suche, Filter, Sortierung, Pagination und Filteroptionen gegen die echte Tabelle
 * (synthetische Objekte, keine echten Daten) sowie Shortcode-Ausgabe mit GET-Parametern.
 */
final class ListingSearchIntegrationTest extends IntegrationTestCase {

	private const PUBLIC   = 611;
	private const RESERVED = 612;
	private const SOLD     = 613;
	private const OTHER    = 619;

	// Synthetische IDs
	private const BUY_APT_BERLIN   = 101; // 300.000 €, 80 m², 3 Zi.
	private const BUY_HOUSE        = 102; // Potsdam, 600.000 €, 150 m², 5 Zi., Grundstück 600 m²
	private const RENT_APT_BERLIN  = 103; // Kaltmiete 900 €, 60 m², 2 Zi.
	private const RENT_APT_HAMBURG = 104; // Kaltmiete 1.500 €, 95 m², 3,5 Zi.
	private const BUY_ON_REQUEST   = 105; // Berlin, Preis auf Anfrage (intern 200.000 €), 2,5 Zi.
	private const BUY_RESERVED     = 106; // Berlin, 250.000 €, 70 m², 3 Zi., reserviert
	private const NOT_PUBLIC       = 107; // Status nicht öffentlich
	private const SOLD_ID          = 108; // verkauft (Verkauft-Phase)
	private const REMOVED_ID       = 109; // entfernt
	private const OFFICE           = 110; // Leipzig, Gewerbe, 1.200.000 €
	private const RENT_TOTAL_ONLY  = 111; // Hamburg, nur Warmmiete 1.000 €
	private const HOLIDAY          = 112; // Ferienwohnung (keine Filtergruppe), Berlin

	private array $getBackup = [];

	protected function setUp(): void {
		parent::setUp();
		$this->getBackup = $_GET;
		$_GET            = [];
		$this->settings( [ 'public_status_ids' => [ self::PUBLIC, self::RESERVED ], 'reserved_status_ids' => [ self::RESERVED ], 'sold_status_ids' => [ self::SOLD ] ] );

		$m   = new PropertyMapper();
		$now = '2026-10-05 12:00:00';
		$add = function ( int $id, int $status, array $o ) use ( $m, $now ): void {
			$this->store->upsertActive( $m->map( self::raw( $id, $status, $o ) ), $now );
		};
		$num = static fn ( $v ) => [ 'label' => 'x', 'value' => $v ];

		$add( self::BUY_APT_BERLIN, self::PUBLIC, [ 'price' => $num( 300000.0 ), 'living_space' => $num( 80 ), 'number_of_rooms' => $num( 3 ), 'created_at' => '2026-05-01T00:00:00Z' ] );
		$add( self::BUY_HOUSE, self::PUBLIC, [ 'rs_type' => 'HOUSE', 'city' => 'Potsdam', 'zip_code' => '14467', 'price' => $num( 600000.0 ), 'living_space' => $num( 150 ), 'plot_area' => $num( 600 ), 'number_of_rooms' => $num( 5 ), 'created_at' => '2026-04-01T00:00:00Z' ] );
		$add( self::RENT_APT_BERLIN, self::PUBLIC, [ 'marketing_type' => 'RENT', 'price' => null, 'base_rent' => $num( 900.0 ), 'total_rent' => $num( 1150.0 ), 'living_space' => $num( 60 ), 'number_of_rooms' => $num( 2 ), 'created_at' => '2026-06-01T00:00:00Z' ] );
		$add( self::RENT_APT_HAMBURG, self::PUBLIC, [ 'marketing_type' => 'RENT', 'city' => 'Hamburg', 'zip_code' => '20095', 'price' => null, 'base_rent' => $num( 1500.0 ), 'living_space' => $num( 95 ), 'number_of_rooms' => $num( 3.5 ), 'created_at' => '2026-03-01T00:00:00Z' ] );
		$add( self::BUY_ON_REQUEST, self::PUBLIC, [ 'price' => $num( 200000.0 ), 'price_on_inquiry' => $num( true ), 'living_space' => $num( 55 ), 'number_of_rooms' => $num( 2.5 ), 'created_at' => '2026-02-01T00:00:00Z' ] );
		$add( self::BUY_RESERVED, self::RESERVED, [ 'price' => $num( 250000.0 ), 'living_space' => $num( 70 ), 'number_of_rooms' => $num( 3 ), 'created_at' => '2026-01-15T00:00:00Z' ] );
		$add( self::NOT_PUBLIC, self::OTHER, [ 'title' => [ 'label' => 'T', 'value' => 'NICHT-OEFFENTLICH' ], 'price' => $num( 100000.0 ) ] );
		$add( self::SOLD_ID, self::PUBLIC, [ 'title' => [ 'label' => 'T', 'value' => 'VERKAUFT-OBJEKT' ], 'price' => $num( 100000.0 ) ] );
		$this->store->markSold( self::SOLD_ID, self::SOLD, $now );
		$add( self::REMOVED_ID, self::PUBLIC, [ 'title' => [ 'label' => 'T', 'value' => 'ENTFERNT-OBJEKT' ], 'price' => $num( 100000.0 ) ] );
		$this->store->markRemoved( self::REMOVED_ID, self::OTHER, $now );
		$add( self::OFFICE, self::PUBLIC, [ 'rs_type' => 'OFFICE', 'city' => 'Leipzig', 'zip_code' => '04109', 'price' => $num( 1200000.0 ), 'living_space' => null, 'property_space_value' => $num( 400 ), 'number_of_rooms' => null, 'created_at' => '2026-07-01T00:00:00Z' ] );
		$add( self::RENT_TOTAL_ONLY, self::PUBLIC, [ 'marketing_type' => 'RENT', 'city' => 'Hamburg', 'zip_code' => '20095', 'price' => null, 'total_rent' => $num( 1000.0 ), 'living_space' => $num( 45 ), 'number_of_rooms' => $num( 1 ), 'created_at' => '2026-02-15T00:00:00Z' ] );
		$add( self::HOLIDAY, self::PUBLIC, [ 'rs_type' => 'SHORT_TERM_ACCOMODATION', 'marketing_type' => 'RENT', 'price' => null, 'base_rent' => $num( 700.0 ), 'living_space' => $num( 40 ), 'number_of_rooms' => $num( 1 ), 'created_at' => '2026-01-01T00:00:00Z' ] );
	}

	protected function tearDown(): void {
		$_GET = $this->getBackup;
		parent::tearDown();
	}

	/** @return list<int> */
	private function ids( array $query, array $atts = [] ): array {
		$request = ListingRequest::fromQuery( $query, ListingConfig::fromAtts( $atts + [ 'per' => '50' ] ) );
		$result  = $this->store->search( [ self::PUBLIC, self::RESERVED ], $request->criteria() );
		$ids     = array_map( static fn ( $p ) => $p->id, $result->items );
		$this->assertSame( count( $ids ), $result->total, 'COUNT entspricht der Trefferliste' );
		return $ids;
	}

	private static function sorted( array $ids ): array {
		sort( $ids );
		return $ids;
	}

	public function test_only_public_active_objects(): void {
		$all = $this->ids( [] );
		$this->assertSame( [ self::OFFICE, self::RENT_APT_BERLIN, self::BUY_APT_BERLIN, self::BUY_HOUSE, self::RENT_APT_HAMBURG, self::RENT_TOTAL_ONLY, self::BUY_ON_REQUEST, self::BUY_RESERVED, self::HOLIDAY ], $all, 'Neueste zuerst (remote_created_at)' );
		foreach ( [ self::NOT_PUBLIC, self::SOLD_ID, self::REMOVED_ID ] as $hidden ) {
			$this->assertNotContains( $hidden, $all );
		}
		$this->assertSame( 0, $this->store->search( [], new PropertySearchCriteria() )->total, 'ohne öffentliche Status nichts' );
	}

	public function test_marketing_type(): void {
		$this->assertSame( self::sorted( [ self::BUY_APT_BERLIN, self::BUY_HOUSE, self::BUY_ON_REQUEST, self::BUY_RESERVED, self::OFFICE ] ), self::sorted( $this->ids( [ 'marketing_type' => 'buy' ] ) ) );
		$this->assertSame( self::sorted( [ self::RENT_APT_BERLIN, self::RENT_APT_HAMBURG, self::RENT_TOTAL_ONLY, self::HOLIDAY ] ), self::sorted( $this->ids( [ 'marketing_type' => 'rent' ] ) ) );
	}

	public function test_property_type_groups(): void {
		$this->assertSame( [ self::BUY_HOUSE ], $this->ids( [ 'property_type' => 'house' ] ) );
		$this->assertSame( [ self::OFFICE ], $this->ids( [ 'property_type' => 'commercial' ] ) );
		$apartments = $this->ids( [ 'property_type' => 'apartment' ] );
		$this->assertNotContains( self::HOLIDAY, $apartments, 'Ferienwohnung ist keine Wohnung-Gruppe' );
		$this->assertCount( 6, $apartments );
	}

	public function test_city_case_insensitive(): void {
		$this->assertSame( self::sorted( [ self::RENT_APT_HAMBURG, self::RENT_TOTAL_ONLY ] ), self::sorted( $this->ids( [ 'city' => 'hamburg' ] ) ) );
		$this->assertSame( [], $this->ids( [ 'city' => 'Nirgendwo' ] ) );
	}

	public function test_price_filters_buy_rent_and_on_request(): void {
		$this->assertSame( self::sorted( [ self::BUY_APT_BERLIN, self::BUY_RESERVED ] ), self::sorted( $this->ids( [ 'marketing_type' => 'buy', 'price_max' => '500000' ] ) ), 'Preis auf Anfrage (intern 200.000) nicht erratbar' );
		$this->assertSame( self::sorted( [ self::BUY_HOUSE, self::OFFICE ] ), self::sorted( $this->ids( [ 'marketing_type' => 'buy', 'price_min' => '500000' ] ) ) );
		$this->assertSame( self::sorted( [ self::RENT_APT_BERLIN, self::HOLIDAY ] ), self::sorted( $this->ids( [ 'marketing_type' => 'rent', 'price_max' => '1000' ] ) ), 'Miete = Kaltmiete; nur Warmmiete fällt heraus' );
		$this->assertContains( self::BUY_ON_REQUEST, $this->ids( [ 'price_min' => '0' ] ), 'price_min=0 schränkt nicht ein' );
	}

	public function test_living_space_rooms_and_plot_area(): void {
		$this->assertSame( self::sorted( [ self::BUY_APT_BERLIN, self::BUY_HOUSE, self::RENT_APT_HAMBURG, self::OFFICE ] ), self::sorted( $this->ids( [ 'living_space_min' => '80' ] ) ), 'Hauptfläche, numerisch (nicht „150“ < „80“)' );
		$this->assertSame( self::sorted( [ self::BUY_APT_BERLIN, self::BUY_HOUSE, self::RENT_APT_HAMBURG, self::BUY_RESERVED ] ), self::sorted( $this->ids( [ 'rooms_min' => '3' ] ) ), '3,5 Zimmer zählt zu 3+' );
		$this->assertContains( self::BUY_ON_REQUEST, $this->ids( [ 'rooms_min' => '2' ] ), '2,5 Zimmer zählt zu 2+' );
		$this->assertSame( [ self::BUY_HOUSE ], $this->ids( [ 'plot_area_min' => '500' ] ) );
	}

	public function test_combination(): void {
		$this->assertSame( [ self::BUY_APT_BERLIN, self::BUY_RESERVED ], $this->ids( [ 'marketing_type' => 'buy', 'property_type' => 'apartment', 'city' => 'Berlin', 'price_max' => '500000', 'rooms_min' => '3' ] ) );
	}

	public function test_sorting_modes(): void {
		$this->assertSame( [ self::BUY_RESERVED, self::BUY_APT_BERLIN, self::BUY_HOUSE, self::OFFICE, self::BUY_ON_REQUEST ], $this->ids( [ 'marketing_type' => 'buy', 'sort' => 'price_asc' ] ), 'Preis auf Anfrage am Ende' );
		$this->assertSame( [ self::OFFICE, self::BUY_HOUSE, self::BUY_APT_BERLIN, self::BUY_RESERVED, self::BUY_ON_REQUEST ], $this->ids( [ 'marketing_type' => 'buy', 'sort' => 'price_desc' ] ) );
		$this->assertSame( [ self::OFFICE, self::BUY_HOUSE, self::RENT_APT_HAMBURG, self::BUY_APT_BERLIN ], array_slice( $this->ids( [ 'sort' => 'area_desc' ] ), 0, 4 ) );
		$this->assertSame( [ self::HOLIDAY, self::RENT_TOTAL_ONLY ], array_slice( $this->ids( [ 'sort' => 'area_asc' ] ), 0, 2 ) );
		$byRooms = $this->ids( [ 'sort' => 'rooms_desc' ] );
		$this->assertSame( self::BUY_HOUSE, $byRooms[0] );
		$this->assertSame( self::OFFICE, end( $byRooms ), 'ohne Zimmerangabe am Ende' );
		$this->assertCount( 9, $this->ids( [ 'sort' => 'updated' ] ) );
	}

	public function test_tie_breaker_keeps_pages_stable(): void {
		// Gleiche Erstellzeit für alle → Reihenfolge allein über propstack_id, Seiten ohne Überschneidung.
		global $wpdb;
		$wpdb->query( "UPDATE {$wpdb->prefix}psl_properties SET remote_created_at = '2026-01-01 00:00:00'" );
		$seen = [];
		for ( $page = 1; $page <= 4; $page++ ) {
			$result = $this->store->search( [ self::PUBLIC, self::RESERVED ], new PropertySearchCriteria( page: $page, perPage: 3 ) );
			$this->assertSame( 9, $result->total );
			$seen = array_merge( $seen, array_map( static fn ( $p ) => $p->id, $result->items ) );
		}
		$this->assertSame( [ 112, 111, 110, 106, 105, 104, 103, 102, 101 ], $seen, 'jede ID genau einmal, absteigend' );
	}

	public function test_pagination_page_two_and_out_of_range(): void {
		$c      = new PropertySearchCriteria( page: 2, perPage: 4 );
		$result = $this->store->search( [ self::PUBLIC, self::RESERVED ], $c );
		$this->assertSame( 9, $result->total );
		$this->assertSame( 3, $result->pages() );
		$this->assertCount( 4, $result->items );

		$far = $this->store->search( [ self::PUBLIC, self::RESERVED ], $c->withPage( 50 ) );
		$this->assertSame( 9, $far->total );
		$this->assertSame( [], $far->items );
		$this->assertTrue( $far->isOutOfRange() );
	}

	public function test_filter_options_from_public_objects_only(): void {
		$o = $this->store->filterOptions( [ self::PUBLIC, self::RESERVED ], new PropertySearchCriteria() );

		$this->assertSame( [ 'BUY' => 5, 'RENT' => 4 ], $o['marketing'] );
		$this->assertSame( [ 'Berlin', 'Hamburg', 'Leipzig', 'Potsdam' ], array_keys( $o['cities'] ), 'nur öffentliche Orte, alphabetisch' );
		$this->assertSame( 5, $o['cities']['Berlin'] );
		$this->assertArrayHasKey( 'SHORT_TERM_ACCOMODATION', $o['rsTypes'] );
		$this->assertTrue( $o['plotArea'] );

		$rentOnly = $this->store->filterOptions( [ self::PUBLIC, self::RESERVED ], new PropertySearchCriteria( marketingType: 'RENT' ) );
		$this->assertSame( [ 'Berlin', 'Hamburg' ], array_keys( $rentOnly['cities'] ), 'feste Einschränkung der Instanz' );
		$this->assertFalse( $rentOnly['plotArea'] );
	}

	public function test_shortcode_with_filters_pagination_and_form_state(): void {
		$_GET = [ 'marketing_type' => 'buy', 'city' => 'Berlin', 'sort' => 'price_asc', 'seite' => '2', 'utm_source' => 'newsletter' ];
		$html = do_shortcode( '[propstack_list per="1"]' );

		$this->assertSame( 1, substr_count( $html, '<article' ) );
		$this->assertStringContainsString( '3 Immobilien entsprechen Ihren Filtern.', $html );
		$this->assertStringContainsString( 'Seite 2 von 3', $html );
		$this->assertStringContainsString( 'Objekt ' . self::BUY_APT_BERLIN, $html, 'Seite 2 bei Preis aufsteigend (Seite 1: reserviertes Objekt)' );
		$this->assertStringContainsString( '<form class="psl-search" method="get"', $html );
		$this->assertMatchesRegularExpression( '#<option value="buy" selected=\'selected\'>Kaufen</option>#', $html, 'Formular zeigt Zustand' );
		$this->assertMatchesRegularExpression( '#<option value="Berlin" selected=\'selected\'>Berlin</option>#', $html );
		$this->assertMatchesRegularExpression( '#<option value="price_asc" selected=\'selected\'>#', $html );
		$this->assertStringContainsString( 'Kaufpreis von', $html, 'Preisbeschriftung nach Vermarktungsart' );
		$this->assertStringContainsString( 'marketing_type=buy&#038;city=Berlin&#038;sort=price_asc', $html, 'Pagination behält Filter' );
		$this->assertStringContainsString( 'aria-current="page"', $html );
		$this->assertStringContainsString( 'rel="prev"', $html );
		$this->assertStringNotContainsString( 'newsletter', $html, 'Trackingparameter nicht in Links' );
		$this->assertStringContainsString( 'Filter zurücksetzen', $html );
		$this->assertStringContainsString( 'for="psl-list-', $html, 'echte Labels' );
	}

	public function test_shortcode_count_texts_and_empty_states(): void {
		$_GET = [];
		$this->assertStringContainsString( '9 Immobilien gefunden', do_shortcode( '[propstack_list]' ) );

		$_GET = [ 'property_type' => 'house' ];
		$this->assertStringContainsString( '1 Immobilie entspricht Ihren Filtern.', do_shortcode( '[propstack_list]' ) );

		$_GET = [ 'city' => 'Nirgendwo' ];
		$empty = do_shortcode( '[propstack_list]' );
		$this->assertStringContainsString( 'Für diese Filter wurden keine Immobilien gefunden.', $empty );
		$this->assertStringContainsString( 'Filter zurücksetzen', $empty );
		$this->assertStringContainsString( '<option value="Nirgendwo" selected', $empty, 'geteilter Link: Wert bleibt sichtbar' );

		$_GET = [ 'seite' => '99' ];
		$far = do_shortcode( '[propstack_list]' );
		$this->assertStringContainsString( 'Diese Ergebnisseite ist nicht (mehr) vorhanden.', $far );
		$this->assertStringNotContainsString( 'psl-pagination', $far );
	}

	public function test_shortcode_escapes_hostile_get_values_and_never_unlocks(): void {
		$_GET = [
			'city'           => '"><script>alert(1)</script>',
			'price_max'      => '"><svg onload=alert(2)>',
			'sort'           => "price_asc' OR 1=1 --",
			'status'         => (string) self::OTHER,
			'marketing_type' => [ 'buy' ],
			'seite'          => [ '2' ],
			'per'            => '100000',
		];
		$html = do_shortcode( '[propstack_list status="' . self::OTHER . '"]' );

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( 'onload=alert', $html );
		$this->assertStringNotContainsString( 'NICHT-OEFFENTLICH', $html );
		$this->assertStringNotContainsString( 'VERKAUFT-OBJEKT', $html );
		$this->assertStringNotContainsString( 'ENTFERNT-OBJEKT', $html );
		$this->assertStringContainsString( '9 Immobilien gefunden', $html, 'alle ungültigen Werte ignoriert' );
		$this->assertSame( 9, substr_count( $html, '<article' ), 'per=100000 ignoriert (Standard 12)' );
	}

	public function test_static_shortcode_ignores_get_and_has_no_form(): void {
		$_GET = [ 'city' => 'Hamburg', 'seite' => '2' ];
		$html = do_shortcode( '[propstack_list per="2" show_filters="0" show_sort="0" pagination="0"]' );

		$this->assertSame( 2, substr_count( $html, '<article' ) );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( 'psl-pagination', $html );
		$this->assertStringContainsString( 'Objekt ' . self::OFFICE, $html, 'Seite 1 ohne Filter' );
	}

	public function test_card_respects_hidden_address_and_image_fallback(): void {
		$m = new PropertyMapper();
		$this->store->upsertActive(
			$m->map( self::raw( 120, self::PUBLIC, [ 'hide_address' => true, 'street' => 'Geheimgasse', 'house_number' => '99', 'district' => 'Mitte', 'lat' => 52.1, 'lng' => 13.1, 'images' => [], 'created_at' => '2026-09-01T00:00:00Z' ] ) ),
			'2026-10-05 12:00:00'
		);
		$_GET = [];
		$html = do_shortcode( '[propstack_list per="1"]' );

		$this->assertStringNotContainsString( 'Geheimgasse', $html );
		$this->assertStringNotContainsString( '52.1', $html );
		$this->assertStringContainsString( '10115 Berlin (Mitte)', $html );
		$this->assertStringContainsString( 'Kein Bild verfügbar', $html, 'neutraler Fallback ohne externen Dienst' );
		$this->assertStringNotContainsString( 'placeholder.com', $html );
	}

	public function test_card_image_is_first_public_non_floorplan_image(): void {
		$_GET = [ 'property_type' => 'house' ];
		$html = do_shortcode( '[propstack_list]' );

		$this->assertStringContainsString( 'https://images.propstack.de/p/' . self::BUY_HOUSE . '-m.jpg', $html );
		$this->assertStringNotContainsString( 'PRIVATE', $html );
		$this->assertStringContainsString( 'width="600" height="450"', $html );
		$this->assertStringContainsString( 'fetchpriority="high"', $html );
	}

	public function test_listing_requests_never_call_propstack(): void {
		$calls = 0;
		$spy   = static function ( $pre, $args, $url ) use ( &$calls ) {
			++$calls;
			return $pre;
		};
		add_filter( 'pre_http_request', $spy, 10, 3 );
		foreach ( [ [], [ 'marketing_type' => 'rent' ], [ 'city' => 'Nirgendwo' ], [ 'seite' => '2', 'sort' => 'price_desc' ] ] as $query ) {
			$_GET = $query;
			do_shortcode( '[propstack_list per="2"]' );
		}
		remove_filter( 'pre_http_request', $spy, 10 );

		$this->assertSame( 0, $calls, 'keine ausgehenden HTTP-Requests' );
	}

	public function test_schema_migration_v1_to_v2_backfills_search_price(): void {
		global $wpdb;
		$table = Schema::table();
		$wpdb->query( "UPDATE {$table} SET search_price = NULL" );
		if ( ! $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'price_idx'" ) ) {
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX price_idx (price)" );
		}
		update_option( Schema::VERSION_OPTION, 1, true );

		Schema::maybeUpgrade();

		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
		$prices = $wpdb->get_results( "SELECT propstack_id, search_price FROM {$table}", OBJECT_K );
		$this->assertSame( '300000.00', $prices[ self::BUY_APT_BERLIN ]->search_price );
		$this->assertSame( '900.00', $prices[ self::RENT_APT_BERLIN ]->search_price );
		$this->assertNull( $prices[ self::BUY_ON_REQUEST ]->search_price );
		$this->assertNull( $prices[ self::RENT_TOTAL_ONLY ]->search_price );
		$this->assertNull( $prices[ self::REMOVED_ID ]->search_price, 'ohne Daten keine Berechnung' );
		$this->assertNull( $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'price_idx'" ), 'ungenutzter Index entfernt' );

		Schema::maybeUpgrade(); // idempotent
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::VERSION_OPTION ) );
	}
}
