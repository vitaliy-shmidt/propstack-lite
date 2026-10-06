<?php

namespace PropstackLite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PropstackLite\Leads\InquiryMailFormatter;
use PropstackLite\Leads\LeadContext;
use PropstackLite\Leads\LeadData;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Settings;
use PropstackLite\Tracking\Attribution;
use PropstackLite\Tracking\AttributionStorage;
use PropstackLite\Tracking\Consent\ConsentProviders;
use PropstackLite\Tracking\LeadEvent;
use PropstackLite\Tracking\Touch;

final class TrackingTest extends TestCase {

	private const NOW  = 1800000000;
	private const LEAD = '0b9a6c2e-4f1d-4a8b-9c3e-1234567890ab';

	private static function json( array $first, ?array $last = null ): string {
		return (string) json_encode( [ 'v' => 1, 'f' => $first, 'l' => $last ?? $first ] );
	}

	private static function storage( int $days = 90 ): AttributionStorage {
		return new AttributionStorage( $days );
	}

	public function test_parse_first_and_last_touch(): void {
		$a = self::storage()->parse(
			self::json(
				[ 's' => 'google', 'm' => 'cpc', 'c' => 'wohnung_berlin', 'ch' => 'paid_search', 'g' => 'TEST123', 'lp' => '/immobilien/', 'rh' => 'google.de', 'ts' => self::NOW - 86400 ],
				[ 's' => 'facebook', 'm' => 'paid_social', 'c' => 'retargeting', 'ch' => 'paid_social', 'ct' => 'video', 'ts' => self::NOW - 3600 ]
			),
			self::NOW
		);
		$this->assertSame( 'google', $a->first->source );
		$this->assertSame( 'facebook', $a->last->source );
		$this->assertSame(
			[
				'first_utm_source'   => 'google',
				'first_utm_medium'   => 'cpc',
				'first_utm_campaign' => 'wohnung_berlin',
				'last_utm_source'    => 'facebook',
				'last_utm_medium'    => 'paid_social',
				'last_utm_campaign'  => 'retargeting',
				'utm_content'        => 'video',
				'gclid'              => 'TEST123',
				'landing_path'       => '/immobilien/',
				'referrer_host'      => 'google.de',
			],
			$a->leadFields()
		);
	}

	public function test_invalid_cookie_data_is_ignored(): void {
		$s = self::storage();
		foreach ( [ '', 'kein json', '{"v":2,"f":{"s":"x","ts":1800000000}}', '[]', str_repeat( 'a', 3000 ) ] as $raw ) {
			$this->assertTrue( $s->parse( $raw, self::NOW )->isEmpty(), $raw );
		}
		$this->assertTrue( $s->parse( self::json( [ 's' => 'google' ] ), self::NOW )->isEmpty(), 'ohne Zeitstempel' );
		$this->assertTrue( $s->parse( self::json( [ 'm' => 'cpc', 'ts' => self::NOW ] ), self::NOW )->isEmpty(), 'ohne Herkunft' );
	}

	public function test_ttl_and_future_timestamps(): void {
		$old = self::json( [ 's' => 'google', 'ts' => self::NOW - 91 * 86400 ] );
		$this->assertTrue( self::storage()->parse( $old, self::NOW )->isEmpty(), '> 90 Tage' );
		$this->assertFalse( self::storage( 120 )->parse( $old, self::NOW )->isEmpty(), 'TTL konfigurierbar' );
		$this->assertTrue( self::storage()->parse( self::json( [ 's' => 'google', 'ts' => self::NOW + 3600 ] ), self::NOW )->isEmpty(), 'Zukunft' );
	}

	public function test_values_are_sanitized_xss_and_click_ids(): void {
		$a = self::storage()->parse(
			self::json( [ 's' => '<script>alert(1)</script>', 'c' => "\"onload='x'`", 'g' => 'abc<def', 'gb' => str_repeat( 'x', 300 ), 'wb' => 'ok_ID-1.2', 'lp' => '/a?b=1', 'rh' => 'evil.example/path', 'ch' => 'hacked', 'ts' => self::NOW ] ),
			self::NOW
		);
		$t = $a->first;
		$this->assertSame( 'scriptalert(1)/script', $t->source );
		$this->assertSame( 'onload=x', $t->campaign );
		$this->assertSame( '', $t->gclid );
		$this->assertSame( '', $t->gbraid );
		$this->assertSame( 'ok_ID-1.2', $t->wbraid );
		$this->assertSame( '', $t->landingPath );
		$this->assertSame( '', $t->referrerHost );
		$this->assertSame( 'other', $t->channel );
		$this->assertSame( 100, mb_strlen( Touch::text( str_repeat( 'ä', 150 ) ) ) );
	}

	public function test_click_id_falls_back_to_first_touch(): void {
		$a = self::storage()->parse(
			self::json( [ 's' => 'google', 'm' => 'cpc', 'g' => 'G1', 'ts' => self::NOW - 100 ], [ 's' => 'newsletter', 'm' => 'email', 'ts' => self::NOW - 50 ] ),
			self::NOW
		);
		$this->assertSame( 'G1', $a->leadFields()['gclid'] );
		$this->assertSame( 'newsletter', $a->leadFields()['last_utm_source'] );
	}

	public function test_custom_field_map_keys_and_aliases(): void {
		$map = Settings::cleanCustomFieldMap( [ 'lead_id' => 'Website_Lead', 'utm_source' => 'quelle', 'gclid' => 'gclid', 'first_utm_source' => 'erste-quelle', 'unbekannt' => 'x' ] );
		$this->assertSame( [ 'lead_id' => 'website_lead', 'last_utm_source' => 'quelle', 'gclid' => 'gclid' ], $map, 'Alias utm_source → last_utm_source; ungültiger Name verworfen' );
		$this->assertSame( array_merge( [ 'lead_id' ], Attribution::LEAD_FIELDS ), Settings::ATTRIBUTION_KEYS );
	}

	private static function lead(): LeadContext {
		$property = ( new PropertyMapper() )->map( json_decode( (string) file_get_contents( PSL_FIXTURES . '/unit-full.json' ), true ) );
		return new LeadContext( self::LEAD, $property, 'https://example.test/immobilien/x-1/', new LeadData( null, 'Max', 'Muster', 'max@example.com', '+49 30 1', 'Hallo', true ), [ 'lead_id' => self::LEAD ] );
	}

	public function test_lead_attribution_only_known_keys_and_server_lead_id(): void {
		$lead = self::lead()->withAttribution( [ 'lead_id' => 'gefälscht', 'gclid' => 'G1', 'first_utm_source' => 'google', 'email' => 'x@example.com', 'last_utm_medium' => '' ] );
		$attr = $lead->attribution;
		ksort( $attr );
		$this->assertSame( [ 'first_utm_source' => 'google', 'gclid' => 'G1', 'lead_id' => self::LEAD ], $attr );
	}

	public function test_mail_contains_attribution_only_for_mapped_fields(): void {
		$lead = self::lead()->withAttribution( [ 'gclid' => 'G1', 'first_utm_source' => 'google', 'last_utm_source' => 'facebook' ] );
		$html = ( new InquiryMailFormatter() )->format( $lead, [ 'first_utm_source' => 'first_source', 'gclid' => 'gclid' ] );
		$this->assertStringContainsString( '<span id="client_cf_first_source">google</span>', $html );
		$this->assertStringContainsString( '<span id="client_cf_gclid">G1</span>', $html );
		$this->assertStringNotContainsString( 'facebook', $html, 'nicht zugeordnet → nicht senden' );
		$none = ( new InquiryMailFormatter() )->format( $lead, [] );
		$this->assertStringNotContainsString( 'client_cf_', $none, 'ohne Zuordnung keine Attribution' );
	}

	public function test_lead_event_payload_contains_no_personal_data(): void {
		$payload = LeadEvent::payload( self::lead() );
		$this->assertSame( [ 'lead_id', 'property_id', 'marketing_type', 'property_type', 'property_city', 'lead_type' ], array_keys( $payload ) );
		$this->assertSame( self::LEAD, $payload['lead_id'] );
		$this->assertSame( 'property_inquiry', $payload['lead_type'] );
		$json = (string) json_encode( $payload, JSON_UNESCAPED_UNICODE );
		foreach ( [ 'Max', 'Muster', 'max@example.com', '+49', 'Hallo', 'Versteckweg', '52.5', 'example.test' ] as $pii ) {
			$this->assertStringNotContainsString( $pii, $json, $pii );
		}
	}

	public function test_consent_default_is_no_marketing(): void {
		$this->assertFalse( ConsentProviders::get( 'none' )->allowsMarketing() );
		$this->assertFalse( ConsentProviders::get( 'gibt-es-nicht' )->allowsMarketing(), 'unbekannt → none' );
		$this->assertTrue( ConsentProviders::get( 'js_api' )->allowsMarketing() );
		$this->assertSame( [ 'type' => 'api' ], ConsentProviders::get( 'js_api' )->clientConfig() );
	}
}
