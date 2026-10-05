<?php

namespace PropstackLite\Leads;

/**
 * Normalisierte, validierte Formulardaten eines Interessenten (unveränderlich).
 *
 * Einzeilige Werte enthalten keine Zeilenumbrüche/Steuerzeichen (Schutz vor Header-Injection),
 * die E-Mail-Adresse ist syntaktisch gültig. Escaping erfolgt erst bei der Ausgabe.
 */
final class LeadData {

	public function __construct(
		public readonly ?string $salutation,
		public readonly string $firstName,
		public readonly string $lastName,
		public readonly string $email,
		public readonly string $phone,
		public readonly string $message,
		public readonly bool $consent
	) {}
}
