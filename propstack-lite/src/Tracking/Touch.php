<?php

namespace PropstackLite\Tracking;

/**
 * Ein Marketingkontakt (Touch) – unveränderlich, ausschließlich bereinigte Werte.
 *
 * Kurzschlüssel im Cookie (Größe): s source, m medium, c campaign, ct content, t term, g gclid,
 * gb gbraid, wb wbraid, ch channel, lp landing path, rh referrer host, ts Zeitstempel (Unix, UTC).
 * Keine personenbezogenen Daten, keine vollständigen URLs, keine Querystrings.
 */
final class Touch {

	public const CHANNELS = [ 'paid_search', 'organic_search', 'paid_social', 'organic_social', 'referral', 'direct', 'other' ];

	private const MAX_TEXT = 100;
	private const MAX_PATH = 200;

	public function __construct(
		public readonly string $source = '',
		public readonly string $medium = '',
		public readonly string $campaign = '',
		public readonly string $content = '',
		public readonly string $term = '',
		public readonly string $gclid = '',
		public readonly string $gbraid = '',
		public readonly string $wbraid = '',
		public readonly string $channel = 'other',
		public readonly string $landingPath = '',
		public readonly string $referrerHost = '',
		public readonly int $timestamp = 0
	) {}

	/** Aus Cookie-Daten (Kurzschlüssel); ungültige Werte werden verworfen. */
	public static function fromArray( mixed $raw ): ?self {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$ts = isset( $raw['ts'] ) && is_numeric( $raw['ts'] ) ? (int) $raw['ts'] : 0;
		if ( $ts <= 0 ) {
			return null;
		}
		$touch = new self(
			source: strtolower( self::text( $raw['s'] ?? '' ) ),
			medium: strtolower( self::text( $raw['m'] ?? '' ) ),
			campaign: self::text( $raw['c'] ?? '' ),
			content: self::text( $raw['ct'] ?? '' ),
			term: self::text( $raw['t'] ?? '' ),
			gclid: self::clickId( $raw['g'] ?? '' ),
			gbraid: self::clickId( $raw['gb'] ?? '' ),
			wbraid: self::clickId( $raw['wb'] ?? '' ),
			channel: in_array( $raw['ch'] ?? '', self::CHANNELS, true ) ? $raw['ch'] : 'other',
			landingPath: self::path( $raw['lp'] ?? '' ),
			referrerHost: self::host( $raw['rh'] ?? '' ),
			timestamp: $ts
		);
		// Ein Touch ohne jede Herkunftsinformation ist wertlos.
		return '' === $touch->source && '' === $touch->gclid && '' === $touch->gbraid && '' === $touch->wbraid && '' === $touch->referrerHost ? null : $touch;
	}

	/**
	 * Freitext (UTM-Werte): Steuerzeichen und HTML-/Skript-relevante Zeichen entfernen, Leerraum
	 * normalisieren, Länge begrenzen. Identische Regel in assets/js/psl-tracking.js.
	 */
	public static function text( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$v = preg_replace( '/[\x00-\x1F\x7F<>"\'`\\\\]+/u', '', (string) $value ) ?? '';
		$v = trim( preg_replace( '/\s+/u', ' ', $v ) ?? '' );
		return mb_substr( $v, 0, self::MAX_TEXT );
	}

	/** Klick-IDs (gclid/gbraid/wbraid): nur [A-Za-z0-9_.-], max. 255 Zeichen, sonst leer. */
	public static function clickId( mixed $value ): string {
		return is_string( $value ) && preg_match( '/^[A-Za-z0-9_.\-]{1,255}$/', $value ) ? $value : '';
	}

	/** Nur Pfad (ohne Query/Fragment), beginnt mit „/“, eingeschränkter Zeichensatz. */
	public static function path( mixed $value ): string {
		return is_string( $value ) && preg_match( '#^/[A-Za-z0-9/_\-.~%]{0,' . ( self::MAX_PATH - 1 ) . '}$#', $value ) ? $value : '';
	}

	/** Nur Hostname (keine URL, kein Pfad). */
	public static function host( mixed $value ): string {
		return is_string( $value ) && preg_match( '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $value ) && strlen( $value ) <= 253 ? $value : '';
	}
}
