<?php

namespace PropstackLite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PropstackLite\Domain\Property;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Routing\RouteDecision;
use PropstackLite\Seo\SeoData;
use PropstackLite\Seo\SeoService;

final class SeoServiceTest extends TestCase {

	private const CANONICAL = 'https://example.test/immobilien/objekt-1000001/';
	private const OVERVIEW  = 'https://example.test/immobilien/';

	private static function raw( string $fixture ): array {
		return json_decode( (string) file_get_contents( PSL_FIXTURES . '/' . $fixture . '.json' ), true );
	}

	private static function property( array $raw ): Property {
		return ( new PropertyMapper() )->map( $raw );
	}

	private static function service(): SeoService {
		return new SeoService( 'Picaflor Immobilien', 'https://example.test/' );
	}

	private static function seo( array $raw, string $state = RouteDecision::ACTIVE ): SeoData {
		return self::service()->forProperty( self::property( $raw ), new RouteDecision( $state, 200 ), self::CANONICAL, self::OVERVIEW );
	}

	private static function node( SeoData $seo, string $type ): ?array {
		foreach ( $seo->schemaNodes() as $node ) {
			if ( $type === $node['@type'] ) {
				return $node;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ Title */

	public function test_title_natural_with_rooms_mode_place_and_brand(): void {
		$this->assertSame( '2-Zimmer-Wohnung kaufen in Berlin-Mitte | Picaflor Immobilien', self::seo( self::raw( 'unit-buy-public' ) )->title );
		$this->assertSame( '4,5-Zimmer-Haus mieten in Oranienburg | Picaflor Immobilien', self::seo( self::raw( 'unit-rent-hidden' ) )->title );
	}

	public function test_title_matches_spec_example(): void {
		$raw             = self::raw( 'unit-buy-public' );
		$raw['district'] = 'Charlottenburg';
		$raw['number_of_rooms']['value'] = 3;
		$this->assertSame( '3-Zimmer-Wohnung kaufen in Berlin-Charlottenburg | Picaflor Immobilien', self::seo( $raw )->title );
	}

	public function test_title_is_independent_of_h1_and_never_contains_street(): void {
		foreach ( [ 'unit-buy-public', 'unit-full', 'unit-rent-hidden', 'unit-minimal' ] as $fixture ) {
			$seo = self::seo( self::raw( $fixture ) );
			$this->assertStringEndsWith( ' | Picaflor Immobilien', $seo->title );
			foreach ( [ 'Musterstraße', 'Versteckweg', 'Dachterrasse', '<' ] as $needle ) {
				$this->assertStringNotContainsString( $needle, $seo->title, $fixture );
			}
		}
	}

	public function test_title_shortens_district_then_rooms_without_cutting_words(): void {
		$raw             = self::raw( 'unit-buy-public' );
		$raw['district'] = 'Prenzlauer Berg';
		$this->assertSame( '2-Zimmer-Wohnung kaufen in Berlin | Picaflor Immobilien', self::seo( $raw )->title, 'Ortsteil fällt zuerst weg' );

		$raw['city'] = 'Hohen Neuendorf bei Berlin an der Havel Nord';
		$title       = self::seo( $raw )->title;
		$this->assertLessThanOrEqual( SeoService::TITLE_TARGET, mb_strlen( $title ) );
		$this->assertStringEndsWith( ' | Picaflor Immobilien', $title );
		$this->assertStringStartsWith( 'Wohnung kaufen in Hohen', $title, 'Zimmer fallen vor dem Wortschnitt weg' );
		$body = substr( $title, 0, -strlen( ' | Picaflor Immobilien' ) );
		$this->assertMatchesRegularExpression( '/^(Wohnung kaufen in )?[\p{L} ]+$/u', $body );
		$this->assertStringContainsString( trim( $body ), 'Wohnung kaufen in ' . $raw['city'], 'nur ganze Wörter' );
	}

	public function test_title_translates_type_and_skips_rooms_for_non_residential(): void {
		$raw            = self::raw( 'unit-buy-public' );
		$raw['rs_type'] = 'OFFICE';
		$this->assertSame( 'Büro kaufen in Berlin-Mitte | Picaflor Immobilien', self::seo( $raw )->title );
		$raw['rs_type'] = 'UNKNOWN_TYPE';
		$this->assertSame( 'Immobilie kaufen in Berlin-Mitte | Picaflor Immobilien', self::seo( $raw )->title );
	}

	public function test_title_without_marketing_type_or_rooms(): void {
		$this->assertSame( 'Immobilie in Teststadt | Picaflor Immobilien', self::seo( self::raw( 'unit-minimal' ) )->title );
	}

	/* ------------------------------------------------------------ Description */

	public function test_description_contains_building_blocks(): void {
		$d = self::seo( self::raw( 'unit-buy-public' ) )->description;
		$this->assertSame( 'Wohnung zum Kauf in Berlin-Mitte: 2 Zimmer, ca. 61,1 m² Wohnfläche, Kaufpreis 354.200 €, mit Balkon/Terrasse. Jetzt weitere Informationen anfragen.', $d );
		$rent = self::seo( self::raw( 'unit-rent-hidden' ) )->description;
		$this->assertStringContainsString( 'Haus zur Miete in Oranienburg', $rent );
		$this->assertStringContainsString( 'Kaltmiete 1.200 €/Monat', $rent );
	}

	public function test_description_length_utf8_no_html_and_full_sentences(): void {
		foreach ( [ 'unit-buy-public', 'unit-full', 'unit-rent-hidden', 'unit-minimal' ] as $fixture ) {
			$d = (string) self::seo( self::raw( $fixture ) )->description;
			$this->assertLessThanOrEqual( 160, mb_strlen( $d ), $fixture );
			$this->assertTrue( mb_check_encoding( $d, 'UTF-8' ), $fixture );
			$this->assertSame( strip_tags( $d ), $d, $fixture );
			$this->assertStringEndsWith( '.', $d, "{$fixture}: ganzer Satz" );
			$this->assertStringNotContainsString( '…', $d, $fixture );
		}
	}

	public function test_description_drops_blocks_instead_of_cutting(): void {
		$raw         = self::raw( 'unit-full' );
		$raw['city'] = 'Sehr Langer Ortsname Mit Vielen Wörtern Und Noch Mehr Text';
		$d           = self::seo( $raw )->description;
		$this->assertLessThanOrEqual( 160, mb_strlen( $d ) );
		$this->assertStringEndsWith( '.', $d );
		$this->assertStringContainsString( 'Kaufpreis 429.000 €', $d, 'Preis bleibt am längsten' );
	}

	public function test_description_never_mentions_price_on_request_as_zero(): void {
		$raw                              = self::raw( 'unit-buy-public' );
		$raw['price']['value']            = null;
		$raw['price_on_inquiry']['value'] = true;
		$d                                = self::seo( $raw )->description;
		$this->assertStringNotContainsString( '0 €', $d );
		$this->assertStringNotContainsString( 'Kaufpreis', $d );
	}

	public function test_plain_removes_markup_from_all_seo_values(): void {
		$this->assertSame( 'Ort alert(1) "x"', SeoService::plain( "Ort <script>alert(1)</script>
 \"x\"<>" ) );
		$raw          = self::raw( 'unit-buy-public' );
		$raw['city']  = 'Ort <b>fett</b>';
		$raw['title'] = [ 'label' => 'T', 'value' => 'A <i>B</i>' ];
		$json         = (string) json_encode( (array) self::seo( $raw ), JSON_UNESCAPED_UNICODE );
		$this->assertStringNotContainsString( '<', $json );
		$this->assertStringNotContainsString( '>', $json );
	}

	public function test_truncate_words_is_utf8_safe(): void {
		$this->assertSame( 'Größe Öl…', SeoService::truncateWords( 'Größe Öl Übergröße Äpfel', 11 ) );
		$this->assertSame( 'kurz', SeoService::truncateWords( 'kurz', 10 ) );
	}

	/* ----------------------------------------------------------- Robots/Canon */

	public function test_robots_by_state(): void {
		$this->assertSame( 'index, follow', self::seo( self::raw( 'unit-buy-public' ) )->robotsString() );
		$this->assertSame( 'index, follow', self::seo( self::raw( 'unit-buy-public' ), RouteDecision::RESERVED )->robotsString() );
		$this->assertSame( 'noindex, follow', self::seo( self::raw( 'unit-buy-public' ), RouteDecision::SOLD )->robotsString() );
		$gone = self::service()->forGone();
		$this->assertSame( 'noindex, follow', $gone->robotsString() );
		$this->assertNull( $gone->canonical );
		$this->assertNull( $gone->jsonLd );
		$this->assertNull( $gone->openGraph );
		$this->assertNull( $gone->description );
	}

	public function test_canonical_and_og_url_are_the_given_canonical(): void {
		$seo = self::seo( self::raw( 'unit-buy-public' ) );
		$this->assertSame( self::CANONICAL, $seo->canonical );
		$this->assertSame( self::CANONICAL, $seo->openGraph['url'] );
		$this->assertSame( self::CANONICAL, self::node( $seo, 'RealEstateListing' )['url'] );
	}

	/* ----------------------------------------------------------- OG/Twitter */

	public function test_og_and_twitter_with_public_non_floorplan_image(): void {
		$seo = self::seo( self::raw( 'unit-full' ) );
		$this->assertSame( 'https://images.propstack.de/p/full/101-big.jpg', $seo->openGraph['image'] );
		$this->assertSame( 'de_DE', $seo->openGraph['locale'] );
		$this->assertSame( 'website', $seo->openGraph['type'] );
		$this->assertSame( 'summary_large_image', $seo->twitter['card'] );
		$this->assertSame( $seo->openGraph['image'], $seo->twitter['image'] );
		$json = (string) json_encode( [ $seo->openGraph, $seo->twitter, $seo->jsonLd ] );
		foreach ( [ 'PRIVATE', 'NOEXPOSE', 'FLOORPLAN' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	public function test_no_og_image_without_public_image(): void {
		$raw           = self::raw( 'unit-full' );
		$raw['images'] = [];
		$seo           = self::seo( $raw );
		$this->assertArrayNotHasKey( 'image', $seo->openGraph );
		$this->assertArrayNotHasKey( 'image', $seo->twitter );
		$this->assertSame( 'summary', $seo->twitter['card'] );
		$this->assertArrayNotHasKey( 'image', self::node( $seo, 'RealEstateListing' ) );
	}

	/* ---------------------------------------------------------------- Schema */

	public function test_schema_buy_offer_and_types(): void {
		$seo     = self::seo( self::raw( 'unit-buy-public' ) );
		$listing = self::node( $seo, 'RealEstateListing' );
		$this->assertSame( 'https://schema.org', $seo->jsonLd['@context'] );
		$this->assertSame( [ '@type' => 'Offer', 'url' => self::CANONICAL, 'businessFunction' => 'http://purl.org/goodrelations/v1#Sell', 'price' => 354200.0, 'priceCurrency' => 'EUR' ], $listing['offers'] );
		$this->assertSame( 'Helle 2-Zimmer-Wohnung mit Dachterrasse', $listing['name'], 'Name = H1 (Propstack-Titel), ohne HTML' );
		$apartment = self::node( $seo, 'Apartment' );
		$this->assertNotNull( $apartment );
		$this->assertSame( 'Musterstraße 12', $apartment['address']['streetAddress'], 'öffentliche Adresse vollständig' );
		$this->assertSame( 'DE', $apartment['address']['addressCountry'] );
		$this->assertArrayHasKey( 'geo', $apartment );
	}

	public function test_schema_rent_uses_unit_price_specification(): void {
		$offer = self::node( self::seo( self::raw( 'unit-rent-hidden' ) ), 'RealEstateListing' )['offers'];
		$this->assertArrayNotHasKey( 'price', $offer );
		$this->assertSame( 1200.0, $offer['priceSpecification']['price'] );
		$this->assertSame( 'UnitPriceSpecification', $offer['priceSpecification']['@type'] );
		$this->assertSame( 'MON', $offer['priceSpecification']['unitCode'] );
		$this->assertNotNull( self::node( self::seo( self::raw( 'unit-rent-hidden' ) ), 'House' ) );
	}

	public function test_schema_never_contains_price_zero(): void {
		$raw                              = self::raw( 'unit-buy-public' );
		$raw['price']['value']            = null;
		$raw['price_on_inquiry']['value'] = true;
		$listing                          = self::node( self::seo( $raw ), 'RealEstateListing' );
		$this->assertArrayNotHasKey( 'offers', $listing );

		$listing = self::node( self::seo( self::raw( 'unit-minimal' ) ), 'RealEstateListing' );
		$this->assertArrayNotHasKey( 'offers', $listing );
		$this->assertDoesNotMatchRegularExpression( '/"price":0\b/', (string) json_encode( self::seo( self::raw( 'unit-minimal' ) )->jsonLd ) );
	}

	public function test_schema_hidden_address_has_no_street_or_geo(): void {
		$seo   = self::seo( self::raw( 'unit-full' ) );
		$place = self::node( $seo, 'Apartment' );
		$this->assertSame( [ '@type' => 'PostalAddress', 'postalCode' => '10437', 'addressLocality' => 'Berlin' ], $place['address'] );
		$this->assertArrayNotHasKey( 'geo', $place );
		$json = (string) json_encode( $seo->jsonLd, JSON_UNESCAPED_UNICODE );
		foreach ( [ 'Versteckweg', '52.5432', '13.4211', 'streetAddress', 'GeoCoordinates' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	public function test_schema_unknown_type_is_place_without_floor_size(): void {
		$seo = self::seo( self::raw( 'unit-minimal' ) );
		$this->assertNotNull( self::node( $seo, 'Place' ) );
	}

	public function test_schema_agent_only_site_data_and_breadcrumb(): void {
		$seo   = self::seo( self::raw( 'unit-full' ) );
		$agent = self::node( $seo, 'RealEstateAgent' );
		$this->assertSame( [ '@type' => 'RealEstateAgent', '@id' => 'https://example.test/#psl-realestateagent', 'name' => 'Picaflor Immobilien', 'url' => 'https://example.test/' ], $agent );
		$listing = self::node( $seo, 'RealEstateListing' );
		$this->assertSame( [ '@id' => $agent['@id'] ], $listing['offeredBy'], 'Makler ist nie offeredBy' );

		$crumbs = self::node( $seo, 'BreadcrumbList' )['itemListElement'];
		$this->assertSame( [ 1, 2, 3 ], array_column( $crumbs, 'position' ) );
		$this->assertSame( 'https://example.test/', $crumbs[0]['item'] );
		$this->assertSame( self::OVERVIEW, $crumbs[1]['item'] );
		foreach ( $crumbs as $crumb ) {
			$this->assertNotEmpty( $crumb['item'] ?? null, 'Google: jeder Eintrag braucht eine URL' );
		}
		$this->assertSame( self::CANONICAL, $crumbs[2]['item'] );
	}

	public function test_schema_and_meta_never_contain_internal_fields(): void {
		$json = (string) json_encode( (array) self::seo( self::raw( 'unit-full' ) ), JSON_UNESCAPED_UNICODE );
		foreach ( [ 'INTERN', 'owner', 'broker_id', 'MS12-WE3', 'private_note' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}
}
