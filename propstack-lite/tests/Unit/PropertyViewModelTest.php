<?php

namespace PropstackLite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PropstackLite\Frontend\PropertyViewModel;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Routing\RouteDecision;

final class PropertyViewModelTest extends TestCase {

	private static function raw(): array {
		return json_decode( (string) file_get_contents( PSL_FIXTURES . '/unit-full.json' ), true );
	}

	private static function view( array $raw, string $state = RouteDecision::ACTIVE ): array {
		$property = ( new PropertyMapper() )->map( $raw );
		return PropertyViewModel::build( $property, new RouteDecision( $state, 200 ), 'https://example.test/immobilien/x-1/', 'https://example.test/immobilien/' );
	}

	private static function json( array $view ): string {
		return (string) json_encode( $view, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public function test_gallery_contains_only_public_non_floorplan_images(): void {
		$view = self::view( self::raw() );
		$this->assertCount( 3, $view['gallery'] );
		$this->assertSame( 'https://images.propstack.de/p/full/101-medium.jpg', $view['mainImage']['src'] );
		$this->assertSame( 'https://images.propstack.de/p/full/101-medium.jpg 600w, https://images.propstack.de/p/full/101-big.jpg 1920w', $view['mainImage']['srcset'] );
		$this->assertSame( 'https://images.propstack.de/p/full/101-big.jpg', $view['mainImage']['full'] );
		$this->assertSame( 280, $view['mainImage']['thumbSize'] );

		$json = self::json( $view );
		$this->assertStringNotContainsString( 'PRIVATE', $json );
		$this->assertStringNotContainsString( 'NOEXPOSE', $json );
		$this->assertStringNotContainsString( 'PRIVATEPLAN', $json );
	}

	public function test_floorplans_are_separate(): void {
		$view = self::view( self::raw() );
		$this->assertCount( 1, $view['floorplans'] );
		$this->assertStringContainsString( '106-FLOORPLAN', $view['floorplans'][0]['full'] );
		$this->assertStringNotContainsString( 'FLOORPLAN', self::json( $view['gallery'] ) );
	}

	public function test_alt_texts(): void {
		$view = self::view( self::raw() );
		$this->assertSame( 'Wohnzimmer mit Dielen', $view['gallery'][0]['alt'], 'Bildtitel hat Vorrang' );
		$this->assertSame( 'Großzügige 3-Zimmer-Wohnung mit Südbalkon – Bild 3 von 3', $view['gallery'][2]['alt'], 'Fallback mit Kontext' );
	}

	public function test_hidden_address_never_reaches_view(): void {
		$view = self::view( self::raw() );
		$json = self::json( $view );
		$this->assertSame( '10437 Berlin (Prenzlauer Berg)', $view['location'] );
		foreach ( [ 'Versteckweg', '52.5432', '13.4211', '"7"' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $json, "Adressdetail im ViewModel: {$needle}" );
		}
	}

	public function test_agent_public_fields_only(): void {
		$view  = self::view( self::raw() );
		$agent = $view['agent'];
		$this->assertSame( 'Dr. Max Beispiel', $agent['name'] );
		$this->assertSame( 'Geschäftsführer', $agent['position'] );
		$this->assertSame( 'kontakt@example.com', $agent['email'] );
		$this->assertSame( '+49 30 6666666', $agent['phone'] );
		$this->assertSame( '+49 170 6666666', $agent['cell'] );
		$json = self::json( $view );
		foreach ( [ 'max.intern@example.com', '5555555', 'ph-full', 'OLD-FULL', 'INTERN: Schlüssel', 'FULL-INTERNAL-TOKEN', 'Alleinauftrag', 'crm.propstack.de' ] as $internal ) {
			$this->assertStringNotContainsString( $internal, $json, "interner Wert im ViewModel: {$internal}" );
		}
	}

	public function test_fact_groups_buy(): void {
		$groups = array_column( self::view( self::raw() )['factGroups'], 'items', 'id' );
		$costs  = array_column( $groups['costs'], 'value', 'label' );
		$this->assertSame( '429.000 €', $costs['Kaufpreis'] );
		$this->assertSame( '5.200 €/m²', $costs['Preis pro m²'] );
		$this->assertSame( '310,50 € / Monat', $costs['Hausgeld'] );
		$this->assertSame( '45 € / Monat', $costs['Instandhaltungsrücklage'] );
		$this->assertSame( '25.000 €', $costs['Stellplatz'] );
		$this->assertSame( '3,57 % inkl. MwSt.', $costs['Provision'] );

		$areas = array_column( $groups['areas'], 'value', 'label' );
		$this->assertSame( '82,5 m²', $areas['Wohnfläche'] );
		$this->assertSame( '8,25 m²', $areas['Balkon-/Terrassenfläche'] );
		$this->assertSame( '3. Obergeschoss', $areas['Etage'] );
		$this->assertArrayNotHasKey( 'Grundstücksfläche', $areas, 'Grundstück 0 = nicht angegeben' );

		$building = array_column( $groups['building'], 'value', 'label' );
		$this->assertSame( 'Dachgeschosswohnung', $building['Objektkategorie'] );
		$this->assertSame( '1910', $building['Baujahr'] );
		$this->assertSame( 'nach Absprache', $building['Verfügbar ab'] );
		$this->assertArrayNotHasKey( 'Vermietet', $building, 'vermietet=false wird nicht als „Nein“ gelistet' );
	}

	public function test_fact_groups_rent(): void {
		$raw = array_merge(
			self::raw(),
			[
				'marketing_type'                  => 'RENT',
				'price'                           => null,
				'base_rent'                       => [ 'label' => 'Kaltmiete', 'value' => 1250 ],
				'total_rent'                      => [ 'label' => 'Warmmiete', 'value' => 1580 ],
				'service_charge'                  => [ 'label' => 'NK', 'value' => 330 ],
				'heating_costs_in_service_charge' => [ 'label' => 'Heizkosten in NK', 'value' => true ],
				'deposit'                         => [ 'label' => 'Kaution', 'value' => '3 Kaltmieten' ],
			]
		);
		$costs = array_column( self::view( $raw )['factGroups'][0]['items'], 'value', 'label' );
		$this->assertSame( '1.250 € / Monat', $costs['Kaltmiete'] );
		$this->assertSame( '1.580 € / Monat', $costs['Warmmiete'] );
		$this->assertSame( '330 € / Monat (inkl. Heizkosten)', $costs['Nebenkosten'] );
		$this->assertSame( '3 Kaltmieten', $costs['Kaution'] );
		$this->assertArrayNotHasKey( 'Kaufpreis', $costs );
		$this->assertArrayNotHasKey( 'Hausgeld', $costs, 'Kaufbegriffe nicht bei Miete' );
	}

	public function test_features_only_positive(): void {
		$features = self::view( self::raw() )['features'];
		$this->assertSame(
			[ 'Balkon/Terrasse', 'Keller', 'Aufzug', 'Einbauküche', 'Stellplatz: Tiefgarage', 'Haustiere nach Vereinbarung', 'Bad: Wanne, Fenster', 'Böden: Dielen, Fliesen' ],
			$features
		);
		$this->assertNotContains( 'Garten/-mitbenutzung', $features );
	}

	public function test_energy(): void {
		$energy = array_column( self::view( self::raw() )['energy'], 'value', 'label' );
		$this->assertSame( 'liegt vor', $energy['Energieausweis'] );
		$this->assertSame( 'Bedarfsausweis', $energy['Art des Energieausweises'] );
		$this->assertSame( '98,4 kWh/(m²·a)', $energy['Endenergiebedarf'] );
		$this->assertSame( 'C', $energy['Energieeffizienzklasse'] );
		$this->assertSame( 'Fernwärme', $energy['Wesentlicher Energieträger'] );
		$this->assertSame( '15.03.2024', $energy['Ausgestellt am'] );
		$this->assertSame( '14.03.2034', $energy['Gültig bis'] );
		$this->assertSame( 'Ja', $energy['Warmwasser im Kennwert enthalten'] );
		$this->assertSame( '2015', $energy['Baujahr Anlagentechnik'] );
	}

	public function test_minimal_object_has_no_empty_sections(): void {
		$view = self::view( [ 'id' => 5, 'marketing_type' => 'BUY', 'zip_code' => '10115', 'city' => 'Berlin' ] );
		$this->assertSame( [], $view['gallery'] );
		$this->assertNull( $view['mainImage'] );
		$this->assertSame( [], $view['floorplans'] );
		$this->assertSame( [], $view['features'] );
		$this->assertSame( [], $view['energy'] );
		$this->assertNull( $view['agent'] );
		$this->assertFalse( $view['hasEquipmentSection'] );
		$this->assertFalse( $view['hasOtherSection'] );
		$this->assertSame( [ 'costs' ], array_column( $view['factGroups'], 'id' ), 'nur Preis-Gruppe („Preis auf Anfrage“)' );
		$this->assertSame( 'Preis auf Anfrage', $view['displayPrice']['value'] );
		$this->assertSame( [], $view['keyFacts'] );
	}

	public function test_non_https_image_urls_are_dropped_even_if_stored(): void {
		$property = \PropstackLite\Domain\Property::fromArray(
			[
				'id'     => 9,
				'slug'   => 's',
				'images' => [
					[ 'id' => 1, 'big' => 'javascript:alert(1)' ],
					[ 'id' => 2, 'medium' => 'https://images.propstack.de/ok-m.jpg', 'big' => 'http://insecure.example/b.jpg' ],
				],
			]
		);
		$view = PropertyViewModel::build( $property, new RouteDecision( RouteDecision::ACTIVE, 200 ), 'https://x/', 'https://x/' );
		$this->assertCount( 1, $view['gallery'] );
		$this->assertSame( 'https://images.propstack.de/ok-m.jpg 600w', $view['gallery'][0]['srcset'] );
		$this->assertStringNotContainsString( 'javascript', self::json( $view ) );
		$this->assertStringNotContainsString( 'http://insecure', self::json( $view ) );
	}

	public function test_breadcrumb_and_contact(): void {
		$view = self::view( self::raw() );
		$this->assertSame( [ 'Immobilien', 'Berlin', 'Großzügige 3-Zimmer-Wohnung mit Südbalkon' ], array_column( $view['breadcrumb'], 'label' ) );
		$this->assertSame( 'https://example.test/immobilien/', $view['breadcrumb'][0]['url'] );
		$this->assertTrue( $view['contact']['allowed'] );
		$this->assertSame( 'Interesse an dieser Immobilie?', $view['contact']['heading'] );

		$sold = self::view( self::raw(), RouteDecision::SOLD );
		$this->assertFalse( $sold['contact']['allowed'] );
		$this->assertSame( 'Verkauft', $sold['statusBadge']['label'] );
	}

	public function test_view_does_not_expose_internal_model_fields(): void {
		$view = self::view( self::raw() );
		foreach ( [ 'publicExposeUrl', 'unitId', 'exposeeId', 'statusId', 'projectId' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $view );
		}
	}
}
