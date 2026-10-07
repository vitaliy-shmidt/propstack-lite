<?php

namespace PropstackLite\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PropstackLite\Domain\Property;
use PropstackLite\Frontend\ListingConfig;
use PropstackLite\Frontend\ListingRequest;
use PropstackLite\Frontend\Pagination;
use PropstackLite\Mapping\FieldCatalog;
use PropstackLite\Routing\UrlGenerator;
use PropstackLite\Seo\SeoService;
use PropstackLite\Storage\ListCriteria;
use PropstackLite\Storage\PropertySearchCriteria;
use PropstackLite\Storage\SearchQueryBuilder;

/** Phase 7: Suchkriterien, URL-Whitelist, Sortierung, Pagination, SEO der Übersicht. */
final class ListingSearchTest extends TestCase {

	private static function request( array $query, array $atts = [] ): ListingRequest {
		return ListingRequest::fromQuery( $query, ListingConfig::fromAtts( $atts ) );
	}

	/* --------------------------------------------------------- SearchCriteria */

	public function test_valid_filters_are_mapped_to_criteria(): void {
		$r = self::request(
			[
				'marketing_type'   => 'buy',
				'property_type'    => 'apartment',
				'city'             => 'Berlin',
				'price_min'        => '100000',
				'price_max'        => '500000',
				'living_space_min' => '80',
				'plot_area_min'    => '300',
				'rooms_min'        => '3',
				'sort'             => 'price_asc',
				'per'              => '24',
				'seite'            => '2',
			]
		);
		$c = $r->criteria();

		$this->assertSame( 'BUY', $c->marketingType );
		$this->assertSame( [ 'APARTMENT' ], $c->rsTypes );
		$this->assertSame( 'Berlin', $c->city );
		$this->assertSame( 100000.0, $c->priceMin );
		$this->assertSame( 500000.0, $c->priceMax );
		$this->assertSame( 80.0, $c->livingSpaceMin );
		$this->assertSame( 300.0, $c->plotAreaMin );
		$this->assertSame( 3.0, $c->roomsMin );
		$this->assertSame( 'price_asc', $c->sort );
		$this->assertSame( 24, $c->perPage );
		$this->assertSame( 2, $c->page );
		$this->assertSame( 24, $c->offset() );
		$this->assertTrue( $r->hasFilters() );
		$this->assertTrue( $r->isCustomized() );
	}

	public function test_defaults_without_parameters(): void {
		$r = self::request( [] );
		$c = $r->criteria();

		$this->assertNull( $c->marketingType );
		$this->assertSame( [], $c->rsTypes );
		$this->assertNull( $c->city );
		$this->assertNull( $c->priceMin );
		$this->assertSame( 'newest', $c->sort );
		$this->assertSame( 12, $c->perPage );
		$this->assertSame( 1, $c->page );
		$this->assertFalse( $r->hasFilters() );
		$this->assertFalse( $r->isCustomized() );
		$this->assertSame( [], $r->queryArgs() );
	}

	public function test_normalization(): void {
		$r = self::request( [ 'marketing_type' => ' RENT ', 'property_type' => 'HOUSE', 'city' => "  Werder   (Havel) ", 'price_min' => '900', 'price_max' => '500', 'sort' => 'PRICE_DESC' ] );

		$this->assertSame( 'rent', $r->value( 'marketing_type' ) );
		$this->assertSame( 'house', $r->value( 'property_type' ) );
		$this->assertSame( 'Werder (Havel)', $r->value( 'city' ) );
		$this->assertSame( '500', $r->value( 'price_min' ), 'vertauschte Preisgrenzen werden getauscht' );
		$this->assertSame( '900', $r->value( 'price_max' ) );
		$this->assertSame( 'price_desc', $r->sort );
		$this->assertSame( 'RENT', $r->criteria()->marketingType );
	}

	public function test_default_values_count_as_not_set(): void {
		$r = self::request( [ 'marketing_type' => '', 'city' => '', 'price_min' => '', 'rooms_min' => '', 'sort' => 'newest', 'per' => '12', 'seite' => '1' ] );

		$this->assertFalse( $r->hasFilters() );
		$this->assertFalse( $r->isCustomized() );
		$this->assertSame( [], $r->queryArgs() );
	}

	public function test_criteria_constructor_enforces_ranges(): void {
		$c = new PropertySearchCriteria( marketingType: 'SELL', rsTypes: [ 'APARTMENT', "x' OR 1=1", 7 ], priceMin: -5, priceMax: INF, roomsMin: 0, sort: 'nope', page: 0, perPage: 100000 );

		$this->assertNull( $c->marketingType );
		$this->assertSame( [ 'APARTMENT' ], $c->rsTypes );
		$this->assertNull( $c->priceMin );
		$this->assertNull( $c->priceMax );
		$this->assertNull( $c->roomsMin );
		$this->assertSame( 'newest', $c->sort );
		$this->assertSame( 1, $c->page );
		$this->assertSame( PropertySearchCriteria::MAX_PER_PAGE, $c->perPage );
	}

	/* ----------------------------------------------- Whitelist / Security-Eingaben */

	/** @return array<string, array{0: array<string, mixed>}> */
	public static function invalidInputs(): array {
		return [
			'SQL-Injection city'    => [ [ 'city' => "Berlin' OR '1'='1" ] ],
			'SQL-Kommentar city'    => [ [ 'city' => 'Berlin; DROP TABLE wp_psl_properties; --' ] ],
			'XSS city'              => [ [ 'city' => '<script>alert(1)</script>' ] ],
			'XSS Attribut city'     => [ [ 'city' => '"><img src=x onerror=alert(1)>' ] ],
			'zu langer Ort'         => [ [ 'city' => str_repeat( 'a', 101 ) ] ],
			'SQL-Injection sort'    => [ [ 'sort' => 'price_asc; DROP TABLE x' ] ],
			'Spaltenname als sort'  => [ [ 'sort' => 'data' ] ],
			'Shortcode-Sortierung'  => [ [ 'sort' => 'city_asc' ] ],
			'negativer Preis'       => [ [ 'price_min' => '-100', 'price_max' => '-1' ] ],
			'Preis 0'               => [ [ 'price_min' => '0' ] ],
			'Preis mit Exponent'    => [ [ 'price_max' => '1e9' ] ],
			'Preis dezimal'         => [ [ 'price_max' => '500.000' ] ],
			'riesiger Preis'        => [ [ 'price_max' => '99999999999999999999' ] ],
			'riesige Fläche'        => [ [ 'living_space_min' => '10000000' ] ],
			'Zimmer 6'              => [ [ 'rooms_min' => '6' ] ],
			'Zimmer 2.5'            => [ [ 'rooms_min' => '2.5' ] ],
			'Zimmer Text'           => [ [ 'rooms_min' => 'drei' ] ],
			'Array statt Wert'      => [ [ 'city' => [ 'Berlin' ], 'marketing_type' => [ 'buy' ], 'price_max' => [ '1' ] ] ],
			'unbekannte Objektart'  => [ [ 'property_type' => 'castle' ] ],
			'unbekannte Vermarktung' => [ [ 'marketing_type' => 'lease' ] ],
			'Objekt statt Wert'     => [ [ 'city' => new \stdClass() ] ],
			'unbekannte Parameter'  => [ [ 'status' => '999', 'state' => 'removed', 'utm_source' => 'google', 'gclid' => 'abc', 'orderby' => 'data' ] ],
		];
	}

	#[DataProvider( 'invalidInputs' )]
	public function test_invalid_inputs_are_ignored( array $query ): void {
		$r = self::request( $query );

		$this->assertFalse( $r->hasFilters(), 'ungültige Werte gelten als nicht gesetzt' );
		$this->assertFalse( $r->isCustomized() );
		$this->assertSame( [], $r->queryArgs() );
		$c = $r->criteria();
		$this->assertNull( $c->city );
		$this->assertNull( $c->priceMin );
		$this->assertNull( $c->priceMax );
		$this->assertSame( 'newest', $c->sort );
	}

	/** @return array<string, array{0: mixed, 1: int}> */
	public static function pageInputs(): array {
		return [
			'Seite 2'           => [ '2', 2 ],
			'Array page[]'      => [ [ '1' ], 1 ],
			'negativ'           => [ '-3', 1 ],
			'null'              => [ '0', 1 ],
			'Text'              => [ 'zwei', 1 ],
			'SQL'               => [ '2 OR 1=1', 1 ],
			'riesig'            => [ '99999999999', 1 ],
			'über Maximum'      => [ '1001', 1 ],
			'Maximum'           => [ '1000', 1000 ],
		];
	}

	#[DataProvider( 'pageInputs' )]
	public function test_page_parameter( mixed $value, int $expected ): void {
		$this->assertSame( $expected, self::request( [ 'seite' => $value ] )->page );
	}

	public function test_per_page_only_from_whitelist(): void {
		$this->assertSame( 48, self::request( [ 'per' => '48' ] )->perPage );
		foreach ( [ '1000000', '100', '13', '-12', '0', 'all' ] as $value ) {
			$this->assertSame( 12, self::request( [ 'per' => $value ] )->perPage, "per={$value}" );
		}
		$this->assertSame( 12, self::request( [ 'per' => [ '48' ] ] )->perPage );
		$this->assertSame( 100, self::request( [], [ 'per' => '100' ] )->perPage, 'Shortcode darf bis 100 (Rückwärtskompatibilität)' );
		$this->assertSame( [ 6, 12, 24, 48 ], self::request( [], [ 'per' => '6' ] )->perPageOptions() );
	}

	public function test_page_parameter_named_page_is_not_used(): void {
		$this->assertSame( 1, self::request( [ 'page' => '3' ] )->page, '`page` ist eine WordPress-Query-Variable' );
	}

	public function test_tracking_parameters_do_not_affect_state(): void {
		$with    = self::request( [ 'city' => 'Berlin', 'utm_source' => 'google', 'utm_campaign' => 'x', 'gclid' => 'Cj0', 'fbclid' => 'IwA' ] );
		$without = self::request( [ 'city' => 'Berlin' ] );

		$this->assertSame( $without->queryArgs(), $with->queryArgs() );
		$this->assertSame( $without->criteria()->cacheKey(), $with->criteria()->cacheKey() );
	}

	/* -------------------------------------------- Shortcode-Konfiguration (fest) */

	public function test_fixed_shortcode_constraints_win_over_url(): void {
		$r = self::request( [ 'marketing_type' => 'buy', 'property_type' => 'apartment', 'city' => 'Potsdam', 'price_max' => '2000' ], [ 'marketing_type' => 'RENT', 'property_type' => 'house', 'city' => 'Berlin', 'price_to' => '1500' ] );
		$c = $r->criteria();

		$this->assertSame( 'RENT', $c->marketingType );
		$this->assertSame( [ 'HOUSE' ], $c->rsTypes );
		$this->assertSame( 'Berlin', $c->city );
		$this->assertSame( 1500.0, $c->priceMax, 'Besucher kann feste Grenze nur enger machen' );
		$this->assertSame( 1200.0, self::request( [ 'price_max' => '1200' ], [ 'price_to' => '1500' ] )->criteria()->priceMax );
		$this->assertSame( [ 'price_max' => '2000' ], $r->filters() );
	}

	public function test_legacy_shortcode_attributes(): void {
		$config = ListingConfig::fromAtts( [ 'rs_type' => 'apartment', 'sort_by' => 'price', 'order' => 'asc', 'limit' => '5', 'heading' => 'h2', 'status' => '999' ] );

		$this->assertSame( [ 'APARTMENT' ], $config->fixedRsTypes );
		$this->assertSame( 'apartment', $config->fixedTypeGroup );
		$this->assertSame( 'price_asc', $config->defaultSort );
		$this->assertSame( 5, $config->perPage );
		$this->assertSame( 'h2', $config->heading );
		$this->assertSame( 'h3', ListingConfig::fromAtts( [ 'heading' => 'h1' ] )->heading, 'keine zweite H1' );
		$this->assertSame( 'h3', ListingConfig::fromAtts( [ 'heading' => '<script>' ] )->heading );
	}

	public function test_static_list_ignores_url(): void {
		$r = self::request( [ 'city' => 'Berlin', 'sort' => 'price_asc', 'seite' => '3' ], [ 'show_filters' => '0', 'show_sort' => '0', 'pagination' => '0', 'page' => '2' ] );

		$this->assertFalse( $r->config->isInteractive() );
		$this->assertFalse( $r->hasFilters() );
		$this->assertSame( 'newest', $r->sort );
		$this->assertSame( 2, $r->page, 'statische Liste: page-Attribut wie bisher' );
		$this->assertSame( [], $r->queryArgs() );
	}

	public function test_hidden_form_parts_are_not_read_from_url(): void {
		$r = self::request( [ 'city' => 'Berlin', 'sort' => 'price_asc' ], [ 'show_filters' => 'no' ] );
		$this->assertFalse( $r->hasFilters() );
		$this->assertSame( 'price_asc', $r->sort );

		$r = self::request( [ 'city' => 'Berlin', 'sort' => 'price_asc' ], [ 'show_sort' => 'false' ] );
		$this->assertTrue( $r->hasFilters() );
		$this->assertSame( 'newest', $r->sort );
	}

	public function test_query_args_order_and_page(): void {
		$r = self::request( [ 'seite' => '3', 'rooms_min' => '2', 'city' => 'Berlin', 'marketing_type' => 'buy', 'utm_source' => 'x' ] );

		$this->assertSame( [ 'marketing_type' => 'buy', 'city' => 'Berlin', 'rooms_min' => '2', 'seite' => '3' ], $r->queryArgs() );
		$this->assertSame( [ 'marketing_type' => 'buy', 'city' => 'Berlin', 'rooms_min' => '2' ], $r->queryArgs( 1 ), 'Seite 1 ohne Parameter' );
		$this->assertSame( '4', $r->queryArgs( 4 )['seite'] );
	}

	public function test_type_groups(): void {
		$this->assertSame( 'commercial', FieldCatalog::typeGroupOf( 'OFFICE' ) );
		$this->assertSame( 'plot', FieldCatalog::typeGroupOf( 'TRADE_SITE' ) );
		$this->assertNull( FieldCatalog::typeGroupOf( 'SHORT_TERM_ACCOMODATION' ), 'seltene Objektart ohne eigene Filteroption' );
		$this->assertNull( FieldCatalog::typeGroupOf( null ) );
		$this->assertSame( [ 'OFFICE', 'STORE', 'GASTRONOMY', 'INDUSTRY', 'SPECIAL_PURPOSE' ], self::request( [ 'property_type' => 'commercial' ] )->criteria()->rsTypes );
		foreach ( FieldCatalog::TYPE_GROUPS as [ $label ] ) {
			$this->assertDoesNotMatchRegularExpression( '/^[A-Z_]+$/', $label, 'nur deutsche Bezeichnungen' );
		}
	}

	/* ----------------------------------------------------------------- SQL */

	public function test_where_uses_placeholders_only(): void {
		$c = new PropertySearchCriteria( marketingType: 'BUY', rsTypes: [ 'APARTMENT', 'HOUSE' ], city: "O'Brien", priceMin: 1, priceMax: 2, livingSpaceMin: 3, plotAreaMin: 4, roomsMin: 2.5, excludeIds: [ 9 ] );
		[ $sql, $params ] = SearchQueryBuilder::where( [ 701, 702 ], $c );

		$this->assertStringStartsWith( 'state = %s AND data IS NOT NULL AND status_id IN (%d,%d)', $sql );
		$this->assertStringNotContainsString( "O'Brien", $sql, 'Werte nie im SQL-Text' );
		$this->assertStringContainsString( 'rs_type IN (%s,%s)', $sql );
		$this->assertStringContainsString( 'search_price >= %f AND search_price <= %f', $sql );
		$this->assertStringContainsString( 'rooms >= %f', $sql );
		$this->assertSame( [ 'active', 701, 702, 'BUY', 'APARTMENT', 'HOUSE', "O'Brien", 1.0, 2.0, 3.0, 4.0, 2.5, 9 ], $params );
		$this->assertSame( substr_count( $sql, '%' ), count( $params ) );
	}

	public function test_no_public_status_means_no_query(): void {
		$this->assertNull( SearchQueryBuilder::where( [], new PropertySearchCriteria() ) );
		$this->assertNull( SearchQueryBuilder::where( [ 0, -1, 'x' ], new PropertySearchCriteria() ) );
	}

	/** @return array<string, array{0: string, 1: string}> */
	public static function sorts(): array {
		return [
			'newest'     => [ 'newest', 'remote_created_at IS NULL, remote_created_at DESC, propstack_id DESC' ],
			'oldest'     => [ 'oldest', 'remote_created_at IS NULL, remote_created_at ASC, propstack_id ASC' ],
			'updated'    => [ 'updated', 'content_changed_at DESC, propstack_id DESC' ],
			'price_asc'  => [ 'price_asc', 'search_price IS NULL, search_price ASC, propstack_id ASC' ],
			'price_desc' => [ 'price_desc', 'search_price IS NULL, search_price DESC, propstack_id DESC' ],
			'area_asc'   => [ 'area_asc', 'living_space IS NULL, living_space ASC, propstack_id ASC' ],
			'area_desc'  => [ 'area_desc', 'living_space IS NULL, living_space DESC, propstack_id DESC' ],
			'rooms_asc'  => [ 'rooms_asc', 'rooms IS NULL, rooms ASC, propstack_id ASC' ],
			'rooms_desc' => [ 'rooms_desc', 'rooms IS NULL, rooms DESC, propstack_id DESC' ],
			'city_asc'   => [ 'city_asc', 'city IS NULL, city ASC, propstack_id ASC' ],
			'ungültig'   => [ 'price; DROP TABLE x', 'remote_created_at IS NULL, remote_created_at DESC, propstack_id DESC' ],
		];
	}

	#[DataProvider( 'sorts' )]
	public function test_sort_whitelist_with_stable_tie_breaker( string $sort, string $expected ): void {
		$order = SearchQueryBuilder::orderBy( new PropertySearchCriteria( sort: $sort ) );
		$this->assertSame( $expected, $order );
		$this->assertStringEndsWith( 'propstack_id ' . ( str_contains( $expected, ' ASC,' ) ? 'ASC' : 'DESC' ), $order, 'eindeutiger Tie-Breaker' );
	}

	public function test_every_ui_sort_is_whitelisted(): void {
		foreach ( array_keys( ListingRequest::SORT_LABELS + ListingRequest::EXTRA_SORT_LABELS ) as $key ) {
			$this->assertArrayHasKey( $key, PropertySearchCriteria::SORTS );
		}
		foreach ( PropertySearchCriteria::SORTS as [ $column ] ) {
			$this->assertNotSame( 'remote_updated_at', $column, 'interne CRM-Änderungen bestimmen keine Sortierung' );
		}
	}

	public function test_legacy_list_criteria_translation(): void {
		$c = ListCriteria::fromInput( [ 'marketing_type' => 'rent', 'rs_type' => 'house', 'price_to' => '1000', 'sort_by' => 'created_at', 'order' => 'asc', 'per_page' => 2, 'page' => 2 ] )->toSearchCriteria();
		$this->assertSame( 'RENT', $c->marketingType );
		$this->assertSame( [ 'HOUSE' ], $c->rsTypes );
		$this->assertSame( 1000.0, $c->priceMax );
		$this->assertSame( 'oldest', $c->sort );
		$this->assertSame( 2, $c->offset() );
		$this->assertSame( 'updated', ( new ListCriteria( sortBy: 'updated_at' ) )->toSearchCriteria()->sort );
	}

	/* ---------------------------------------------------------- Preisbasis */

	public function test_search_price_never_zero_and_respects_price_on_request(): void {
		$p = static fn ( array $a ) => Property::fromArray( $a + [ 'id' => 1 ] );

		$this->assertSame( 350000.0, $p( [ 'marketingType' => 'BUY', 'price' => 350000 ] )->searchPrice() );
		$this->assertSame( 950.0, $p( [ 'marketingType' => 'RENT', 'baseRent' => 950, 'totalRent' => 1200 ] )->searchPrice(), 'Miete = Kaltmiete' );
		$this->assertNull( $p( [ 'marketingType' => 'RENT', 'totalRent' => 1200 ] )->searchPrice(), 'nur Warmmiete → nicht filterbar' );
		$this->assertNull( $p( [ 'marketingType' => 'BUY', 'price' => 350000, 'priceOnRequest' => true ] )->searchPrice(), 'Preis auf Anfrage nicht erratbar' );
		$this->assertNull( $p( [ 'marketingType' => 'BUY', 'price' => 0 ] )->searchPrice(), 'nie 0 €' );
		$this->assertNull( $p( [ 'marketingType' => 'BUY' ] )->searchPrice() );
	}

	public function test_card_price_row_respects_price_on_request_for_rent(): void {
		$rent = Property::fromArray( [ 'id' => 1, 'marketingType' => 'RENT', 'baseRent' => 950, 'priceOnRequest' => true ] );
		$this->assertSame( [ 'label' => 'Miete', 'value' => 'auf Anfrage' ], \PropstackLite\Frontend\Formatter::priceRow( $rent ) );
	}

	/* ---------------------------------------------------------- Pagination */

	public function test_pagination_first_page(): void {
		$p = new Pagination( 40, 12, 1 );
		$this->assertSame( 4, $p->pages );
		$this->assertNull( $p->previous() );
		$this->assertSame( 2, $p->next() );
		$this->assertSame( [ 1, 12 ], $p->range() );
		$this->assertSame( [ 1, 2, 3, 4 ], $p->items() );
		$this->assertTrue( $p->isNeeded() );
	}

	public function test_pagination_second_and_last_page(): void {
		$p = new Pagination( 40, 12, 2 );
		$this->assertSame( 1, $p->previous() );
		$this->assertSame( 3, $p->next() );
		$this->assertSame( [ 13, 24 ], $p->range() );

		$last = new Pagination( 40, 12, 4 );
		$this->assertNull( $last->next() );
		$this->assertSame( [ 37, 40 ], $last->range() );
	}

	public function test_pagination_too_high_page(): void {
		$p = new Pagination( 40, 12, 9 );
		$this->assertTrue( $p->isOutOfRange() );
		$this->assertFalse( $p->isNeeded() );
		$this->assertSame( [], $p->items() );
		$this->assertSame( [ 0, 0 ], $p->range() );
		$this->assertNull( $p->previous() );
	}

	public function test_pagination_without_results(): void {
		$p = new Pagination( 0, 12, 1 );
		$this->assertSame( 1, $p->pages );
		$this->assertFalse( $p->isOutOfRange() );
		$this->assertFalse( $p->isNeeded() );
		$this->assertTrue( ( new Pagination( 0, 12, 2 ) )->isOutOfRange() );
	}

	public function test_pagination_items_with_gaps(): void {
		$this->assertSame( [ 1, null, 9, 10, 11, null, 20 ], ( new Pagination( 240, 12, 10 ) )->items() );
		$this->assertSame( [ 1, 2, 3, 4, null, 20 ], ( new Pagination( 240, 12, 3 ) )->items(), 'einzelne Lücke wird als Zahl gezeigt' );
		$this->assertSame( [ 1, null, 19, 20 ], ( new Pagination( 240, 12, 20 ) )->items() );
	}

	/* ------------------------------------------------------------ URL / SEO */

	public function test_listing_url(): void {
		$urls = new UrlGenerator();
		$this->assertSame( 'https://example.org/immobilien/', $urls->listingUrl( 'https://example.org/immobilien/', [] ) );
		$this->assertSame( 'https://example.org/immobilien/?city=K%C3%B6ln&seite=2', $urls->listingUrl( 'https://example.org/immobilien/#x', [ 'city' => 'Köln', 'seite' => '2' ] ) );
		$this->assertSame( 'https://example.org/?page_id=4&seite=2', $urls->listingUrl( 'https://example.org/?page_id=4', [ 'seite' => '2' ] ), 'ohne Pretty Permalinks' );
		$this->assertSame( 'https://example.org/immobilien/?city=Werder%20%28Havel%29', $urls->listingUrl( 'https://example.org/immobilien/', [ 'city' => 'Werder (Havel)' ] ) );
	}

	public function test_listing_seo(): void {
		$seo = new SeoService( 'Picaflor', 'https://example.org/' );

		$plain = $seo->forListing( 'https://example.org/immobilien/', 1, false, false );
		$this->assertTrue( $plain->isIndexable() );
		$this->assertSame( 'https://example.org/immobilien/', $plain->canonical );
		$this->assertNull( $plain->titleSuffix );

		$page2 = $seo->forListing( 'https://example.org/immobilien/?seite=2', 2, false, false );
		$this->assertTrue( $page2->isIndexable(), 'Pagination ohne Filter bleibt indexierbar' );
		$this->assertSame( 'https://example.org/immobilien/?seite=2', $page2->canonical, 'Self-Canonical, nicht Seite 1' );
		$this->assertSame( 'Immobilien – Seite 2', $page2->title( 'Immobilien' ) );

		$filtered = $seo->forListing( 'https://example.org/immobilien/?city=Berlin', 1, true, false );
		$this->assertSame( 'noindex, follow', $filtered->robotsString() );
		$this->assertSame( 'https://example.org/immobilien/?city=Berlin', $filtered->canonical );

		$this->assertFalse( $seo->forListing( 'https://example.org/immobilien/?seite=99', 99, false, true )->isIndexable(), 'Seite hinter der letzten' );
	}
}
