<?php

namespace PropstackLite\Tracking\Consent;

/**
 * Sicherer Standard: kein Consent-System angebunden → nie Marketing-Consent. Es werden keine
 * Attribution-Cookies gesetzt (ein vorhandener wird gelöscht) und keine Conversion-Events gesendet.
 * Formular und Anfrage funktionieren unverändert.
 */
final class NoConsentProvider implements ConsentProviderInterface {

	public function id(): string {
		return 'none';
	}

	public function label(): string {
		return 'Kein Consent-System (sicherer Standard – keine Marketingdaten)';
	}

	public function allowsMarketing(): bool {
		return false;
	}

	public function isVerified(): bool {
		return true;
	}

	public function clientConfig(): array {
		return [ 'type' => 'none' ];
	}
}
