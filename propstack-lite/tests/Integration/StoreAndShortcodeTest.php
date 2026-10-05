<?php

namespace PropstackLite\Tests\Integration;

use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Storage\ListCriteria;

final class StoreAndShortcodeTest extends IntegrationTestCase {

	private const PUBLIC   = 601;
	private const RESERVED = 602;
	private const HIDDEN   = 603;

	protected function setUp(): void {
		parent::setUp();
		$this->settings( [ 'public_status_ids' => [ self::PUBLIC, self::RESERVED ], 'reserved_status_ids' => [ self::RESERVED ] ] );

		$mapper = new PropertyMapper();
		$now    = '2026-10-05 12:00:00';
		$this->store->upsertActive( $mapper->map( self::raw( 1, self::PUBLIC, [ 'title' => [ 'label' => 'T', 'value' => 'Tom & "Jerry" Wohnung' ], 'created_at' => '2026-03-01T00:00:00Z' ] ) ), $now );
		$this->store->upsertActive( $mapper->map( self::raw( 2, self::RESERVED, [ 'hide_address' => true, 'street' => 'Geheimgasse', 'created_at' => '2026-02-01T00:00:00Z' ] ) ), $now );
		$this->store->upsertActive( $mapper->map( self::raw( 3, self::PUBLIC, [ 'marketing_type' => 'RENT', 'price' => null, 'base_rent' => 950.0, 'created_at' => '2026-01-01T00:00:00Z' ] ) ), $now );
		// Aktiv gespeichert, aber Status inzwischen nicht mehr öffentlich (z. B. Einstellung geändert):
		$this->store->upsertActive( $mapper->map( self::raw( 4, self::HIDDEN, [ 'title' => [ 'label' => 'T', 'value' => 'NICHT-OEFFENTLICH' ] ] ) ), $now );
	}

	public function test_visibility_is_enforced_by_status_whitelist(): void {
		$result = $this->store->queryPublic( [ self::PUBLIC, self::RESERVED ], new ListCriteria( perPage: 50 ) );
		$this->assertSame( 3, $result['total'] );
		$this->assertSame( [ 1, 2, 3 ], array_map( static fn ( $p ) => $p->id, $result['items'] ), 'neueste zuerst' );

		$this->assertSame( 0, $this->store->queryPublic( [], new ListCriteria() )['total'], 'ohne Whitelist nichts sichtbar' );
	}

	public function test_filters_sorting_and_paging(): void {
		$rent = $this->store->queryPublic( [ self::PUBLIC, self::RESERVED ], ListCriteria::fromInput( [ 'marketing_type' => 'rent' ] ) );
		$this->assertSame( [ 3 ], array_map( static fn ( $p ) => $p->id, $rent['items'] ) );

		$cheap = $this->store->queryPublic( [ self::PUBLIC, self::RESERVED ], ListCriteria::fromInput( [ 'price_to' => '1000' ] ) );
		$this->assertSame( [ 3 ], array_map( static fn ( $p ) => $p->id, $cheap['items'] ), 'Preisfilter nutzt Kaltmiete bei Miete' );

		$page2 = $this->store->queryPublic( [ self::PUBLIC, self::RESERVED ], ListCriteria::fromInput( [ 'per_page' => 2, 'page' => 2, 'sort_by' => 'created_at', 'order' => 'asc' ] ) );
		$this->assertSame( [ 1 ], array_map( static fn ( $p ) => $p->id, $page2['items'] ) );
		$this->assertSame( 3, $page2['total'] );

		$evil = ListCriteria::fromInput( [ 'sort_by' => 'price; DROP TABLE x', 'order' => 'sideways', 'rs_type' => "x' OR 1=1" ] );
		$this->assertSame( 'created_at', $evil->sortBy );
		$this->assertNull( $evil->rsType );
	}

	public function test_shortcode_renders_only_public_data_escaped(): void {
		$html = do_shortcode( '[propstack_list per="10" status="' . self::HIDDEN . '"]' );

		$this->assertStringNotContainsString( 'NICHT-OEFFENTLICH', $html, 'status-Attribut schaltet nichts frei' );
		$this->assertStringNotContainsString( 'Geheimgasse', $html, 'verborgene Adresse' );
		$this->assertStringNotContainsString( 'PRIVATE', $html, 'private Bilder' );
		$this->assertStringContainsString( 'Tom &amp; &quot;Jerry&quot; Wohnung', $html );
		$this->assertStringContainsString( 'Teststraße 1, 10115 Berlin', $html, 'freigegebene Adresse mit Straße' );
		$this->assertStringContainsString( 'Reserviert', $html );
		$this->assertStringContainsString( 'Kaltmiete', $html );
		$this->assertStringContainsString( '950 €', $html );
		$this->assertStringContainsString( home_url( '/immobilien/2-zimmer-wohnung-kaufen-berlin-1/' ), $html, 'absolute kanonische Detail-URL' );
		$this->assertStringNotContainsString( 'target="_blank"', $html );
		$this->assertTrue( wp_style_is( 'propstack-lite-list', 'enqueued' ) );
	}

	public function test_shortcode_filters_and_empty_state(): void {
		$html = do_shortcode( '[propstack_list marketing_type="RENT"]' );
		$this->assertSame( 1, substr_count( $html, '<article' ) );

		$none = do_shortcode( '[propstack_list city="Nirgendwo"]' );
		$this->assertStringContainsString( 'Derzeit sind keine passenden Immobilien verfügbar.', $none );
	}

	public function test_shortcode_does_not_call_propstack(): void {
		$calls = 0;
		$spy   = static function ( $pre, $args, $url ) use ( &$calls ) {
			if ( str_contains( (string) $url, 'propstack.de' ) ) {
				++$calls;
			}
			return $pre;
		};
		add_filter( 'pre_http_request', $spy, 10, 3 );
		do_shortcode( '[propstack_list]' );
		remove_filter( 'pre_http_request', $spy, 10 );

		$this->assertSame( 0, $calls );
	}
}
