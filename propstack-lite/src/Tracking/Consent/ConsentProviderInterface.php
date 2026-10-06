<?php

namespace PropstackLite\Tracking\Consent;

/**
 * Anbindung eines Consent-Systems (Borlabs, Real Cookie Banner, Complianz, Cookiebot, eigene Lösung …).
 *
 * Consent ist Browser-Zustand: Die eigentliche Prüfung erfolgt in assets/js/psl-tracking.js anhand von
 * clientConfig(). PHP nutzt den Provider nur, um zu entscheiden, ob überhaupt Marketingdaten
 * verwendet werden dürfen (allowsMarketing() = false → Server ignoriert den Attribution-Cookie und
 * liefert keine Event-Daten).
 */
interface ConsentProviderInterface {

	/** Technischer Schlüssel (Einstellung `consent_provider`), nur [a-z0-9_]. */
	public function id(): string;

	/** Bezeichnung im Admin. */
	public function label(): string;

	/** Kann dieser Provider überhaupt Marketing-Consent melden? (none → false) */
	public function allowsMarketing(): bool;

	/** Real gegen das Consent-System getestet? Nicht getestete Adapter werden im Admin so markiert. */
	public function isVerified(): bool;

	/**
	 * Konfiguration für psl-tracking.js, z. B. [ 'type' => 'none' ] oder [ 'type' => 'api' ].
	 *
	 * @return array<string, string|bool|int>
	 */
	public function clientConfig(): array;
}
