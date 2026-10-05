<?php

namespace PropstackLite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PropstackLite\Frontend\Formatter;
use PropstackLite\Mapping\PropertyMapper;

final class FormatterTest extends TestCase {

	public function test_money(): void {
		$this->assertSame( '429.000 €', Formatter::money( 429000.0 ) );
		$this->assertSame( '310,50 €', Formatter::money( 310.5 ) );
		$this->assertSame( '1.250 € / Monat', Formatter::monthly( 1250.0 ) );
		$this->assertSame( '5.200 €/m²', Formatter::perSquareMeter( 5200.0 ) );
		$this->assertNull( Formatter::money( null ) );
		$this->assertNull( Formatter::monthly( null ) );
	}

	public function test_display_price_buy_rent_and_on_request(): void {
		$m = new PropertyMapper();

		$buy = Formatter::displayPrice( $m->map( [ 'id' => 1, 'marketing_type' => 'BUY', 'price' => 429000 ] ) );
		$this->assertSame( [ 'label' => 'Kaufpreis', 'value' => '429.000 €', 'onRequest' => false ], $buy );

		$rent = Formatter::displayPrice( $m->map( [ 'id' => 2, 'marketing_type' => 'RENT', 'base_rent' => 1250, 'price' => 0 ] ) );
		$this->assertSame( [ 'label' => 'Kaltmiete', 'value' => '1.250 € / Monat', 'onRequest' => false ], $rent );

		$warm = Formatter::displayPrice( $m->map( [ 'id' => 3, 'marketing_type' => 'RENT', 'total_rent' => 1500 ] ) );
		$this->assertSame( 'Warmmiete', $warm['label'] );

		$this->assertSame( 'Preis auf Anfrage', Formatter::displayPrice( $m->map( [ 'id' => 4, 'marketing_type' => 'BUY', 'price' => 0 ] ) )['value'], '0 = nicht angegeben' );
		$this->assertTrue( Formatter::displayPrice( $m->map( [ 'id' => 5, 'marketing_type' => 'BUY', 'price' => 300000, 'price_on_inquiry' => true ] ) )['onRequest'], 'price_on_inquiry hat Vorrang' );
		$this->assertSame( 'Preis auf Anfrage', Formatter::displayPrice( $m->map( [ 'id' => 6, 'marketing_type' => 'RENT' ] ) )['value'] );
	}

	public function test_areas_rooms_floor_energy(): void {
		$this->assertSame( '82,5 m²', Formatter::area( 82.5 ) );
		$this->assertSame( '1.250 m²', Formatter::area( 1250.0 ) );
		$this->assertSame( '3 Zimmer', Formatter::rooms( 3.0 ) );
		$this->assertSame( '2,5 Zimmer', Formatter::rooms( 2.5 ) );
		$this->assertSame( 'Erdgeschoss', Formatter::floor( 0 ) );
		$this->assertSame( '3. Obergeschoss', Formatter::floor( 3 ) );
		$this->assertNull( Formatter::floor( null ) );
		$this->assertSame( '98,4 kWh/(m²·a)', Formatter::energyValue( 98.4 ) );
	}

	public function test_dates(): void {
		$this->assertSame( '15.03.2024', Formatter::date( '2024-03-15' ) );
		$this->assertSame( '15.03.2024', Formatter::date( '2024-03-15T10:00:00Z' ) );
		$this->assertSame( '14.03.2034', Formatter::date( '14.03.2034' ) );
		$this->assertSame( 'ab 1. Mai 2014', Formatter::date( 'ab 1. Mai 2014' ) );
		$this->assertNull( Formatter::date( '  ' ) );
	}

	public function test_enum_labels_never_show_raw_enums(): void {
		$this->assertSame( 'A+', Formatter::enumLabel( 'energy_efficiency_class', 'A_PLUS' ) );
		$this->assertSame( 'A+', Formatter::enumLabel( 'energy_efficiency_class', 'A+' ), 'bereits lesbar' );
		$this->assertSame( 'C', Formatter::enumLabel( 'energy_efficiency_class', 'C' ) );
		$this->assertSame( 'Dachgeschosswohnung', Formatter::enumLabel( 'rs_category', 'ROOF_STOREY' ) );
		$this->assertNull( Formatter::enumLabel( 'rs_category', 'UNKNOWN_CATEGORY' ), 'unbekanntes Enum wird nicht ausgegeben' );
		$this->assertNull( Formatter::text( 'FIRST_TIME_USE' ) );
		$this->assertSame( 'Erstbezug', Formatter::text( 'Erstbezug' ) );
		$this->assertSame( 'KWK fossil', Formatter::text( 'KWK fossil' ) );
		$this->assertSame( 'Parkett, Fliesen', Formatter::text( [ 'Parkett', 'Fliesen' ] ) );
	}

	public function test_yes_no(): void {
		$this->assertSame( 'Ja', Formatter::yesNo( true ) );
		$this->assertSame( 'Nein', Formatter::yesNo( false ) );
		$this->assertNull( Formatter::yesNo( null ) );
	}
}
