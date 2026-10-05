<?php

namespace PropstackLite\Api;

/**
 * Fehler bei der Kommunikation mit Propstack.
 *
 * Die Nachricht ist für Logs und Admin-Anzeigen bestimmt und enthält niemals den API-Key.
 */
final class ApiException extends \RuntimeException {

	public const CONFIG           = 'config';
	public const NETWORK          = 'network';
	public const AUTH             = 'auth';
	public const NOT_FOUND        = 'not_found';
	public const RATE_LIMIT       = 'rate_limit';
	public const SERVER           = 'server';
	public const CLIENT           = 'client';
	public const INVALID_RESPONSE = 'invalid_response';

	public function __construct(
		string $message,
		private string $category,
		private int $httpStatus = 0,
		private ?int $retryAfter = null
	) {
		parent::__construct( $message );
	}

	public function getCategory(): string {
		return $this->category;
	}

	public function getHttpStatus(): int {
		return $this->httpStatus;
	}

	/** Sekunden laut Retry-After-Header, falls vorhanden. */
	public function getRetryAfter(): ?int {
		return $this->retryAfter;
	}

	public function isRetryable(): bool {
		return in_array( $this->category, [ self::NETWORK, self::RATE_LIMIT, self::SERVER ], true );
	}
}
