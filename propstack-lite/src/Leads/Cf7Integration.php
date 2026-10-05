<?php

namespace PropstackLite\Leads;

use PropstackLite\Frontend\DetailController;
use PropstackLite\Routing\RouteResolver;
use PropstackLite\Routing\UrlGenerator;
use PropstackLite\Settings;
use PropstackLite\Storage\PropertyStore;
use PropstackLite\Support\Clock;
use PropstackLite\Support\Logger;

/**
 * Contact-Form-7-Integration für Immobilienanfragen (nur geladen, wenn CF7 verfügbar ist).
 *
 * Jeder Hook prüft zuerst, ob es sich um das in den Einstellungen konfigurierte Formular handelt;
 * andere CF7-Formulare (Kontakt, Karriere, …) bleiben vollständig unverändert.
 *
 * Offizielle CF7-APIs/Hooks:
 *  - Shortcode [contact-form-7 id="…"]            Formular auf der Detailseite (Hook psl_property_contact)
 *  - wpcf7_form_hidden_fields                     psl_property_id, psl_lead_id
 *  - wpcf7_form_elements                          Honeypot-Feld
 *  - wpcf7_spam                                   Honeypot-Prüfung (CF7-eigene Spamprüfung bleibt vorrangig)
 *  - wpcf7_before_send_mail ($abort)              Property-Verifikation, Rate-Limit, Mail-Vorbereitung
 *  - wpcf7_special_mail_tags                      [_psl_propstack_block]
 *  - wpcf7_mail_sent / wpcf7_mail_failed          Status-Logging, Action psl_lead_sent
 *  - WPCF7_Submission::get_posted_data(), get_meta(), get_contact_form(), set_response()
 */
final class Cf7Integration {

	public const FIELD_PROPERTY = 'psl_property_id';
	public const FIELD_LEAD     = 'psl_lead_id';
	public const FIELD_HONEYPOT = 'psl_hp_website';

	public const VISITOR_ERROR = 'Die Anfrage konnte leider nicht versendet werden. Bitte versuchen Sie es später erneut.';

	private const USED_LEAD_PREFIX = 'psl_lead_used_';
	private const UUID_PATTERN     = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

	private ?Cf7MailLeadSink $sink = null;
	private ?LeadContext $lead     = null;

	public function __construct(
		private Settings $settings,
		private PropertyStore $store,
		private UrlGenerator $urls,
		private Clock $clock,
		private Logger $logger,
		private InquiryMailFormatter $formatter,
		private RateLimiter $rateLimiter
	) {}

	public static function isAvailable(): bool {
		return class_exists( 'WPCF7_ContactForm' ) && class_exists( 'WPCF7_Submission' ) && function_exists( 'wpcf7_contact_form' );
	}

	public function register(): void {
		add_filter( 'psl_inquiry_form_available', [ $this, 'formAvailable' ] );
		add_action( 'psl_property_contact', [ $this, 'renderForm' ] );
		add_filter( 'wpcf7_form_hidden_fields', [ $this, 'hiddenFields' ] );
		add_filter( 'wpcf7_form_elements', [ $this, 'honeypotField' ] );
		add_filter( 'wpcf7_spam', [ $this, 'honeypotCheck' ], 20, 2 );
		add_action( 'wpcf7_before_send_mail', [ $this, 'beforeSendMail' ], 10, 3 );
		add_filter( 'wpcf7_special_mail_tags', [ $this, 'specialMailTag' ], 10, 4 );
		add_action( 'wpcf7_mail_sent', [ $this, 'mailSent' ] );
		add_action( 'wpcf7_mail_failed', [ $this, 'mailFailed' ] );
	}

	private ?bool $ready = null;

	/**
	 * Formular-ID gesetzt, Formular existiert, Propstack-Adresse gesetzt, Pflichtfelder der Zuordnung
	 * im Formular vorhanden, Zustimmung ist ein acceptance-Feld (siehe LeadSetupCheck). Pro Request gemerkt.
	 */
	public function isReady(): bool {
		return $this->ready ??= ( new LeadSetupCheck( $this->settings ) )->run()['ready'];
	}

	public function formAvailable( mixed $available ): bool {
		return $this->isReady();
	}

	private function isOurForm( mixed $form ): bool {
		$id = $this->settings->cf7FormId();
		return $id > 0 && $form instanceof \WPCF7_ContactForm && (int) $form->id() === $id;
	}

	/* ------------------------------------------------------------ Rendering */

	public function renderForm( array $view ): void {
		if ( empty( $view['contact']['allowed'] ) || ! $this->isReady() ) {
			return;
		}
		echo '<div class="psl-contact__form">';
		echo do_shortcode( sprintf( '[contact-form-7 id="%d" html_class="psl-cf7-form"]', $this->settings->cf7FormId() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CF7-Ausgabe.
		echo '</div>';
	}

	/** Hidden Fields nur für unser Formular und nur auf Detailseiten mit anfragbarem Objekt. */
	public function hiddenFields( mixed $fields ): array {
		$fields = is_array( $fields ) ? $fields : [];
		if ( ! $this->isOurForm( \WPCF7_ContactForm::get_current() ) ) {
			return $fields;
		}
		$view = DetailController::current()?->view();
		if ( null === $view || empty( $view['contact']['allowed'] ) ) {
			return $fields;
		}
		$fields[ self::FIELD_PROPERTY ] = (string) (int) $view['id'];
		$fields[ self::FIELD_LEAD ]     = wp_generate_uuid4();
		return $fields;
	}

	/** Zusätzliches, für Menschen unsichtbares Feld; ausgefüllt → Spam. */
	public function honeypotField( mixed $html ): mixed {
		if ( ! is_string( $html ) || ! $this->isOurForm( \WPCF7_ContactForm::get_current() ) ) {
			return $html;
		}
		return $html . sprintf(
			'<span class="psl-hp" aria-hidden="true"><label>Website <input type="text" name="%s" value="" tabindex="-1" autocomplete="off"></label></span>',
			esc_attr( self::FIELD_HONEYPOT )
		);
	}

	/* ----------------------------------------------------------- Verarbeitung */

	public function honeypotCheck( mixed $spam, mixed $submission ): mixed {
		if ( $spam || ! $submission instanceof \WPCF7_Submission || ! $this->isOurForm( $submission->get_contact_form() ) ) {
			return $spam;
		}
		$value = $submission->get_posted_data( self::FIELD_HONEYPOT );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$this->logger->info( 'lead', [ 'status' => 'spam', 'code' => 'honeypot' ] );
			return true;
		}
		return $spam;
	}

	/**
	 * Verifiziert Property und Eingaben, prüft das Rate-Limit und bereitet die Mail vor.
	 * Bei Fehlern: $abort = true und neutrale Besuchermeldung (keine technischen Details).
	 */
	public function beforeSendMail( mixed $form, mixed &$abort, mixed $submission = null ): void {
		$this->sink = null;
		$this->lead = null;
		if ( ! $this->isOurForm( $form ) || ! $submission instanceof \WPCF7_Submission ) {
			return;
		}

		$leadId     = $this->leadId( $submission->get_posted_data( self::FIELD_LEAD ) );
		$propertyId = $submission->get_posted_data( self::FIELD_PROPERTY );
		try {
			if ( ! $this->isReady() ) {
				throw new LeadException( LeadException::NOT_CONFIGURED );
			}
			$client = (string) ( $submission->get_meta( 'remote_ip' ) ?: 'unknown' );
			if ( ! $this->rateLimiter->attempt( $client ) ) {
				throw new LeadException( LeadException::RATE_LIMITED );
			}

			$posted = [];
			foreach ( $this->settings->fieldMap() as $internal => $cf7Name ) {
				$posted[ $internal ] = '' === $cf7Name ? null : $submission->get_posted_data( $cf7Name );
			}

			$factory = new LeadContextFactory(
				fn ( int $id ) => $this->store->find( $id ),
				new RouteResolver( $this->settings->publicStatusIds(), $this->settings->reservedStatusIds(), $this->clock->now() ),
				fn ( $stored ) => $this->urls->canonicalUrl( $stored )
			);
			$lead = $factory->build( $posted, $propertyId, $leadId );

			$sink = new Cf7MailLeadSink( $form, $this->settings, $this->formatter, self::locale() );
			$sink->deliver( $lead );

			$this->sink = $sink;
			$this->lead = $lead;
			$this->logger->info( 'lead', [ 'lead' => $leadId, 'property' => $lead->property->id, 'status' => 'prepared' ] );
		} catch ( LeadException $e ) {
			$abort = true;
			$submission->set_response( self::VISITOR_ERROR );
			$this->logger->warning( 'lead', [ 'lead' => $leadId, 'property' => is_scalar( $propertyId ) ? absint( $propertyId ) : 0, 'status' => 'aborted', 'code' => $e->reason() ] );
		}
	}

	public function specialMailTag( mixed $output, mixed $name, mixed $html = false, mixed $mailTag = null ): mixed {
		if ( Cf7MailLeadSink::MAIL_TAG !== $name ) {
			return $output;
		}
		return null === $this->sink ? '' : $this->sink->block();
	}

	public function mailSent( mixed $form ): void {
		if ( ! $this->isOurForm( $form ) || null === $this->lead ) {
			return;
		}
		set_transient( self::USED_LEAD_PREFIX . $this->lead->leadId, 1, DAY_IN_SECONDS );
		$this->logger->info( 'lead', [ 'lead' => $this->lead->leadId, 'property' => $this->lead->property->id, 'status' => 'sent' ] );
		do_action( 'psl_lead_sent', $this->lead );
	}

	public function mailFailed( mixed $form ): void {
		if ( ! $this->isOurForm( $form ) || null === $this->lead ) {
			return;
		}
		$this->logger->error( 'lead', [ 'lead' => $this->lead->leadId, 'property' => $this->lead->property->id, 'status' => 'mail_failed' ] );
	}

	/** Browser-Lead-ID nur als Hinweis: gültiges UUIDv4-Format und noch nicht verwendet, sonst neu erzeugen. */
	private function leadId( mixed $raw ): string {
		$candidate = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
		if ( preg_match( self::UUID_PATTERN, $candidate ) && false === get_transient( self::USED_LEAD_PREFIX . $candidate ) ) {
			return $candidate;
		}
		return wp_generate_uuid4();
	}

	private static function locale(): ?string {
		$lang = substr( (string) get_locale(), 0, 2 );
		return in_array( $lang, [ 'de', 'en', 'es' ], true ) ? $lang : null;
	}
}
