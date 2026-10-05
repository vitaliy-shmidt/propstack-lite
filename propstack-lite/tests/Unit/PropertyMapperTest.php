<?php

namespace PropstackLite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PropstackLite\Domain\Property;
use PropstackLite\Mapping\FieldCatalog;
use PropstackLite\Mapping\MappingException;
use PropstackLite\Mapping\PropertyMapper;

final class PropertyMapperTest extends TestCase {

	private static function fixture( string $name ): array {
		return json_decode( (string) file_get_contents( PSL_FIXTURES . '/' . $name . '.json' ), true );
	}

	private static function buy(): Property {
		return ( new PropertyMapper() )->map( self::fixture( 'unit-buy-public' ) );
	}

	private static function rent(): Property {
		return ( new PropertyMapper() )->map( self::fixture( 'unit-rent-hidden' ) );
	}

	public function test_maps_core_fields_from_label_value_format(): void {
		$p = self::buy();
		$this->assertSame( 1000001, $p->id );
		$this->assertSame( 'Helle 2-Zimmer-Wohnung mit Dachterrasse', $p->title, 'HTML im Titel wird entfernt' );
		$this->assertSame( 900001, $p->statusId );
		$this->assertSame( 'BUY', $p->marketingType );
		$this->assertSame( 'APARTMENT', $p->rsType );
		$this->assertSame( 354200.0, $p->price );
		$this->assertSame( 61.1, $p->livingSpace );
		$this->assertSame( 2.0, $p->rooms );
		$this->assertSame( 1, $p->bathrooms );
		$this->assertSame( 'Berlin', $p->address->city, 'Leerzeichen am Ende werden entfernt' );
		$this->assertSame( '2025-11-12T11:21:46+00:00', $p->createdAt, 'Zeitstempel in UTC' );
	}

	public function test_zero_means_not_specified_except_floor(): void {
		$p = self::buy();
		$this->assertNull( $p->baseRent, 'base_rent 0 bei Kaufobjekten = nicht angegeben' );
		$this->assertNull( $p->plotArea );
		$this->assertArrayNotHasKey( 'construction_year', $p->facts, 'Baujahr 0 = nicht angegeben' );
		$this->assertSame( 0, $p->facts['floor'], 'Etage 0 = Erdgeschoss bleibt erhalten' );
	}

	public function test_visible_address_keeps_street_and_coordinates(): void {
		$a = self::buy()->address;
		$this->assertFalse( $a->hidden );
		$this->assertSame( 'Musterstraße', $a->street );
		$this->assertSame( '12', $a->houseNumber );
		$this->assertSame( 52.5321, $a->lat );
		$this->assertSame( 'Musterstraße 12, 10115 Berlin (Mitte)', $a->publicLabel() );
	}

	public function test_hidden_address_drops_street_house_number_and_coordinates(): void {
		$p = self::rent();
		$this->assertTrue( $p->address->hidden );
		$this->assertNull( $p->address->street );
		$this->assertNull( $p->address->houseNumber );
		$this->assertNull( $p->address->lat );
		$this->assertNull( $p->address->lng );
		$this->assertSame( '16515 Oranienburg (Sachsenhausen)', $p->address->publicLabel() );

		$json = json_encode( $p->toArray(), JSON_UNESCAPED_UNICODE );
		$this->assertStringNotContainsString( 'Geheimweg', $json, 'Straße darf nirgends im Modell auftauchen' );
	}

	public function test_missing_hide_address_flag_defaults_to_hidden(): void {
		$p = ( new PropertyMapper() )->map( self::fixture( 'unit-minimal' ) );
		$this->assertTrue( $p->address->hidden );
		$this->assertNull( $p->address->street );
		$this->assertSame( 900002, $p->statusId, 'flaches Format: status statt property_status' );
	}

	public function test_private_and_not_for_expose_images_are_never_mapped(): void {
		$p    = self::buy();
		$ids  = array_map( static fn ( $i ) => $i->id, $p->images );
		$json = json_encode( $p->toArray() );

		$this->assertSame( [ 11, 16 ], $ids, 'nur öffentliche Galeriebilder, nach Position sortiert' );
		$this->assertStringNotContainsString( 'PRIVATE', $json );
		$this->assertStringNotContainsString( 'NOEXPOSE', $json );
	}

	public function test_ambiguous_privacy_flag_blocks_image_missing_flag_allows(): void {
		$url = 'https://images.propstack.de/x.jpg';
		$p   = ( new PropertyMapper() )->map(
			[
				'id'     => 5,
				'images' => [
					[ 'id' => 1, 'is_private' => null, 'url' => $url ],
					[ 'id' => 2, 'is_not_for_exposee' => 'unklar', 'url' => $url ],
					[ 'id' => 3, 'url' => $url ],
				],
			]
		);
		$this->assertSame( [ 3 ], array_map( static fn ( $i ) => $i->id, $p->images ) );
	}

	public function test_floorplans_are_separated_from_gallery(): void {
		$p = self::buy();
		$this->assertCount( 1, $p->floorplans );
		$this->assertSame( 13, $p->floorplans[0]->id );
		$this->assertTrue( $p->floorplans[0]->isFloorplan );
	}

	public function test_only_https_propstack_urls_are_accepted(): void {
		$json = json_encode( self::buy()->toArray() );
		$this->assertStringNotContainsString( 'evil.example.com', $json );
		$this->assertStringNotContainsString( 'http://images', $json );
		$this->assertStringNotContainsString( 'example.com/grundbuch', $json, 'Dokumente werden nicht übernommen' );
	}

	public function test_agent_uses_only_public_contact_fields(): void {
		$agent = self::buy()->agent;
		$this->assertNotNull( $agent );
		$this->assertSame( 'Erika Muster', $agent->name );
		$this->assertSame( 'beratung@example.com', $agent->email );
		$this->assertSame( '+49 30 2222222', $agent->phone );
		$this->assertNull( $agent->cell );
		$this->assertSame( 'https://images.propstack.de/avatar/test.jpg', $agent->avatarUrl );

		$json = json_encode( self::buy()->toArray() );
		$this->assertStringNotContainsString( 'intern.makler@example.com', $json );
		$this->assertStringNotContainsString( '1111111', $json );
		$this->assertStringNotContainsString( 'ph-intern', $json );
	}

	public function test_agent_without_public_data_is_null(): void {
		$this->assertNull( self::rent()->agent, 'nur interne Kontaktdaten → kein öffentlicher Ansprechpartner' );
	}

	public function test_internal_crm_fields_are_never_mapped(): void {
		$data = self::buy()->toArray();
		$json = json_encode( $data, JSON_UNESCAPED_UNICODE );

		foreach ( [ 'INTERN: Eigentümer', 'INTERNAL-TOKEN', '123456789', 'portal-intern', 'Sensible Daten', 'Notar', 'geheim', '2 %' ] as $leak ) {
			$this->assertStringNotContainsString( $leak, $json, "Interner Wert darf nicht im Modell landen: {$leak}" );
		}

		$keys = [];
		$collect = static function ( array $a ) use ( &$collect, &$keys ): void {
			foreach ( $a as $k => $v ) {
				$keys[] = $k;
				if ( is_array( $v ) ) {
					$collect( $v );
				}
			}
		};
		$collect( $data );
		$this->assertSame( [], array_values( array_intersect( FieldCatalog::FORBIDDEN_KEYS, $keys ) ) );
	}

	public function test_internal_object_name_is_not_used_as_title_fallback(): void {
		$p = self::rent();
		$this->assertNull( $p->title, 'leerer Titel bleibt leer' );
		$this->assertStringNotContainsString( 'Geheimweg', (string) json_encode( $p->toArray(), JSON_UNESCAPED_UNICODE ) );
	}

	public function test_rich_text_is_sanitized_and_line_breaks_kept(): void {
		$text = self::buy()->texts['description'];
		$this->assertStringNotContainsString( '<script', $text );
		$this->assertStringNotContainsString( "\r", $text );
		$this->assertStringContainsString( "Willkommen!\n\n", $text );
		$this->assertStringContainsString( '<strong>mit Ausblick</strong>', $text );
		$this->assertArrayNotHasKey( 'other', self::buy()->texts, 'leere Texte entfallen' );
	}

	public function test_features_only_contain_explicit_true_values(): void {
		$this->assertSame( [ 'balcony', 'storeroom' ], self::buy()->features );
	}

	public function test_catalog_lists_and_enums(): void {
		$p = self::buy();
		$this->assertSame( [ 'Dusche', 'Fenster' ], $p->facts['bathroom'] );
		$this->assertSame( 'nach Absprache', $p->facts['free_from'] );
		$this->assertSame( 'A_PLUS', $p->energy['energy_efficiency_class'] );
		$this->assertSame( 26.33, $p->energy['energy_efficiency_value'] );
	}

	public function test_rent_fields_and_free_text_deposit(): void {
		$p = self::rent();
		$this->assertTrue( $p->isRent() );
		$this->assertNull( $p->price );
		$this->assertSame( 1200.0, $p->baseRent );
		$this->assertSame( 1500.0, $p->totalRent );
		$this->assertSame( 1200.0, $p->primaryPrice() );
		$this->assertSame( 140.5, $p->livingSpace, 'numerischer String wird akzeptiert' );
		$this->assertSame( 4.5, $p->rooms );
		$this->assertSame( '3.600,00 €', $p->facts['deposit'], 'Kaution bleibt Freitext' );
		$this->assertTrue( $p->facts['heating_costs_in_service_charge'] );
		$this->assertArrayNotHasKey( 'courtage', $p->facts, 'leerer String = nicht angegeben' );
	}

	public function test_structured_slug(): void {
		$this->assertSame( '2-zimmer-wohnung-kaufen-berlin-mitte', self::buy()->slug );
		$this->assertSame( '4-5-zimmer-haus-mieten-oranienburg-sachsenhausen', self::rent()->slug );
	}

	public function test_roundtrip_through_array_is_lossless(): void {
		$p = self::buy();
		$this->assertSame( $p->toArray(), Property::fromArray( $p->toArray() )->toArray() );
		$r = self::rent();
		$this->assertSame( $r->toArray(), Property::fromArray( $r->toArray() )->toArray() );
	}

	public function test_missing_id_throws(): void {
		$this->expectException( MappingException::class );
		( new PropertyMapper() )->map( [ 'title' => 'ohne ID' ] );
	}

	public function test_public_expose_url_is_kept_internally(): void {
		$this->assertSame( 'https://crm.propstack.de/public/exposee/test-objekt', self::buy()->publicExposeUrl );
	}
}
