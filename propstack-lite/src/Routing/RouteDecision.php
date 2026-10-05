<?php

namespace PropstackLite\Routing;

use PropstackLite\Storage\StoredProperty;

/** Ergebnis der Statusauflösung für eine Detail-URL (ohne Redirect-Entscheidung). */
final class RouteDecision {

	public const ACTIVE    = 'active';
	public const RESERVED  = 'reserved';
	public const SOLD      = 'sold';
	public const GONE      = 'gone';
	public const NOT_FOUND = 'not_found';

	public function __construct(
		public readonly string $state,
		public readonly int $httpStatus,
		public readonly ?StoredProperty $stored = null
	) {}

	/** Wird eine Objektseite gerendert (200)? Nur dann gibt es Canonical/301-Kanonisierung. */
	public function isRenderable(): bool {
		return 200 === $this->httpStatus;
	}

	/** Verkauft-Phase und 410 dürfen nicht indexiert werden. */
	public function isNoindex(): bool {
		return in_array( $this->state, [ self::SOLD, self::GONE ], true );
	}

	/** Kontaktformular (Phase 4) nur bei verfügbaren Objekten. */
	public function allowsContact(): bool {
		return in_array( $this->state, [ self::ACTIVE, self::RESERVED ], true );
	}
}
