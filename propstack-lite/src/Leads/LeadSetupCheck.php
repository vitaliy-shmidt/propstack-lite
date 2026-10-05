<?php

namespace PropstackLite\Leads;

use PropstackLite\Settings;

/**
 * Konfigurationsprüfung der Immobilienanfragen für die Einstellungsseite (keine Secrets).
 */
final class LeadSetupCheck {

	public function __construct( private Settings $settings ) {}

	/**
	 * @return array{cf7: bool, form: ?string, email: bool, missingFields: list<string>, consentIsAcceptance: ?bool, ready: bool}
	 */
	public function run(): array {
		$cf7     = Cf7Integration::isAvailable();
		$form    = null;
		$missing = [];
		$consent = null;

		if ( $cf7 && $this->settings->cf7FormId() > 0 ) {
			$contactForm = wpcf7_contact_form( $this->settings->cf7FormId() );
			if ( null !== $contactForm ) {
				$form = (string) $contactForm->title();
				foreach ( $this->settings->fieldMap() as $internal => $name ) {
					if ( '' === $name ) {
						continue;
					}
					$tags = $contactForm->scan_form_tags( [ 'name' => $name ] );
					if ( [] === $tags ) {
						$missing[] = $internal . ' (' . $name . ')';
					} elseif ( 'consent' === $internal ) {
						$consent = 'acceptance' === $tags[0]->basetype;
					}
				}
			}
		}

		$email = '' !== $this->settings->inquiryEmail();
		$ready = $cf7 && null !== $form && $email && [] === array_filter( $missing, static fn ( $m ) => ! str_starts_with( $m, 'salutation' ) ) && false !== $consent;

		return [
			'cf7'                 => $cf7,
			'form'                => $form,
			'email'               => $email,
			'missingFields'       => $missing,
			'consentIsAcceptance' => $consent,
			'ready'               => $ready,
		];
	}
}
