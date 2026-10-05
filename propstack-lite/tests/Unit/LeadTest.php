<?php

namespace PropstackLite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PropstackLite\Domain\Property;
use PropstackLite\Leads\InquiryMailFormatter;
use PropstackLite\Leads\LeadContext;
use PropstackLite\Leads\LeadContextFactory;
use PropstackLite\Leads\LeadData;
use PropstackLite\Leads\LeadException;
use PropstackLite\Leads\RateLimiter;
use PropstackLite\Routing\RouteResolver;
use PropstackLite\Storage\StoredProperty;

final class LeadTest extends TestCase {

	private const PUBLIC   = 10;
	private const RESERVED = 11;
	private const LEAD     = '0b8f1c5e-3a2d-4f6b-9c1e-7d2a4b6c8e01';

	/* ---------------------------------------------------------------- Helfer */

	private static function property( int $id = 4711, array $extra = [] ): Property {
		return Property::fromArray(
			array_merge(
				[
					'id'              => $id,
					'slug'            => 'wohnung-kaufen-berlin',
					'title'           => 'Helle Wohnung',
					'marketingType'   => 'BUY',
					'address'         => [ 'hidden' => true, 'city' => 'Berlin', 'zipCode' => '10115' ],
					'publicExposeUrl' => 'https://crm.propstack.de/public/exposee/secret',
					'unitId'          => 'INTERN-WE-4',
				],
				$extra
			)
		);
	}

	/** @param array<int, StoredProperty> $rows */
	private static function factory( array $rows ): LeadContextFactory {
		return new LeadContextFactory(
			static fn ( int $id ) => $rows[ $id ] ?? null,
			new RouteResolver( [ self::PUBLIC, self::RESERVED ], [ self::RESERVED ], new \DateTimeImmutable( '2026-10-05 12:00:00', new \DateTimeZone( 'UTC' ) ) ),
			static fn ( StoredProperty $s ) => 'https://example.test/immobilien/' . $s->slug . '-' . $s->id . '/'
		);
	}

	private static function row( int $id, string $state, ?int $status, ?string $soldAt = null ): StoredProperty {
		return new StoredProperty( $id, 'wohnung-kaufen-berlin', $state, $status, $soldAt, null, null, self::property( $id ) );
	}

	private static function posted( array $override = [] ): array {
		return array_merge(
			[
				'salutation' => 'Herr',
				'first_name' => 'Max',
				'last_name'  => 'Mustermann',
				'email'      => 'max.mustermann@example.com',
				'phone'      => '+49 30 1234567',
				'message'    => "Guten Tag,\nich interessiere mich für die Wohnung.",
				'consent'    => '1',
			],
			$override
		);
	}

	private static function lead( array $posted = [], ?Property $property = null ): LeadContext {
		$property ??= self::property();
		$rows       = [ $property->id => new StoredProperty( $property->id, 'wohnung-kaufen-berlin', 'active', self::PUBLIC, null, null, null, $property ) ];
		return self::factory( $rows )->build( self::posted( $posted ), (string) $property->id, self::LEAD );
	}

	private static function reason( callable $fn ): string {
		try {
			$fn();
		} catch ( LeadException $e ) {
			return $e->reason();
		}
		return 'none';
	}

	/* --------------------------------------------------------- Lead-Kontext */

	public function test_context_uses_store_data_and_ignores_client_property_details(): void {
		$lead = self::lead();
		$this->assertSame( 4711, $lead->property->id );
		$this->assertSame( 'Helle Wohnung', $lead->property->title );
		$this->assertSame( 'https://example.test/immobilien/wohnung-kaufen-berlin-4711/', $lead->propertyUrl );
		$this->assertSame( self::LEAD, $lead->leadId );
		$this->assertSame( [ 'lead_id' => self::LEAD ], $lead->attribution );
		$this->assertSame( 'mr', $lead->data->salutation );
		$this->assertTrue( $lead->data->consent );
	}

	public function test_manipulated_or_unknown_property_ids_are_rejected(): void {
		$factory = self::factory( [ 1 => self::row( 1, 'active', self::PUBLIC ) ] );
		foreach ( [ '', '0', '-1', 'abc', '1 OR 1=1', '1.5', [ 1 ], null, '99999999999999999999' ] as $raw ) {
			$this->assertSame( LeadException::PROPERTY_MISSING, self::reason( fn () => $factory->build( self::posted(), $raw, self::LEAD ) ), var_export( $raw, true ) );
		}
		$this->assertSame( LeadException::PROPERTY_NOT_FOUND, self::reason( fn () => $factory->build( self::posted(), '2', self::LEAD ) ) );
		$this->assertSame( 'none', self::reason( fn () => $factory->build( self::posted(), ' 1 ', self::LEAD ) ) );
	}

	public function test_only_inquirable_properties_are_accepted(): void {
		$rows    = [
			1 => self::row( 1, 'active', self::PUBLIC ),
			2 => self::row( 2, 'active', self::RESERVED ),
			3 => self::row( 3, 'sold', 50, '2026-10-01 00:00:00' ),
			4 => self::row( 4, 'removed', null ),
			5 => self::row( 5, 'active', 99 ),
			6 => self::row( 6, 'sold', 50, '2026-01-01 00:00:00' ),
		];
		$factory = self::factory( $rows );
		$this->assertSame( 'none', self::reason( fn () => $factory->build( self::posted(), 1, self::LEAD ) ), 'öffentlich' );
		$this->assertSame( 'none', self::reason( fn () => $factory->build( self::posted(), 2, self::LEAD ) ), 'reserviert' );
		foreach ( [ 3 => 'verkauft (30-Tage-Phase)', 4 => 'entfernt (410)', 5 => 'Status nicht öffentlich', 6 => 'verkauft > 30 Tage' ] as $id => $case ) {
			$this->assertSame( LeadException::PROPERTY_NOT_INQUIRABLE, self::reason( fn () => $factory->build( self::posted(), $id, self::LEAD ) ), $case );
		}
	}

	public function test_consent_and_required_fields(): void {
		foreach ( [ '', '0', 'false', null, [] ] as $consent ) {
			$this->assertSame( LeadException::CONSENT_MISSING, self::reason( fn () => self::lead( [ 'consent' => $consent ] ) ), var_export( $consent, true ) );
		}
		$this->assertSame( LeadException::INVALID_INPUT, self::reason( fn () => self::lead( [ 'email' => 'keine-mail' ] ) ) );
		$this->assertSame( LeadException::INVALID_INPUT, self::reason( fn () => self::lead( [ 'first_name' => '   ' ] ) ) );
		$this->assertSame( LeadException::INVALID_INPUT, self::reason( fn () => self::lead( [ 'email' => "max@example.com\r\nBcc: evil@example.com" ] ) ), 'Header-Injection in E-Mail' );
		$this->assertNull( self::lead( [ 'salutation' => 'Divers' ] )->data->salutation );
	}

	public function test_single_line_fields_cannot_carry_line_breaks(): void {
		$lead = self::lead( [ 'first_name' => "Max\r\nBcc: evil@example.com", 'phone' => "+49 30\n123" ] );
		$this->assertStringNotContainsString( "\n", $lead->data->firstName );
		$this->assertStringNotContainsString( "\r", $lead->data->firstName );
		$this->assertSame( 'Max Bcc: evil@example.com', $lead->data->firstName, 'bleibt Text, wird nie Header' );
		$this->assertSame( '+49 30 123', $lead->data->phone );
	}

	/* --------------------------------------------------------- Mail-Format */

	public function test_formatter_produces_propstack_container_with_all_fields(): void {
		$html = ( new InquiryMailFormatter() )->format( self::lead(), [], 'de' );

		$this->assertStringStartsWith( '<div id="ps-kontaktanfrage">', $html );
		$this->assertStringContainsString( '<span id="client_salutation">mr</span>', $html );
		$this->assertStringContainsString( '<span id="client_first_name">Max</span>', $html );
		$this->assertStringContainsString( '<span id="client_last_name">Mustermann</span>', $html );
		$this->assertStringContainsString( '<span id="client_email">max.mustermann@example.com</span>', $html );
		$this->assertStringContainsString( '<span id="client_phone">+49 30 1234567</span>', $html );
		$this->assertStringContainsString( '<span id="client_accept_contact">ja</span>', $html );
		$this->assertStringContainsString( '<span id="client_locale">de</span>', $html );
		$this->assertStringContainsString( '<span id="property_id">4711</span>', $html );
		$this->assertStringContainsString( '<span id="body">Guten Tag,<br>' . "\n" . 'ich interessiere mich für die Wohnung.</span>', $html );
		$this->assertStringContainsString( 'https://example.test/immobilien/wohnung-kaufen-berlin-4711/', $html );
		$this->assertSame( 1, substr_count( $html, 'id="ps-kontaktanfrage"' ) );
	}

	public function test_formatter_optional_fields_and_no_marketing_consents(): void {
		$lead = self::lead( [ 'salutation' => '', 'phone' => '' ], self::property( 4711, [ 'projectId' => 77 ] ) );
		$html = ( new InquiryMailFormatter() )->format( $lead );
		$this->assertStringNotContainsString( 'client_salutation', $html );
		$this->assertStringNotContainsString( 'client_phone', $html );
		$this->assertStringNotContainsString( 'client_locale', $html );
		$this->assertStringContainsString( '<span id="project_id">77</span>', $html );
		foreach ( [ 'client_newsletter', 'client_property_mailing_wanted', 'client_cf_' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $html, "nicht ohne Freigabe/Zuordnung: {$absent}" );
		}
	}

	public function test_formatter_escapes_xss_and_special_characters(): void {
		$lead = self::lead(
			[
				'first_name' => 'Jürgen & "Söhne" <b>',
				'last_name'  => "O'Brien<script>alert(1)</script>",
				'message'    => "<img src=x onerror=alert(2)>\n[_site_admin_email] [your-email] </span></div><div id=\"ps-kontaktanfrage\">",
			],
			self::property( 4711, [ 'title' => 'Titel <script>alert(3)</script>' ] )
		);
		$html = ( new InquiryMailFormatter() )->format( $lead );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'onerror=alert(2)>', $html, 'Tags entfernt bzw. escaped' );
		$this->assertStringContainsString( 'Jürgen &amp; &quot;Söhne&quot;', $html );
		$this->assertStringContainsString( 'O&apos;Brien', $html );
		$this->assertSame( 1, substr_count( $html, 'id="ps-kontaktanfrage"' ), 'kein zweiter Container aus Benutzereingaben' );
		$this->assertStringContainsString( 'Titel &lt;script&gt;', $html );
	}

	public function test_formatter_never_contains_internal_property_fields(): void {
		$html = ( new InquiryMailFormatter() )->format( self::lead() );
		$this->assertStringNotContainsString( 'crm.propstack.de', $html );
		$this->assertStringNotContainsString( 'INTERN-WE-4', $html );
	}

	public function test_formatter_custom_fields_only_when_mapped(): void {
		$lead = self::lead();
		$html = ( new InquiryMailFormatter() )->format( $lead, [ 'lead_id' => 'website_lead_id', 'utm_source' => 'utm_source' ] );
		$this->assertStringContainsString( '<span id="client_cf_website_lead_id">' . self::LEAD . '</span>', $html );
		$this->assertStringNotContainsString( 'client_cf_utm_source', $html, 'kein Wert vorhanden → nicht senden' );
		$evil = ( new InquiryMailFormatter() )->format( $lead, [ 'lead_id' => 'x" onclick="y' ] );
		$this->assertStringNotContainsString( 'client_cf_', $evil, 'ungültiger Feldname wird ignoriert' );
	}

	public function test_lead_data_object_is_immutable(): void {
		$data = new LeadData( null, 'A', 'B', 'a@example.com', '', '', true );
		$this->expectException( \Error::class );
		$data->firstName = 'X'; // @phpstan-ignore-line
	}

	/* --------------------------------------------------------- Rate-Limit */

	private function limiter( int &$now, array &$store ): RateLimiter {
		return new RateLimiter(
			'test-salt',
			static function ( string $k ) use ( &$store ) {
				return $store[ $k ] ?? false;
			},
			static function ( string $k, $v, int $ttl ) use ( &$store ): void {
				$store[ $k ] = $v;
			},
			static function () use ( &$now ): int {
				return $now;
			}
		);
	}

	public function test_rate_limit_window(): void {
		$now   = 1000;
		$store = [];
		$rl    = $this->limiter( $now, $store );
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertTrue( $rl->attempt( '203.0.113.7' ), "Versuch {$i}" );
		}
		$this->assertFalse( $rl->attempt( '203.0.113.7' ), '6. Versuch im Fenster' );
		$this->assertTrue( $rl->attempt( '198.51.100.2' ), 'anderer Client unabhängig' );

		$now += RateLimiter::WINDOW;
		$this->assertTrue( $rl->attempt( '203.0.113.7' ), 'nach Ablauf wieder erlaubt' );
	}

	public function test_rate_limit_stores_no_plain_ip(): void {
		$now   = 1000;
		$store = [];
		$rl    = $this->limiter( $now, $store );
		$rl->attempt( '203.0.113.7' );
		$this->assertStringNotContainsString( '203.0.113.7', (string) json_encode( $store ) );
		$this->assertMatchesRegularExpression( '/^psl_rl_[0-9a-f]{32}$/', array_key_first( $store ) );
		$this->assertNotSame( $rl->clientKey( 'a' ), ( new RateLimiter( 'other-salt', fn () => false, fn () => null ) )->clientKey( 'a' ), 'salt-abhängig' );
	}
}
