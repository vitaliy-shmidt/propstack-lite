<?php

namespace PropstackLite\Leads;

use PropstackLite\Domain\Property;

/**
 * Unveränderlicher Kontext einer Immobilienanfrage.
 *
 * Objektdaten (ID, Titel, URL …) stammen ausschließlich aus dem lokalen PropertyStore,
 * nie aus Browserdaten. `attribution` enthält `lead_id` und – nur mit aktivierter Attribution und
 * Marketing-Consent – Werte aus dem Attribution-Cookie (Phase 6, siehe Tracking\Attribution).
 */
final class LeadContext {

	/**
	 * @param array<string, string> $attribution z. B. ['lead_id' => '…'] – nur Schlüssel aus Settings::ATTRIBUTION_KEYS
	 */
	public function __construct(
		public readonly string $leadId,
		public readonly Property $property,
		public readonly string $propertyUrl,
		public readonly LeadData $data,
		public readonly array $attribution = []
	) {}

	/**
	 * Kopie mit ergänzten Attributionswerten: nur bekannte Schlüssel, nur nicht-leere Strings;
	 * `lead_id` bleibt immer die serverseitig bestätigte Lead-ID.
	 *
	 * @param array<string, mixed> $values
	 */
	public function withAttribution( array $values ): self {
		$clean = [];
		foreach ( \PropstackLite\Settings::ATTRIBUTION_KEYS as $key ) {
			if ( isset( $values[ $key ] ) && is_string( $values[ $key ] ) && '' !== $values[ $key ] ) {
				$clean[ $key ] = $values[ $key ];
			}
		}
		$clean['lead_id'] = $this->leadId;
		return new self( $this->leadId, $this->property, $this->propertyUrl, $this->data, $clean );
	}
}
