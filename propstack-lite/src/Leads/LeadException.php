<?php

namespace PropstackLite\Leads;

/**
 * Anfrage wird nicht weitergeleitet. Der Code ist für Logs/Admin bestimmt und enthält keine
 * personenbezogenen Daten; Besucher sehen nur eine neutrale Meldung.
 */
final class LeadException extends \RuntimeException {

	public const PROPERTY_MISSING       = 'property_missing';
	public const PROPERTY_NOT_FOUND     = 'property_not_found';
	public const PROPERTY_NOT_INQUIRABLE = 'property_not_inquirable';
	public const CONSENT_MISSING        = 'consent_missing';
	public const INVALID_INPUT          = 'invalid_input';
	public const RATE_LIMITED           = 'rate_limited';
	public const NOT_CONFIGURED         = 'not_configured';

	public function __construct( private string $reason ) {
		parent::__construct( $reason );
	}

	public function reason(): string {
		return $this->reason;
	}
}
