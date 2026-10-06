<?php

namespace PropstackLite\Tracking;

use PropstackLite\Frontend\DetailController;
use PropstackLite\Leads\LeadContext;
use PropstackLite\Routing\Router;
use PropstackLite\Settings;
use PropstackLite\Tracking\Consent\ConsentProviderInterface;
use PropstackLite\Tracking\Consent\ConsentProviders;

/**
 * Phase 6 – Attribution und Conversion-Tracking (siehe docs/tracking.md).
 *
 * - psl-tracking.js seitenweit (Attribution muss auf jeder Landingpage funktionieren), nur wenn
 *   Attribution oder dataLayer-Event aktiv UND ein Consent-Provider mit Marketing-Fähigkeit gewählt ist.
 * - psl-lead-event.js nur auf Detailseiten mit Anfrageformular.
 * - Hidden Field `psl_attr` im konfigurierten Formular; Wert setzt nur das Skript bei Consent.
 * - Filter `psl_lead_attribution`: validierte Attribution für die optionale Propstack-Übertragung.
 * - Filter `wpcf7_feedback_response`: nach `mail_sent` öffentliche Event-Daten (`psl_lead`).
 *
 * Das Plugin lädt kein GTM/GA4/Ads/Pixel und erzeugt keine externen Requests. Das Lead-Handling
 * hängt nie vom Tracking ab.
 */
final class TrackingIntegration {

	public const CORE_HANDLE = 'propstack-lite-tracking';
	public const LEAD_HANDLE = 'propstack-lite-lead-event';
	public const FIELD       = 'psl_attr';

	private ?LeadContext $sentLead = null;

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 20 );
		add_filter( 'wpcf7_form_hidden_fields', [ $this, 'hiddenField' ], 11 ); // nach Cf7Integration (psl_lead_id)
		add_filter( 'psl_lead_attribution', [ $this, 'leadAttribution' ], 10, 2 );
		add_action( 'psl_lead_sent', [ $this, 'rememberLead' ] );
		add_filter( 'wpcf7_feedback_response', [ $this, 'feedbackResponse' ], 10, 2 );
	}

	public function provider(): ConsentProviderInterface {
		return ConsentProviders::get( $this->settings->consentProviderId() );
	}

	/** Darf überhaupt etwas geladen/übertragen werden? */
	public function isActive(): bool {
		return ( $this->settings->trackingAttribution() || $this->settings->trackingDataLayer() ) && $this->provider()->allowsMarketing();
	}

	/* --------------------------------------------------------------- Skripte */

	public function enqueue(): void {
		if ( ! $this->isActive() ) {
			return;
		}
		wp_enqueue_script( self::CORE_HANDLE, PSL_URL . 'assets/js/psl-tracking.js', [], PSL_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
		wp_add_inline_script( self::CORE_HANDLE, 'window.pslTrackingConfig = ' . wp_json_encode( $this->coreConfig() ) . ';', 'before' );

		if ( $this->formOnPage() ) {
			wp_enqueue_script( self::LEAD_HANDLE, PSL_URL . 'assets/js/psl-lead-event.js', [ self::CORE_HANDLE ], PSL_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
			wp_add_inline_script( self::LEAD_HANDLE, 'window.pslLeadConfig = ' . wp_json_encode( $this->leadConfig() ) . ';', 'before' );
		}
	}

	/** @return array<string, mixed> */
	public function coreConfig(): array {
		$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return [
			'cookie'      => AttributionStorage::COOKIE,
			'ttlDays'     => $this->settings->attributionTtlDays(),
			'path'        => '' === $path ? '/' : $path,
			'secure'      => is_ssl(),
			'attribution' => $this->settings->trackingAttribution(),
			'consent'     => $this->provider()->clientConfig(),
		];
	}

	/** @return array<string, mixed> */
	public function leadConfig(): array {
		return [
			'formId'        => $this->settings->cf7FormId(),
			'attribution'   => $this->settings->trackingAttribution(),
			'dataLayer'     => $this->settings->trackingDataLayer(),
			'dataLayerName' => (string) apply_filters( 'psl_datalayer_name', 'dataLayer' ),
		];
	}

	private function formOnPage(): bool {
		if ( ! Router::isPropertyRequest() ) {
			return false;
		}
		$view = DetailController::current()?->view();
		return null !== $view && ! empty( $view['contact']['formAvailable'] );
	}

	/* ------------------------------------------------------------ Formular */

	private function isOurForm( mixed $form ): bool {
		$id = $this->settings->cf7FormId();
		return $id > 0 && $form instanceof \WPCF7_ContactForm && (int) $form->id() === $id;
	}

	public function hiddenField( mixed $fields ): mixed {
		if ( ! is_array( $fields ) || ! $this->settings->trackingAttribution() || ! $this->provider()->allowsMarketing() ) {
			return $fields;
		}
		if ( class_exists( '\WPCF7_ContactForm' ) && $this->isOurForm( \WPCF7_ContactForm::get_current() ) && isset( $fields['psl_lead_id'] ) ) {
			$fields[ self::FIELD ] = ''; // wird nur bei Consent clientseitig befüllt
		}
		return $fields;
	}

	/**
	 * Attribution aus dem Hidden Field (nur mit Einstellung + Marketing-fähigem Provider).
	 *
	 * @param array<string, string> $values
	 * @return array<string, string>
	 */
	public function leadAttribution( mixed $values, mixed $lead = null ): array {
		$values = is_array( $values ) ? $values : [];
		if ( ! $this->settings->trackingAttribution() || ! $this->provider()->allowsMarketing() || ! class_exists( '\WPCF7_Submission' ) ) {
			return $values;
		}
		$submission = \WPCF7_Submission::get_instance();
		$raw        = $submission ? $submission->get_posted_data( self::FIELD ) : null;
		if ( ! is_string( $raw ) || '' === $raw ) {
			return $values;
		}
		$attribution = ( new AttributionStorage( $this->settings->attributionTtlDays() ) )->parse( $raw, time() );
		return array_merge( $attribution->leadFields(), $values ); // lead_id bleibt die serverseitige
	}

	/* --------------------------------------------------------------- Event */

	public function rememberLead( mixed $lead ): void {
		$this->sentLead = $lead instanceof LeadContext ? $lead : null;
	}

	/**
	 * Nur nach erfolgreichem Versand des konfigurierten Formulars: öffentliche Event-Daten anhängen.
	 *
	 * @param array<string, mixed> $response
	 */
	public function feedbackResponse( mixed $response, mixed $result = null ): mixed {
		if ( ! is_array( $response ) || null === $this->sentLead || ! $this->settings->trackingDataLayer() || ! $this->provider()->allowsMarketing() ) {
			return $response;
		}
		if ( 'mail_sent' !== ( $response['status'] ?? '' ) || (int) ( $response['contact_form_id'] ?? 0 ) !== $this->settings->cf7FormId() ) {
			return $response;
		}
		$response['psl_lead'] = LeadEvent::payload( $this->sentLead );
		$this->sentLead       = null;
		return $response;
	}
}
