<?php

namespace PropstackLite\Tracking\Consent;

/**
 * Generische Anbindung über JavaScript – unabhängig vom konkreten Consent-Tool:
 *
 *   window.PSLTracking.setConsent( true | false );            // z. B. aus dem Callback des Consent-Tools
 *   document.dispatchEvent( new CustomEvent( 'psl:consent', { detail: { marketing: true } } ) );
 *   window.pslConsent = { marketing: true };                  // vor dem Laden des Skripts gesetzt
 *
 * Ohne Meldung gilt: kein Consent. Der Zustand wird nicht vom Plugin gespeichert – das Consent-Tool
 * muss ihn auf jeder Seite melden. Beispiele für Borlabs, Real Cookie Banner, Complianz, Cookiebot
 * und GTM stehen in docs/tracking.md (nicht gegen reale Installationen getestet).
 */
final class JsApiConsentProvider implements ConsentProviderInterface {

	public function id(): string {
		return 'js_api';
	}

	public function label(): string {
		return 'JavaScript-API (Consent-Tool meldet Marketing-Einwilligung an PSLTracking.setConsent)';
	}

	public function allowsMarketing(): bool {
		return true;
	}

	public function isVerified(): bool {
		return true; // API selbst getestet; die Anbindung des jeweiligen Tools ist Website-Konfiguration
	}

	public function clientConfig(): array {
		return [ 'type' => 'api' ];
	}
}
