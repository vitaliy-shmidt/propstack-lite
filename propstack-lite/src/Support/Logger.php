<?php

namespace PropstackLite\Support;

/**
 * Minimaler Logger ohne personenbezogene Daten.
 *
 * Schreibt nur, wenn WP_DEBUG_LOG aktiv ist. Kontextwerte müssen skalare, technische Angaben
 * sein (IDs, Zähler, HTTP-Codes); Schlüssel, die nach Secrets oder PII klingen, werden verworfen.
 */
final class Logger {

	private const BLOCKED_KEY_PATTERN = '/key|token|secret|pass|email|phone|name|address|street|message_body/i';

	public function info( string $message, array $context = [] ): void {
		$this->write( 'INFO', $message, $context );
	}

	public function warning( string $message, array $context = [] ): void {
		$this->write( 'WARNING', $message, $context );
	}

	public function error( string $message, array $context = [] ): void {
		$this->write( 'ERROR', $message, $context );
	}

	private function write( string $level, string $message, array $context ): void {
		if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
			return;
		}
		$clean = [];
		foreach ( $context as $key => $value ) {
			if ( preg_match( self::BLOCKED_KEY_PATTERN, (string) $key ) || ! is_scalar( $value ) ) {
				continue;
			}
			$clean[ $key ] = $value;
		}
		$line = '[propstack-lite] ' . $level . ' ' . $message;
		if ( [] !== $clean ) {
			$line .= ' ' . json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
