<?php

namespace PropstackLite\Tests\Http;

use PropstackLite\Leads\Cf7Integration;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Routing\UrlGenerator;
use PropstackLite\Tests\Integration\IntegrationTestCase;

/**
 * Phase 4: Immobilienanfragen mit echtem Contact Form 7 über HTTP (REST-Feedback-Endpunkt von CF7).
 *
 * Voraussetzungen: PSL_WP_LOAD, PSL_TEST_BASE_URL, Contact Form 7 aktiv in der Testinstanz,
 * mu-plugins aus tests/Support/mu-plugins. Mails werden per pre_wp_mail abgefangen (nichts wird versendet).
 * Nur synthetische Testdaten.
 */
final class InquiryTest extends IntegrationTestCase {

	private const PUBLIC   = 721;
	private const RESERVED = 722;
	private const SOLD     = 723;

	private const ID_ACTIVE     = 990000201;
	private const ID_RESERVED   = 990000202;
	private const ID_SOLD       = 990000203;
	private const ID_REMOVED    = 990000204;
	private const ID_NOT_PUBLIC = 990000205;
	private const ID_UNKNOWN    = 990000299;

	private const INBOX = 'propstack-inbox@example.test';

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
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$this->base = rtrim( $base, '/' );
		$this->createForms();
		$this->seed();
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
		if ( ! is_plugin_active( 'contact-form-7/wp-contact-form-7.php' ) && file_exists( WP_PLUGIN_DIR . '/contact-form-7/wp-contact-form-7.php' ) ) {
			activate_plugin( 'contact-form-7/wp-contact-form-7.php' );
		}
		parent::tearDown();
	}

	/* ---------------------------------------------------------------- Setup */

	private function createForms(): void {
		$form = \WPCF7_ContactForm::get_template( [ 'title' => 'PSL Test Immobilienanfrage' ] );
		$form->set_properties(
			[
				'form' => "[text* your-first-name] [text* your-last-name] [email* your-email] [tel your-phone] [textarea your-message] [acceptance psl-consent] Ich stimme der Verarbeitung zu. [/acceptance] [submit \"Senden\"]",
				'mail' => [
					'active'             => true,
					'subject'            => 'Immobilienanfrage',
					'sender'             => 'PSL Test <wordpress@example.test>',
					'recipient'          => 'should-be-overridden@example.test',
					'body'               => 'Anfrage von [your-first-name] [your-last-name]',
					'additional_headers' => 'Reply-To: [your-email]',
					'attachments'        => '',
					'use_html'           => false,
					'exclude_blank'      => false,
				],
			]
		);
		$form->save();
		$this->formId = (int) $form->id();

		$other = \WPCF7_ContactForm::get_template( [ 'title' => 'PSL Test Allgemeiner Kontakt' ] );
		$other->set_properties(
			[
				'form' => "[text* your-name] [email* your-email] [textarea your-message] [submit \"Senden\"]",
				'mail' => [
					'active'             => true,
					'subject'            => 'Allgemeine Anfrage',
					'sender'             => 'PSL Test <wordpress@example.test>',
					'recipient'          => 'office@example.test',
					'body'               => '[your-message]',
					'additional_headers' => '',
					'attachments'        => '',
					'use_html'           => false,
					'exclude_blank'      => false,
				],
			]
		);
		$other->save();
		$this->otherId = (int) $other->id();
	}

	private function seed( array $overrides = [] ): void {
		$this->settings(
			array_merge(
				[
					'public_status_ids'   => [ self::PUBLIC, self::RESERVED ],
					'reserved_status_ids' => [ self::RESERVED ],
					'sold_status_ids'     => [ self::SOLD ],
					'cf7_form_id'         => $this->formId,
					'inquiry_email'       => self::INBOX,
					'inquiry_bcc'         => '',
					'field_map'           => [],
					'cf_map'              => [],
				],
				$overrides
			)
		);
		$mapper = new PropertyMapper();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$raw    = static fn ( int $id, int $status, string $title ) => self::raw( $id, $status, [ 'title' => [ 'label' => 'T', 'value' => $title ], 'hide_address' => true ] );

		$this->store->upsertActive( $mapper->map( $raw( self::ID_ACTIVE, self::PUBLIC, 'Testwohnung Anfrage' ) ), $now );
		$this->store->upsertActive( $mapper->map( $raw( self::ID_RESERVED, self::RESERVED, 'Reserviertes Testhaus' ) ), $now );
		$this->store->upsertActive( $mapper->map( $raw( self::ID_SOLD, self::PUBLIC, 'Verkauftes Testobjekt' ) ), $now );
		$this->store->markSold( self::ID_SOLD, self::SOLD, $now );
		$this->store->upsertActive( $mapper->map( $raw( self::ID_REMOVED, self::PUBLIC, 'Entferntes Testobjekt' ) ), $now );
		$this->store->markRemoved( self::ID_REMOVED, null, $now );
		$this->store->upsertActive( $mapper->map( $raw( self::ID_NOT_PUBLIC, 799, 'Nicht öffentlich' ) ), $now );
	}

	private function resetRateLimit(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_psl\_rl\_%' OR option_name LIKE '\_transient\_timeout\_psl\_rl\_%'" );
	}

	/* --------------------------------------------------------------- Helfer */

	private function url( int $id ): string {
		return ( new UrlGenerator() )->canonicalUrl( $this->store->find( $id ) );
	}

	private function http( string $url, ?array $post = null ): array {
		$ch = curl_init( $url );
		// CF7 wertet Anfragen ohne (bzw. mit sehr kurzem) User-Agent als Spam – wie ein Browser senden.
		$opt = [ CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_USERAGENT => 'Mozilla/5.0 (PSL Inquiry Test)' ];
		if ( null !== $post ) {
			$opt[ CURLOPT_POST ]       = true;
			$opt[ CURLOPT_POSTFIELDS ] = $post; // Array → multipart/form-data (von CF7 verlangt)
		}
		curl_setopt_array( $ch, $opt );
		$body = (string) curl_exec( $ch );
		return [ 'status' => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ), 'body' => $body ];
	}

	/** Hidden Fields des CF7-Formulars mit der gegebenen ID aus einer Seite. @return array<string, string> */
	private function hiddenFields( string $html, int $formId ): array {
		$doc = new \DOMDocument();
		@$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		$fields = [];
		foreach ( $doc->getElementsByTagName( 'form' ) as $form ) {
			$values = [];
			foreach ( $form->getElementsByTagName( 'input' ) as $input ) {
				if ( 'hidden' === $input->getAttribute( 'type' ) ) {
					$values[ $input->getAttribute( 'name' ) ] = $input->getAttribute( 'value' );
				}
			}
			if ( (int) ( $values['_wpcf7'] ?? 0 ) === $formId ) {
				$fields = $values;
			}
		}
		return $fields;
	}

	private function valid( array $override = [] ): array {
		return array_merge(
			[
				'your-first-name' => 'Max',
				'your-last-name'  => 'Mustermann',
				'your-email'      => 'max.mustermann@example.com',
				'your-phone'      => '+49 30 1234567',
				'your-message'    => "Guten Tag,\nbitte senden Sie mir weitere Informationen.",
				'psl-consent'     => '1',
			],
			$override
		);
	}

	/** Sendet das Immobilienformular von der Detailseite (Hidden Fields aus dem echten Markup). */
	private function submit( int $propertyPageId, array $fields = [], array $hiddenOverride = [] ): array {
		$page   = $this->http( $this->url( $propertyPageId ) );
		$hidden = $this->hiddenFields( $page['body'], $this->formId );
		$this->assertNotEmpty( $hidden, 'Formular auf der Seite gefunden' );
		return $this->post( $this->formId, array_merge( $hidden, $hiddenOverride, $this->valid( $fields ) ) );
	}

	private function post( int $formId, array $data ): array {
		$r = $this->http( $this->base . '/wp-json/contact-form-7/v1/contact-forms/' . $formId . '/feedback', $data );
		return (array) json_decode( $r['body'], true );
	}

	/** @return list<array{to: mixed, subject: string, message: string, headers: mixed}> */
	private function mails(): array {
		$file = WP_CONTENT_DIR . '/psl-mail-capture.jsonl';
		if ( ! file_exists( $file ) ) {
			return [];
		}
		return array_map( static fn ( $l ) => json_decode( $l, true ), array_filter( explode( "\n", (string) file_get_contents( $file ) ) ) );
	}

	private static function headers( array $mail ): string {
		return implode( "\n", (array) $mail['headers'] );
	}

	/* ---------------------------------------------------------------- Tests */

	public function test_form_only_on_inquirable_pages(): void {
		$active = $this->http( $this->url( self::ID_ACTIVE ) )['body'];
		$hidden = $this->hiddenFields( $active, $this->formId );
		$this->assertSame( (string) self::ID_ACTIVE, $hidden[ Cf7Integration::FIELD_PROPERTY ] ?? null );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $hidden[ Cf7Integration::FIELD_LEAD ] ?? '' );
		$this->assertStringContainsString( 'name="psl_hp_website"', $active, 'Honeypot' );
		$this->assertStringContainsString( 'Interesse an dieser Immobilie?', $active );

		$this->assertNotEmpty( $this->hiddenFields( $this->http( $this->url( self::ID_RESERVED ) )['body'], $this->formId ), 'reserviert: Formular' );

		$sold = $this->http( $this->url( self::ID_SOLD ) )['body'];
		$this->assertStringNotContainsString( 'wpcf7-form', $sold, 'verkauft: kein Formular' );
		$this->assertStringContainsString( 'Diese Immobilie ist bereits verkauft.', $sold );

		$gone = $this->http( $this->url( self::ID_REMOVED ) );
		$this->assertSame( 410, $gone['status'] );
		$this->assertStringNotContainsString( 'wpcf7-form', $gone['body'] );
	}

	public function test_valid_inquiry_sends_propstack_mail(): void {
		$r = $this->submit( self::ID_ACTIVE );
		$this->assertSame( 'mail_sent', $r['status'] ?? null, json_encode( $r ) );

		$mails = $this->mails();
		$this->assertCount( 1, $mails );
		$mail = $mails[0];
		$this->assertSame( self::INBOX, is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : $mail['to'], 'Empfänger aus den Einstellungen' );
		$headers = self::headers( $mail );
		$this->assertStringContainsString( 'Content-Type: text/html', $headers );
		$this->assertStringContainsString( 'Reply-To: max.mustermann@example.com', $headers );
		$this->assertStringNotContainsString( 'should-be-overridden', json_encode( $mail ) );

		$body = $mail['message'];
		$this->assertSame( 1, substr_count( $body, '<div id="ps-kontaktanfrage">' ) );
		foreach ( [
			'<span id="property_id">' . self::ID_ACTIVE . '</span>',
			'<span id="client_first_name">Max</span>',
			'<span id="client_last_name">Mustermann</span>',
			'<span id="client_email">max.mustermann@example.com</span>',
			'<span id="client_phone">+49 30 1234567</span>',
			'<span id="client_accept_contact">ja</span>',
			'<span id="client_locale">de</span>',
			'bitte senden Sie mir weitere Informationen.',
			$this->url( self::ID_ACTIVE ),
			'Testwohnung Anfrage',
			'Anfrage von Max Mustermann',
		] as $needle ) {
			$this->assertStringContainsString( $needle, $body, $needle );
		}
		foreach ( [ 'client_newsletter', 'client_property_mailing_wanted', 'client_cf_' ] as $absent ) {
			$this->assertStringNotContainsString( $absent, $body );
		}
	}

	public function test_reserved_property_is_inquirable(): void {
		$this->assertSame( 'mail_sent', $this->submit( self::ID_RESERVED )['status'] ?? null );
		$this->assertStringContainsString( '<span id="property_id">' . self::ID_RESERVED . '</span>', $this->mails()[0]['message'] );
	}

	public function test_manipulated_property_ids_are_rejected(): void {
		foreach ( [ self::ID_SOLD, self::ID_REMOVED, self::ID_NOT_PUBLIC, self::ID_UNKNOWN, 'abc', '', '-5' ] as $manipulated ) {
			$r = $this->submit( self::ID_ACTIVE, [], [ Cf7Integration::FIELD_PROPERTY => (string) $manipulated ] );
			$this->assertSame( 'aborted', $r['status'] ?? null, "Property-ID {$manipulated}" );
			$this->assertSame( Cf7Integration::VISITOR_ERROR, $r['message'] ?? null, 'neutrale Besuchermeldung' );
		}
		$this->assertSame( [], $this->mails(), 'keine Mail bei abgelehnten Anfragen' );
	}

	public function test_title_and_url_never_come_from_client(): void {
		$this->submit( self::ID_ACTIVE, [], [ 'psl_property_title' => 'GEFÄLSCHT', 'psl_property_url' => 'https://evil.example/' ] );
		$body = $this->mails()[0]['message'];
		$this->assertStringNotContainsString( 'GEFÄLSCHT', $body );
		$this->assertStringNotContainsString( 'evil.example', $body );
	}

	public function test_other_cf7_forms_are_not_touched(): void {
		$page   = $this->http( $this->base . '/' );
		$r      = $this->post(
			$this->otherId,
			[
				'_wpcf7'                       => (string) $this->otherId,
				'_wpcf7_unit_tag'              => 'wpcf7-f' . $this->otherId . '-o1',
				'your-name'                    => 'Erika Beispiel',
				'your-email'                   => 'erika@example.com',
				'your-message'                 => 'Allgemeine Frage',
				Cf7Integration::FIELD_PROPERTY => (string) self::ID_ACTIVE,
				Cf7Integration::FIELD_HONEYPOT => 'bot',
			]
		);
		$this->assertSame( 'mail_sent', $r['status'] ?? null, 'Honeypot/Rate-Limit/Prüfungen greifen nicht für fremde Formulare' );
		$mail = $this->mails()[0];
		$this->assertSame( 'office@example.test', is_array( $mail['to'] ) ? implode( ',', $mail['to'] ) : $mail['to'] );
		$this->assertStringNotContainsString( 'ps-kontaktanfrage', $mail['message'] );
		$this->assertStringNotContainsString( 'text/html', self::headers( $mail ) );
		$this->assertSame( 200, $page['status'] );
	}

	public function test_rate_limit(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertSame( 'mail_sent', $this->submit( self::ID_ACTIVE )['status'] ?? null, "Anfrage {$i}" );
		}
		$r = $this->submit( self::ID_ACTIVE );
		$this->assertSame( 'aborted', $r['status'] ?? null, '6. Anfrage innerhalb von 10 Minuten' );
		$this->assertCount( 5, $this->mails() );

		global $wpdb;
		$stored = (string) $wpdb->get_var( "SELECT GROUP_CONCAT(option_name, option_value) FROM {$wpdb->options} WHERE option_name LIKE '%psl\_rl\_%'" );
		$this->assertStringNotContainsString( '127.0.0.1', $stored, 'keine Klartext-IP gespeichert' );
	}

	public function test_missing_configuration_hides_form_and_rejects_posts(): void {
		$this->seed( [ 'inquiry_email' => '' ] );
		$page = $this->http( $this->url( self::ID_ACTIVE ) )['body'];
		$this->assertStringNotContainsString( 'wpcf7-form', $page );
		$this->assertStringContainsString( 'Bitte kontaktieren Sie uns telefonisch oder per E-Mail', $page );

		$r = $this->post( $this->formId, array_merge( [ '_wpcf7' => (string) $this->formId, '_wpcf7_unit_tag' => 'wpcf7-f' . $this->formId . '-o1', Cf7Integration::FIELD_PROPERTY => (string) self::ID_ACTIVE ], $this->valid() ) );
		$this->assertSame( 'aborted', $r['status'] ?? null );
		$this->assertSame( [], $this->mails() );
	}

	public function test_consent_required_and_honeypot(): void {
		$r = $this->submit( self::ID_ACTIVE, [ 'psl-consent' => '' ] );
		$this->assertContains( $r['status'] ?? null, [ 'acceptance_missing', 'validation_failed' ] );

		$r = $this->submit( self::ID_ACTIVE, [ Cf7Integration::FIELD_HONEYPOT => 'https://spam.example' ] );
		$this->assertSame( 'spam', $r['status'] ?? null );
		$this->assertSame( [], $this->mails() );
	}

	public function test_xss_and_header_injection(): void {
		$r = $this->submit(
			self::ID_ACTIVE,
			[
				'your-first-name' => "Max\r\nBcc: evil@example.com",
				'your-last-name'  => '<script>alert(1)</script>Muster',
				'your-message'    => "<img src=x onerror=alert(2)> [_site_admin_email] </span></div><div id=\"ps-kontaktanfrage\">",
			]
		);
		$this->assertSame( 'mail_sent', $r['status'] ?? null );
		$mail = $this->mails()[0];
		$this->assertStringNotContainsString( 'evil@example.com', self::headers( $mail ), 'kein eingeschleuster Header' );
		$this->assertStringNotContainsString( '<script', $mail['message'] );
		$this->assertStringNotContainsString( '<img', $mail['message'] );
		$this->assertSame( 1, substr_count( $mail['message'], 'id="ps-kontaktanfrage"' ) );
		$this->assertStringNotContainsString( get_option( 'admin_email' ), $mail['message'], 'keine CF7-Spezialtags aus Benutzereingaben' );

		$bad = $this->submit( self::ID_ACTIVE, [ 'your-email' => "max@example.com\nBcc: evil@example.com" ] );
		$this->assertNotSame( 'mail_sent', $bad['status'] ?? null );
	}

	public function test_lead_id_custom_field_and_server_side_uniqueness(): void {
		$this->seed( [ 'cf_map' => [ 'lead_id' => 'website_lead_id' ] ] );
		$page   = $this->http( $this->url( self::ID_ACTIVE ) )['body'];
		$hidden = $this->hiddenFields( $page, $this->formId );
		$leadId = $hidden[ Cf7Integration::FIELD_LEAD ];

		$this->post( $this->formId, array_merge( $hidden, $this->valid() ) );
		$this->post( $this->formId, array_merge( $hidden, $this->valid() ) ); // gleiche Lead-ID erneut (z. B. gecachte Seite)
		$mails = $this->mails();
		$this->assertCount( 2, $mails );
		$this->assertStringContainsString( '<span id="client_cf_website_lead_id">' . $leadId . '</span>', $mails[0]['message'] );
		$this->assertStringNotContainsString( $leadId, $mails[1]['message'], 'bereits verwendete Lead-ID wird serverseitig ersetzt' );
		$this->assertMatchesRegularExpression( '/client_cf_website_lead_id">[0-9a-f-]{36}</', $mails[1]['message'] );
	}

	public function test_mail_failure_is_reported_neutrally(): void {
		update_option( 'psl_test_mail_fail', 1 );
		$r = $this->submit( self::ID_ACTIVE );
		$this->assertSame( 'mail_failed', $r['status'] ?? null );
	}

	public function test_no_lead_data_persisted_and_logs_without_pii(): void {
		$log = WP_CONTENT_DIR . '/debug.log';
		file_put_contents( $log, '' );
		$this->submit( self::ID_ACTIVE, [ 'your-email' => 'persist.check@example.com', 'your-last-name' => 'Persistenzpruefung' ] );

		global $wpdb;
		$hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT (SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s) + (SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s OR post_title LIKE %s) + (SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s)", '%persist.check%', '%persist.check%', '%Persistenzpruefung%', '%persist.check%' ) );
		$this->assertSame( 0, $hits, 'keine Anfrageinhalte in der Datenbank' );

		$lines = array_filter( explode( "\n", (string) file_get_contents( $log ) ), static fn ( $l ) => str_contains( $l, '[propstack-lite]' ) && str_contains( $l, 'lead' ) );
		$this->assertNotEmpty( $lines );
		$joined = implode( "\n", $lines );
		$this->assertStringContainsString( '"status":"sent"', $joined );
		$this->assertStringContainsString( '"property":' . self::ID_ACTIVE, $joined );
		foreach ( [ 'persist.check', 'Persistenzpruefung', 'Max', '1234567', 'Informationen', '127.0.0.1' ] as $pii ) {
			$this->assertStringNotContainsString( $pii, $joined, "PII im Log: {$pii}" );
		}
	}

	public function test_no_propstack_requests_during_inquiry(): void {
		if ( ! file_exists( WP_CONTENT_DIR . '/mu-plugins/psl-http-spy.php' ) ) {
			$this->markTestSkipped( 'mu-plugin psl-http-spy.php nicht installiert.' );
		}
		$spy = WP_CONTENT_DIR . '/psl-http-spy.log';
		file_put_contents( $spy, '' );
		$this->submit( self::ID_ACTIVE );
		$this->assertStringNotContainsString( 'propstack', (string) file_get_contents( $spy ) );
	}

	public function test_without_cf7_the_page_still_renders(): void {
		deactivate_plugins( 'contact-form-7/wp-contact-form-7.php', true );
		$r = $this->http( $this->url( self::ID_ACTIVE ) );
		$this->assertSame( 200, $r['status'] );
		$this->assertStringContainsString( 'Interesse an dieser Immobilie?', $r['body'] );
		$this->assertStringContainsString( 'Bitte kontaktieren Sie uns telefonisch oder per E-Mail', $r['body'] );
		$this->assertStringNotContainsString( 'wpcf7', $r['body'] );
		$this->assertStringNotContainsString( 'Fatal error', $r['body'] );
		$this->assertStringNotContainsString( 'Warning', $r['body'] );
	}
}
