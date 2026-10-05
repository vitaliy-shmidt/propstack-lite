<?php

namespace PropstackLite\Leads;

use PropstackLite\Domain\Property;

/**
 * Unveränderlicher Kontext einer Immobilienanfrage.
 *
 * Objektdaten (ID, Titel, URL …) stammen ausschließlich aus dem lokalen PropertyStore,
 * nie aus Browserdaten. `attribution` ist in Phase 4 leer bis auf `lead_id` (Phase 6 ergänzt UTM/GCLID).
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
}
