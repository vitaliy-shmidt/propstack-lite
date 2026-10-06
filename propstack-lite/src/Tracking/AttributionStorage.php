<?php

namespace PropstackLite\Tracking;

/**
 * First-Party-Cookie `psl_attr` – wird ausschließlich clientseitig (assets/js/psl-tracking.js) und nur
 * mit Marketing-Consent geschrieben; der Server liest und validiert ihn nur (kompatibel mit
 * Full-Page-Cache, keine Set-Cookie-Header aus PHP).
 *
 * Format (URL-kodiertes JSON, max. ~2 KB): { "v": 1, "f": Touch, "l": Touch } – siehe Touch.
 */
final class AttributionStorage {

	public const COOKIE           = 'psl_attr';
	public const VERSION          = 1;
	public const DEFAULT_TTL_DAYS = 90;
	public const MAX_TTL_DAYS     = 365;
	public const MAX_BYTES        = 2048;

	public function __construct( private int $ttlDays = self::DEFAULT_TTL_DAYS ) {}

	/**
	 * @param array<string, mixed> $cookies z. B. $_COOKIE
	 */
	public function read( array $cookies, int $now ): Attribution {
		$raw = $cookies[ self::COOKIE ] ?? null;
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > self::MAX_BYTES * 3 ) {
			return new Attribution( null, null );
		}
		// PHP hat den Cookie-Wert bereits URL-dekodiert ($_COOKIE).
		return $this->parse( function_exists( 'wp_unslash' ) ? wp_unslash( $raw ) : stripslashes( $raw ), $now );
	}

	/** JSON wie im Cookie bzw. im Hidden Field `psl_attr` (vom Formular-Skript bei Consent gesetzt). */
	public function parse( string $json, int $now ): Attribution {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new Attribution( null, null );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) || self::VERSION !== ( $data['v'] ?? null ) ) {
			return new Attribution( null, null );
		}
		return new Attribution( $this->valid( $data['f'] ?? null, $now ), $this->valid( $data['l'] ?? null, $now ) );
	}

	/** Touch nur, wenn gültig und innerhalb der TTL (kleine Uhrabweichung erlaubt). */
	private function valid( mixed $raw, int $now ): ?Touch {
		$touch = Touch::fromArray( $raw );
		if ( null === $touch ) {
			return null;
		}
		$ttl = max( 1, min( self::MAX_TTL_DAYS, $this->ttlDays ) ) * 86400;
		return $touch->timestamp > $now - $ttl && $touch->timestamp <= $now + 300 ? $touch : null;
	}
}
