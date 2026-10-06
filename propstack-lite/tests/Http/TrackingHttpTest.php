<?php

namespace PropstackLite\Tests\Http;

use PropstackLite\Leads\Cf7Integration;
use PropstackLite\Leads\RateLimiter;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Routing\UrlGenerator;
use PropstackLite\Tests\Integration\IntegrationTestCase;
use PropstackLite\Tracking\TrackingIntegration;

/**
 * Phase 6 über echte HTTP-Requests mit echtem Contact Form 7 (Mail-Capture, nichts wird versendet):
 * Skript-Einbindung, Consent-Gating, Attribution in der Propstack-Mail (nur mit Zuordnung),
 * Event-Daten `psl_lead` nur nach erfolgreichem Versand des konfigurierten Formulars.
 * Clientseitiges Verhalten (Cookie, dataLayer, Deduplizierung): tests/js und Browser-Test.
 */
final class TrackingHttpTest extends IntegrationTestCase {

	private const PUBLIC = 731;
	private const SOLD   = 733;

	private const ID_ACTIVE = 990000301;
	private const ID_SOLD   = 990000303;

	private const INBOX = 'propstack-inbox@example.test';
	private const GCLID = 'GCLIDTEST_synthetisch_123';
	private const UUID  = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	private const FULL_MAP = [
		'lead_id'            => 'website_lead_id',
		'first_utm_source'   => 'first_source',
		'first_utm_medium'   => 'first_medium',
		'first_utm_campaign' => 'first_campaign',
		'last_utm_source'    => 'last_source',
		'last_utm_medium'    => 'last_medium',
		'last_utm_campaign'  => 'last_campaign',
		'utm_content'        => 'utm_content',
		'utm_term'           => 'utm_term',
		'gclid'              => 'gclid',
		'gbraid'             => 'gbraid',
		'wbraid'             => 'wbraid',
		'landing_path'       => 'landing_path',
		'referrer_host'      => 'referrer_host',
	];

	private string $base;
	private int $formId  = 0;
	private int $otherId = 0;

	protected function setUp(): void {
		$base = getenv( 'PSL_TEST_BASE_URL' );
		if ( ! is_string( $base ) || '' === $base ) {
			$this->markTestSkipped( 'PSL_TEST_BASE_URL nicht gesetzt.' );
		}
		parent::setUp();
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			$this->markTestSkipped( 'Contact Form 7 ist in der Testinstanz nicht aktiv.' );
		}
		$this->base = rtrim( $base, '/' );
		$this->createForms();
		$this->resetRateLimit();
		update_option( 'psl_test_mail_capture', 1 );
		delete_option( 'psl_test_mail_fail' );
		@unlink( WP_CONTENT_DIR . '/psl-mail-capture.jsonl' );
	}

	protected function tearDown(): void {
		foreach ( [ $this->formId, $this->otherId ] as $id ) {
			if ( $id > 0 ) {
				wp_delete_post( $id, true );
			}
		}
		delete_option( 'psl_test_mail_capture' );
		delete_option( 'psl_test_mail_fail' );
		$this->resetRateLimit();
		parent::tearDown();
	}

	/* ---------------------------------------------------------------- Setup */

	private function createForms(): void {
		$make = static function ( string $title, string $form ): int {
			$f = \WPCF7_ContactForm::get_template( [ 'title' => $title ] );
			$f->set_properties(
				[
					'form' => $form,
					'mail' => [ 'active' => true, 'subject' => 'Test', 'sender' => 'PSL Test <wordpress@example.test>', 'recipient' => 'office@example.test', 'body' => '[your-message]', 'additional_headers' => 'Reply-To: [your-email]', 'attachments' => '', 'use_html' => false, 'exclude_blank' => false ],
				]
			);
			$f->save();
			return (int) $f->id();
		};
		$this->formId  = $make( 'PSL Tracking Test Immobilienanfrage', "[text* your-first-name] [text* your-last-name] [email* your-email] [tel your-phone] [textarea your-message] [acceptance psl-consent] Zustimmung [/acceptance] [submit \"Senden\"]" );
		$this->otherId = $make( 'PSL Tracking Test Kontakt', "[text* your-name] [email* your-email] [textarea your-message] [submit \"Senden\"]" );
	}

	/** Einstellungen + Testobjekte (aktiv, verkauft). */
	private function seed( array $tracking ): void {
		$this->settings(
			array_merge(
				[
					'public_status_ids'    => [ self::PUBLIC ],
					'sold_status_ids'      => [ self::SOLD ],
					'cf7_form_id'          => $this->formId,
					'inquiry_email'        => self::INBOX,
					'field_map'            => [],
					'cf_map'               => [],
					'tracking_attribution' => false,
					'tracking_datalayer'   => false,
					'consent_provider'     => 'none',
					'attribution_ttl_days' => 90,
				],
				$tracking
			)
		);
		$mapper = new PropertyMapper();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$this->store->upsertActive( $mapper->map( self::raw( self::ID_ACTIVE, self::PUBLIC, [ 'title' => [ 'label' => 'T', 'value' => 'Tracking-Testwohnung' ], 'hide_address' => true, 'city' => 'Potsdam' ] ) ), $now );
		$this->store->upsertActive( $mapper->map( self::raw( self::ID_SOLD, self::PUBLIC, [ 'title' => [ 'label' => 'T', 'value' => 'Verkauft' ] ] ) ), $now );
		$this->store->markSold( self::ID_SOLD, self::SOLD, $now );
	}

	private const ON = [ 'tracking_attribution' => true, 'tracking_datalayer' => true, 'consent_provider' => 'js_api' ];

	private function resetRateLimit(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_psl\_rl\_%' OR option_name LIKE '\_transient\_timeout\_psl\_rl\_%'" );
	}

	/* --------------------------------------------------------------- Helfer */

	private function url( int $id ): string {
		return ( new UrlGenerator() )->canonicalUrl( $this->store->find( $id ) );
	}

	private function http( string $url, ?array $post = null ): array {
		$ch  = curl_init( $url );
		$opt = [ CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'Mozilla/5.0 (PSL Tracking Test)' ];
		if ( null !== $post ) {
			$opt[ CURLOPT_POST ]       = true;
			$opt[ CURLOPT_POSTFIELDS ] = $post;
		}
		curl_setopt_array( $ch, $opt );
		$body = (string) curl_exec( $ch );
		return [ 'status' => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ), 'body' => $body ];
	}

	/** @return array<string, string> */
	private function hiddenFields( string $html, int $formId ): array {
		$doc = new \DOMDocument();
		@$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		foreach ( $doc->getElementsByTagName( 'form' ) as $form ) {
			$values = [];
			foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
				if ( 'hidden' === $input->getAttribute( 'type' ) ) {
					$values[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
				}
			}
			if ( (int) ( $values['_wpcf7'] ?? 0 ) === $formId ) {
				return $values;
			}
		}
		return [];
	}

	private static function fields( array $override = [] ): array {
		return array_merge(
			[ 'your-first-name' => 'Max', 'your-last-name' => 'Mustermann', 'your-email' => 'max.mustermann@example.com', 'your-phone' => '+49 30 1234567', 'your-message' => 'Bitte Infos.', 'psl-consent' => '1' ],
			$override
		);
	}

	/** Attribution wie vom Formular-Skript bei Consent gesetzt (synthetische Werte). */
	private static function attribution( array $firstOverride = [] ): string {
		$now = time();
		return (string) json_encode(
			[
				'v' => 1,
				'f' => array_merge( [ 's' => 'google', 'm' => 'cpc', 'c' => 'wohnung_berlin', 'ch' => 'paid_search', 'g' => self::GCLID, 'lp' => '/immobilien/', 'rh' => 'google.de', 'ts' => $now - 86400 ], $firstOverride ),
				'l' => [ 's' => 'facebook', 'm' => 'paid_social', 'c' => 'retargeting', 'ct' => 'video_a', 't' => 'wohnung', 'ch' => 'paid_social', 'ts' => $now - 60 ],
			]
		);
	}

	/** @return array{response: array, hidden: array<string, string>} */
	private function submit( array $fields = [], array $hiddenOverride = [], int $propertyId = self::ID_ACTIVE ): array {
		$page   = $this->http( $this->url( $propertyId ) );
		$hidden = $this->hiddenFields( $page['body'], $this->formId );
		$this->assertNotEmpty( $hidden, 'Formular auf der Detailseite' );
		$r = $this->http( $this->base . '/wp-json/contact-form-7/v1/contact-forms/' . $this->formId . '/feedback', array_merge( $hidden, $hiddenOverride, self::fields( $fields ) ) );
		return [ 'response' => (array) json_decode( $r['body'], true ), 'hidden' => $hidden ];
	}

	/** @return list<array{message: string}> */
	private function mails(): array {
		$file = WP_CONTENT_DIR . '/psl-mail-capture.jsonl';
		return file_exists( $file ) ? array_map( static fn ( $l ) => json_decode( $l, true ), array_filter( explode( "\n", (string) file_get_contents( $file ) ) ) ) : [];
	}

	/* ---------------------------------------------------------------- Tests */

	public function test_default_off_loads_nothing_and_sends_no_event_data(): void {
		$this->seed( [] );
		foreach ( [ $this->base . '/', $this->url( self::ID_ACTIVE ) ] as $url ) {
			$body = $this->http( $url )['body'];
			$this->assertStringNotContainsString( 'psl-tracking.js', $body, $url );
			$this->assertStringNotContainsString( 'psl-lead-event.js', $body, $url );
			$this->assertStringNotContainsString( 'pslTrackingConfig', $body, $url );
		}
		$r = $this->submit( [], [ TrackingIntegration::FIELD => self::attribution() ] );
		$this->assertSame( 'mail_sent', $r['response']['status'] ?? null, 'Anfrage funktioniert ohne Tracking' );
		$this->assertArrayNotHasKey( 'psl_lead', $r['response'] );
		$this->assertArrayNotHasKey( TrackingIntegration::FIELD, $r['hidden'], 'kein Attribution-Feld' );
	}

	public function test_no_consent_provider_ignores_marketing_data(): void {
		$this->seed( [ 'tracking_attribution' => true, 'tracking_datalayer' => true, 'consent_provider' => 'none', 'cf_map' => self::FULL_MAP ] );
		$this->assertStringNotContainsString( 'psl-tracking.js', $this->http( $this->url( self::ID_ACTIVE ) )['body'], 'ohne Consent-System keine Skripte' );
		$r = $this->submit( [], [ TrackingIntegration::FIELD => self::attribution() ] );
		$this->assertSame( 'mail_sent', $r['response']['status'] ?? null );
		$this->assertArrayNotHasKey( 'psl_lead', $r['response'], 'kein Event ohne Consent-System' );
		$mail = $this->mails()[0]['message'];
		$this->assertStringContainsString( 'client_cf_website_lead_id', $mail, 'Lead-ID ist kein Marketingwert' );
		foreach ( [ 'client_cf_first_source', 'client_cf_gclid', self::GCLID, 'facebook' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $mail, $absent );
		}
	}

	public function test_scripts_sitewide_and_lead_script_only_with_form(): void {
		$this->seed( self::ON );
		$home = $this->http( $this->base . '/' )['body'];
		$this->assertStringContainsString( 'psl-tracking.js', $home, 'Attribution seitenweit (Landingpages)' );
		$this->assertStringNotContainsString( 'psl-lead-event.js', $home );
		$this->assertStringContainsString( 'window.pslTrackingConfig = {"cookie":"psl_attr","ttlDays":90,"path":"\/Picaflor\/","secure":false,"attribution":true,"consent":{"type":"api"}};', $home );
		$this->assertMatchesRegularExpression( '#<script[^>]*\bdefer\b[^>]*psl-tracking\.js#', $home, 'defer' );

		$detail = $this->http( $this->url( self::ID_ACTIVE ) )['body'];
		$this->assertStringContainsString( 'psl-lead-event.js', $detail );
		$this->assertStringContainsString( 'window.pslLeadConfig = {"formId":' . $this->formId . ',"attribution":true,"dataLayer":true,"dataLayerName":"dataLayer"};', $detail );
		$this->assertArrayHasKey( TrackingIntegration::FIELD, $this->hiddenFields( $detail, $this->formId ), 'Attribution-Feld im Immobilienformular' );
		$this->assertSame( '', $this->hiddenFields( $detail, $this->formId )[ TrackingIntegration::FIELD ], 'leer – nur das Skript füllt es bei Consent' );

		$sold = $this->http( $this->url( self::ID_SOLD ) )['body'];
		$this->assertStringContainsString( 'psl-tracking.js', $sold );
		$this->assertStringNotContainsString( 'psl-lead-event.js', $sold, 'verkauft: kein Formular, kein Lead-Skript' );
		foreach ( [ 'googletagmanager.com', 'google-analytics.com', 'gtag(', 'fbq(', 'connect.facebook.net' ] as $external ) {
			$this->assertStringNotContainsString( $external, $detail . $home, 'Plugin lädt keine Tags: ' . $external );
		}
	}

	public function test_mapping_active_transfers_attribution_to_propstack_block(): void {
		$this->seed( self::ON + [ 'cf_map' => self::FULL_MAP ] );
		$r = $this->submit( [], [ TrackingIntegration::FIELD => self::attribution() ] );
		$this->assertSame( 'mail_sent', $r['response']['status'] ?? null, json_encode( $r['response'] ) );
		$mail = $this->mails()[0]['message'];
		$leadId = $r['hidden'][ Cf7Integration::FIELD_LEAD ];
		$expect = [
			'website_lead_id' => $leadId, 'first_source' => 'google', 'first_medium' => 'cpc', 'first_campaign' => 'wohnung_berlin',
			'last_source' => 'facebook', 'last_medium' => 'paid_social', 'last_campaign' => 'retargeting', 'utm_content' => 'video_a',
			'utm_term' => 'wohnung', 'gclid' => self::GCLID, 'landing_path' => '/immobilien/', 'referrer_host' => 'google.de',
		];
		foreach ( $expect as $field => $value ) {
			$this->assertStringContainsString( '<span id="client_cf_' . $field . '">' . $value . '</span>', $mail, $field );
		}

		$event = $r['response']['psl_lead'] ?? null;
		$this->assertSame( [ 'lead_id', 'property_id', 'marketing_type', 'property_type', 'property_city', 'lead_type' ], array_keys( (array) $event ) );
		$this->assertSame( $leadId, $event['lead_id'], 'dieselbe Lead-ID wie im Formular und in der Mail' );
		$this->assertSame( self::ID_ACTIVE, $event['property_id'] );
		$this->assertSame( [ 'BUY', 'APARTMENT', 'Potsdam', 'property_inquiry' ], [ $event['marketing_type'], $event['property_type'], $event['property_city'], $event['lead_type'] ] );
		$json = (string) json_encode( $r['response'] );
		foreach ( [ 'Mustermann', 'max.mustermann', '1234567', 'Teststra', self::GCLID ] as $pii ) {
			$this->assertStringNotContainsString( $pii, $json, 'keine PII/Klick-ID in der Antwort: ' . $pii );
		}
	}

	public function test_mapping_inactive_sends_no_attribution(): void {
		$this->seed( self::ON );
		$r = $this->submit( [], [ TrackingIntegration::FIELD => self::attribution() ] );
		$this->assertSame( 'mail_sent', $r['response']['status'] ?? null );
		$this->assertStringNotContainsString( 'client_cf_', $this->mails()[0]['message'] );
		$this->assertStringNotContainsString( self::GCLID, $this->mails()[0]['message'] );
	}

	public function test_manipulated_attribution_is_sanitized_or_ignored(): void {
		$this->seed( self::ON + [ 'cf_map' => self::FULL_MAP ] );
		$r = $this->submit( [], [ TrackingIntegration::FIELD => self::attribution( [ 's' => '<script>alert(1)</script>', 'c' => '"><img src=x onerror=alert(2)>', 'g' => 'bad<id', 'lp' => '/x?email=a@b.c', 'rh' => 'https://evil.example/pfad' ] ) ] );
		$this->assertSame( 'mail_sent', $r['response']['status'] ?? null );
		$mail = $this->mails()[0]['message'];
		$this->assertStringContainsString( '<span id="client_cf_first_source">scriptalert(1)/script</span>', $mail );
		foreach ( [ '<script', 'onerror=alert(2)>', 'bad<id', 'email=a@b.c', 'evil.example' ] as $bad ) {
			$this->assertStringNotContainsString( $bad, $mail, $bad );
		}
		@unlink( WP_CONTENT_DIR . '/psl-mail-capture.jsonl' );
		$r = $this->submit( [], [ TrackingIntegration::FIELD => '{kaputt' ] );
		$this->assertSame( 'mail_sent', $r['response']['status'] ?? null, 'ungültige Attribution blockiert die Anfrage nie' );
		$this->assertStringNotContainsString( 'client_cf_first', $this->mails()[0]['message'] );
	}

	public function test_event_data_only_after_successful_send_of_our_form(): void {
		$this->seed( self::ON );
		$cases = [];

		$r                  = $this->submit( [ 'psl-consent' => '' ] );
		$cases['Validierung (Zustimmung fehlt)'] = $r['response'];
		$r                  = $this->submit( [ 'your-email' => 'keine-mail' ] );
		$cases['Validierung (E-Mail)'] = $r['response'];
		$r                  = $this->submit( [], [ Cf7Integration::FIELD_HONEYPOT => 'http://spam.example' ] );
		$cases['Spam (Honeypot)'] = $r['response'];
		$r                  = $this->submit( [], [ Cf7Integration::FIELD_PROPERTY => (string) self::ID_SOLD ] );
		$cases['manipulierte ID (verkauft)'] = $r['response'];

		update_option( 'psl_test_mail_fail', 1 );
		$r = $this->submit();
		$cases['Mailfehler'] = $r['response'];
		delete_option( 'psl_test_mail_fail' );

		$limiter = new RateLimiter( wp_salt( 'nonce' ) );
		for ( $i = 0; $i < 10; $i++ ) {
			$limiter->attempt( '127.0.0.1' );
		}
		$r = $this->submit();
		$cases['Rate-Limit'] = $r['response'];
		$this->resetRateLimit();

		$other = $this->http( $this->base . '/wp-json/contact-form-7/v1/contact-forms/' . $this->otherId . '/feedback', [ '_wpcf7' => (string) $this->otherId, '_wpcf7_unit_tag' => 'wpcf7-f' . $this->otherId . '-o1', 'your-name' => 'Erika', 'your-email' => 'erika@example.com', 'your-message' => 'Hallo' ] );
		$cases['anderes CF7-Formular'] = (array) json_decode( $other['body'], true );

		foreach ( $cases as $label => $response ) {
			$this->assertArrayNotHasKey( 'psl_lead', $response, $label . ': ' . json_encode( $response ) );
			if ( 'anderes CF7-Formular' !== $label ) {
				$this->assertNotSame( 'mail_sent', $response['status'] ?? null, $label );
			}
		}

		$ok = $this->submit();
		$this->assertSame( 'mail_sent', $ok['response']['status'] ?? null );
		$this->assertMatchesRegularExpression( self::UUID, $ok['response']['psl_lead']['lead_id'] ?? '', 'Erfolg → Event-Daten' );
	}

	public function test_datalayer_off_means_no_event_data(): void {
		$this->seed( [ 'tracking_attribution' => true, 'tracking_datalayer' => false, 'consent_provider' => 'js_api' ] );
		$r = $this->submit();
		$this->assertSame( 'mail_sent', $r['response']['status'] ?? null );
		$this->assertArrayNotHasKey( 'psl_lead', $r['response'] );
		$this->assertStringContainsString( '"dataLayer":false', $this->http( $this->url( self::ID_ACTIVE ) )['body'] );
	}

	public function test_reused_lead_id_gets_new_server_id_for_event(): void {
		$this->seed( self::ON );
		$first  = $this->submit();
		$leadId = $first['hidden'][ Cf7Integration::FIELD_LEAD ];
		$this->assertSame( $leadId, $first['response']['psl_lead']['lead_id'] );
		// gleiche Seite erneut abgesendet (z. B. Full-Page-Cache/zweite Anfrage) → neue serverseitige ID
		$second = $this->submit( [], [ Cf7Integration::FIELD_LEAD => $leadId ] );
		$this->assertNotSame( $leadId, $second['response']['psl_lead']['lead_id'] ?? $leadId );
	}

	public function test_no_marketing_ids_or_attribution_in_logs(): void {
		$this->seed( self::ON + [ 'cf_map' => self::FULL_MAP ] );
		$log    = WP_CONTENT_DIR . '/debug.log';
		$before = file_exists( $log ) ? filesize( $log ) : 0;
		$this->submit( [], [ TrackingIntegration::FIELD => self::attribution() ] );
		$new = file_exists( $log ) ? (string) file_get_contents( $log, false, null, $before ) : '';
		foreach ( [ self::GCLID, 'wohnung_berlin', 'retargeting', 'google.de', 'Mustermann' ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $new, $secret );
		}
	}
}
