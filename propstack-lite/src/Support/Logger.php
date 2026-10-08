<?php

namespace PropstackLite\Support;

/**
 * Logger ohne personenbezogene Daten (Format: `[propstack-lite] LEVEL Nachricht {"code":…}`).
 *
 * Stufen:
 * - error, warning: immer (error_log → debug.log bei WP_DEBUG_LOG, sonst PHP-/Server-Log) – Betrieb muss
 *   Fehler sehen, auch ohne Debug-Modus.
 * - info: nur bei aktivem WP_DEBUG (Sync-Zusammenfassungen, Lead-Status).
 * Filter `psl_log_enabled` (bool, Level) kann einzelne Stufen abschalten.
 *
 * Datenschutz: Kontextwerte nur skalar; Schlüssel, die nach Secrets/PII/Tracking klingen, werden verworfen;
 * Werte mit E-Mail-Adressen werden ersetzt; URLs verlieren ihren Query-String (UTM, gclid …).
 * Stabile Codes über `code` (Support\ErrorCode).
 */
final class Logger {

	public const ERROR   = 'ERROR';
	public const WARNING = 'WARNING';
	public const INFO    = 'INFO';

	private const BLOCKED_KEY_PATTERN = '/key|token|secret|pass|mail|phone|tel|name|address|street|message|body|ip|gclid|gbraid|wbraid|fbclid|utm|referr|url|cookie|attr/i';

	/** @var callable|null Testweiche: erhält die fertige Zeile statt error_log */
	private $sink;

	public function __construct( ?callable $sink = null ) {
		$this->sink = $sink;
	}

	public function info( string $message, array $context = [] ): void {
		$this->write( self::INFO, $message, $context );
	}

	public function warning( string $message, array $context = [] ): void {
		$this->write( self::WARNING, $message, $context );
	}

	public function error( string $message, array $context = [] ): void {
		$this->write( self::ERROR, $message, $context );
	}

	public static function enabled( string $level ): bool {
		$enabled = self::INFO === $level ? ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : true;
		return function_exists( 'apply_filters' ) ? (bool) apply_filters( 'psl_log_enabled', $enabled, $level ) : $enabled;
	}

	/** Bereinigte Logzeile (ohne WordPress testbar). */
	public static function format( string $level, string $message, array $context ): string {
		$clean = [];
		foreach ( $context as $key => $value ) {
			if ( 'code' !== $key && ( preg_match( self::BLOCKED_KEY_PATTERN, (string) $key ) || ! is_scalar( $value ) ) ) {
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$clean[ $key ] = is_string( $value ) ? self::scrub( $value ) : $value;
		}
		$line = '[propstack-lite] ' . $level . ' ' . self::scrub( $message );
		if ( [] !== $clean ) {
			$line .= ' ' . json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return $line;
	}

	/** E-Mail-Adressen entfernen, Query-Strings von URLs abschneiden, auf 500 Zeichen kürzen. */
	public static function scrub( string $text ): string {
		$text = (string) preg_replace( '/[^\s@"<>]+@[^\s@"<>]+\.[a-z]{2,}/i', '[E-Mail entfernt]', $text );
		$text = (string) preg_replace( '#(https?://[^\s?"<>]+)\?[^\s"<>]*#i', '$1', $text );
		return mb_substr( $text, 0, 500 );
	}

	private function write( string $level, string $message, array $context ): void {
		if ( ! self::enabled( $level ) ) {
			return;
		}
		$line = self::format( $level, $message, $context );
		if ( null !== $this->sink ) {
			( $this->sink )( $line );
			return;
		}
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
