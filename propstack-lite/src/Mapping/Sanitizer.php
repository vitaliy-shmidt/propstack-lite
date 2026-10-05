<?php

namespace PropstackLite\Mapping;

/**
 * Normalisiert und bereinigt Rohwerte aus Propstack.
 *
 * Läuft ohne WordPress (Unit-Tests); in WordPress werden wp_kses_post / wp_strip_all_tags genutzt.
 * Bereinigung beim Speichern ersetzt NICHT das Escaping bei der Ausgabe.
 */
final class Sanitizer {

	/** Erlaubte Bild-/Medien-Hosts. */
	private const ALLOWED_HOST_SUFFIX = 'propstack.de';

	/** Einzeiliger Klartext ohne HTML. Leere Werte werden zu null. */
	public static function text( mixed $value, int $max = 255 ): ?string {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( static fn ( $v ) => self::text( $v, $max ), $value ) ) );
		}
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return null;
		}
		$text = (string) $value;
		$text = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $text ) : strip_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
		if ( '' === $text ) {
			return null;
		}
		return mb_substr( $text, 0, $max );
	}

	/** Mehrzeiliger Text mit sicherem HTML (wp_kses_post); Zeilenumbrüche bleiben erhalten. */
	public static function richText( mixed $value, int $max = 20000 ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$text = str_replace( [ "\r\n", "\r" ], "\n", $value );
		if ( function_exists( 'wp_kses_post' ) ) {
			$text = wp_kses_post( $text );
		} else {
			$text = preg_replace( '#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $text ) ?? '';
			$text = strip_tags( $text, '<p><br><strong><b><em><i><ul><ol><li>' );
		}
		$text = trim( $text );
		if ( '' === $text ) {
			return null;
		}
		return mb_substr( $text, 0, $max );
	}

	/** Zahl; `0` gilt optional als „nicht angegeben“. Negative Werte und Freitext werden verworfen. */
	public static function float( mixed $value, bool $zeroIsNull = true ): ?float {
		if ( is_bool( $value ) || ! is_numeric( $value ) ) {
			return null;
		}
		$number = (float) $value;
		if ( $number < 0 || ! is_finite( $number ) ) {
			return null;
		}
		if ( $zeroIsNull && 0.0 === $number ) {
			return null;
		}
		return $number;
	}

	public static function int( mixed $value, bool $zeroIsNull = true ): ?int {
		$number = self::float( $value, $zeroIsNull );
		return null === $number ? null : (int) round( $number );
	}

	/** Echte Booleans; Strings wie "true"/"1" werden akzeptiert, alles andere ist null. */
	public static function bool( mixed $value ): ?bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 1 === $value ? true : ( 0 === $value ? false : null );
		}
		if ( is_string( $value ) ) {
			$v = strtolower( trim( $value ) );
			if ( in_array( $v, [ 'true', '1', 'yes', 'ja' ], true ) ) {
				return true;
			}
			if ( in_array( $v, [ 'false', '0', 'no', 'nein' ], true ) ) {
				return false;
			}
		}
		return null;
	}

	/** Großgeschriebener Enum-Wert wie APARTMENT oder A_PLUS. */
	public static function enum( mixed $value, int $max = 60 ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$v = strtoupper( trim( $value ) );
		if ( '' === $v || ! preg_match( '/^[A-Z0-9_]+$/', $v ) ) {
			return null;
		}
		return substr( $v, 0, $max );
	}

	/** HTTPS-URL auf *.propstack.de – alles andere wird verworfen. */
	public static function propstackUrl( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$url   = trim( $value );
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ( $parts['scheme'] ?? '' ) !== 'https' || empty( $parts['host'] ) ) {
			return null;
		}
		$host = strtolower( $parts['host'] );
		if ( self::ALLOWED_HOST_SUFFIX !== $host && ! str_ends_with( $host, '.' . self::ALLOWED_HOST_SUFFIX ) ) {
			return null;
		}
		if ( false === filter_var( $url, FILTER_VALIDATE_URL ) && false === filter_var( str_replace( ' ', '%20', $url ), FILTER_VALIDATE_URL ) ) {
			return null;
		}
		return str_replace( ' ', '%20', $url );
	}

	public static function email( mixed $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$email = trim( $value );
		return false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ? null : $email;
	}

	public static function phone( mixed $value ): ?string {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return null;
		}
		$phone = trim( preg_replace( '/[^0-9+()\/.\- ]/', '', (string) $value ) ?? '' );
		$digits = preg_replace( '/\D/', '', $phone ) ?? '';
		if ( strlen( $digits ) < 3 || strlen( $phone ) > 40 ) {
			return null;
		}
		return $phone;
	}

	/** Liste von Klartext-Werten (z. B. Bodenbeläge). */
	public static function stringList( mixed $value, int $max = 100 ): array {
		if ( is_string( $value ) ) {
			$value = [ $value ];
		}
		if ( ! is_array( $value ) ) {
			return [];
		}
		$list = [];
		foreach ( $value as $item ) {
			$text = self::text( $item, $max );
			if ( null !== $text ) {
				$list[] = $text;
			}
		}
		return array_values( array_unique( $list ) );
	}

	/** ISO-8601-Zeitpunkt in UTC (`2026-10-05T10:25:35+00:00`). */
	public static function datetime( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		try {
			$date = new \DateTimeImmutable( $value );
		} catch ( \Exception ) {
			return null;
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DATE_ATOM );
	}
}
