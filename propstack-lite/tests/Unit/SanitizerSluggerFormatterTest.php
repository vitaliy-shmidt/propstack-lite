<?php

namespace PropstackLite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PropstackLite\Domain\Address;
use PropstackLite\Frontend\Formatter;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Mapping\Sanitizer;
use PropstackLite\Support\Slugger;

final class SanitizerSluggerFormatterTest extends TestCase {

	public function test_float_rules(): void {
		$this->assertNull( Sanitizer::float( 0 ) );
		$this->assertSame( 0.0, Sanitizer::float( 0, false ) );
		$this->assertNull( Sanitizer::float( -5 ) );
		$this->assertNull( Sanitizer::float( '3600,00€' ), 'Freitext ist keine Zahl' );
		$this->assertNull( Sanitizer::float( true ) );
		$this->assertSame( 61.1, Sanitizer::float( '61.1' ) );
	}

	public function test_bool_rules(): void {
		$this->assertTrue( Sanitizer::bool( 'true' ) );
		$this->assertFalse( Sanitizer::bool( 0 ) );
		$this->assertNull( Sanitizer::bool( null ) );
		$this->assertNull( Sanitizer::bool( 'vielleicht' ) );
	}

	public function test_urls_only_https_propstack(): void {
		$this->assertSame( 'https://images.propstack.de/a.jpg', Sanitizer::propstackUrl( 'https://images.propstack.de/a.jpg' ) );
		$this->assertSame( 'https://images.propstack.de/a%20b.jpg', Sanitizer::propstackUrl( 'https://images.propstack.de/a b.jpg' ) );
		$this->assertNull( Sanitizer::propstackUrl( 'http://images.propstack.de/a.jpg' ) );
		$this->assertNull( Sanitizer::propstackUrl( 'https://propstack.de.evil.com/a.jpg' ) );
		$this->assertNull( Sanitizer::propstackUrl( 'javascript:alert(1)' ) );
	}

	public function test_text_and_contact_rules(): void {
		$this->assertSame( 'a b', Sanitizer::text( " a \n <i>b</i> " ) );
		$this->assertNull( Sanitizer::text( '   ' ) );
		$this->assertNull( Sanitizer::email( 'kein-mail' ) );
		$this->assertSame( '+49 (30) 123-45', Sanitizer::phone( '+49 (30) 123-45<script>' ) );
		$this->assertSame( '2026-10-05T10:25:35+00:00', Sanitizer::datetime( '2026-10-05T12:25:35.997+02:00' ) );
	}

	public function test_slugify_is_deterministic_for_german_umlauts(): void {
		$this->assertSame( 'schoene-aussicht-strasse', Slugger::slugify( 'Schöne Aussicht – Straße' ) );
		$this->assertSame( 'ueber-oeko-aerger', Slugger::slugify( 'Über Öko Ärger' ) );
		$this->assertSame( '', Slugger::slugify( '!!!' ) );
		$this->assertLessThanOrEqual( 80, strlen( Slugger::slugify( str_repeat( 'abc ', 50 ) ) ) );
	}

	public function test_slug_for_property_fallbacks(): void {
		$this->assertSame( 'immobilie', Slugger::forProperty( null, null, null, null, null ) );
		$this->assertSame( 'grundstueck-kaufen-bad-saarow', Slugger::forProperty( 'TRADE_SITE', null, 'BUY', 'Bad Saarow', null ) );
		$this->assertSame( '3-zimmer-wohnung-mieten-berlin', Slugger::forProperty( 'APARTMENT', 3.0, 'RENT', 'Berlin', 'Berlin' ) );
	}

	public function test_address_constructor_enforces_hidden(): void {
		$a = new Address( true, 'Straße', '1', '10115', 'Berlin', null, null, null, null, 1.0, 2.0 );
		$this->assertNull( $a->street );
		$this->assertNull( $a->lat );
		$this->assertSame( '10115 Berlin', $a->publicLabel() );
		$this->assertTrue( Address::fromArray( [] )->hidden, 'fehlende Angabe = verborgen' );
	}

	public function test_price_row_never_shows_zero(): void {
		$mapper = new PropertyMapper();
		$buyNoPrice = $mapper->map( [ 'id' => 1, 'marketing_type' => 'BUY', 'price' => [ 'label' => 'Preis', 'value' => 0 ], 'base_rent' => 0 ] );
		$this->assertSame( [ 'label' => 'Kaufpreis', 'value' => 'auf Anfrage' ], Formatter::priceRow( $buyNoPrice ) );

		$rent = $mapper->map( [ 'id' => 2, 'marketing_type' => 'RENT', 'base_rent' => 925.0 ] );
		$this->assertSame( [ 'label' => 'Kaltmiete', 'value' => '925 €' ], Formatter::priceRow( $rent ) );

		$buy = $mapper->map( [ 'id' => 3, 'marketing_type' => 'BUY', 'price' => 354200.0 ] );
		$this->assertSame( [ 'label' => 'Kaufpreis', 'value' => '354.200 €' ], Formatter::priceRow( $buy ) );
	}

	public function test_number_formatting(): void {
		$this->assertSame( '61,1 m²', Formatter::area( 61.1 ) );
		$this->assertSame( '1.250 m²', Formatter::area( 1250.0 ) );
		$this->assertSame( '2,5', Formatter::decimal( 2.5, 1 ) );
		$this->assertSame( 'Wohnung in 10115 Berlin', Formatter::title( ( new PropertyMapper() )->map( [ 'id' => 9, 'rs_type' => 'APARTMENT', 'zip_code' => '10115', 'city' => 'Berlin' ] ) ) );
	}
}
