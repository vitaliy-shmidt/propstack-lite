<?php

namespace PropstackLite\Api;

/**
 * Baut Query-Strings im von Propstack (Rails) erwarteten Format.
 *
 * Arrays werden als `key[]=a&key[]=b` serialisiert – `http_build_query()` würde
 * `key[0]=a` erzeugen, was Rails als Hash statt als Liste interpretiert.
 */
final class QueryString {

	public static function build( array $params ): string {
		$parts = [];
		foreach ( $params as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			$name = rawurlencode( (string) $key );
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					if ( null === $item || '' === $item || is_array( $item ) ) {
						continue;
					}
					$parts[] = $name . '[]=' . rawurlencode( self::scalar( $item ) );
				}
				continue;
			}
			$parts[] = $name . '=' . rawurlencode( self::scalar( $value ) );
		}
		return implode( '&', $parts );
	}

	private static function scalar( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return (string) $value;
	}
}
