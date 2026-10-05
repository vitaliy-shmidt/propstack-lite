<?php

namespace PropstackLite\Leads;

/**
 * Einfaches Fenster-Rate-Limit pro anonymisiertem Client-Schlüssel.
 *
 * Der Schlüssel wird mit HMAC-SHA256 und einem geheimen Salt gebildet; die Klartext-IP wird nie
 * gespeichert. Speicherung kurzlebig (Transients mit Ablauf). Storage/Zeit injizierbar → testbar.
 */
final class RateLimiter {

	public const MAX_ATTEMPTS = 5;
	public const WINDOW       = 600; // Sekunden

	/** @var callable(string): mixed */
	private $get;
	/** @var callable(string, mixed, int): void */
	private $set;
	/** @var callable(): int */
	private $now;

	public function __construct(
		private string $salt,
		?callable $get = null,
		?callable $set = null,
		?callable $now = null,
		private int $max = self::MAX_ATTEMPTS,
		private int $window = self::WINDOW
	) {
		$this->get = $get ?? static fn ( string $key ) => get_transient( $key );
		$this->set = $set ?? static function ( string $key, $value, int $ttl ): void {
			set_transient( $key, $value, $ttl );
		};
		$this->now = $now ?? static fn (): int => time();
	}

	/** Anonymisierter Schlüssel (kein Klartext, nicht umkehrbar ohne Salt). */
	public function clientKey( string $clientIdentifier ): string {
		return 'psl_rl_' . substr( hash_hmac( 'sha256', $clientIdentifier, $this->salt ), 0, 32 );
	}

	/** Zählt einen Versuch; false, wenn das Limit im laufenden Fenster überschritten ist. */
	public function attempt( string $clientIdentifier ): bool {
		$key   = $this->clientKey( $clientIdentifier );
		$now   = ( $this->now )();
		$entry = ( $this->get )( $key );

		if ( ! is_array( $entry ) || ! isset( $entry['start'], $entry['count'] ) || $now - (int) $entry['start'] >= $this->window ) {
			$entry = [ 'start' => $now, 'count' => 0 ];
		}
		if ( (int) $entry['count'] >= $this->max ) {
			return false;
		}
		$entry['count'] = (int) $entry['count'] + 1;
		( $this->set )( $key, $entry, max( 1, $this->window - ( $now - (int) $entry['start'] ) ) );
		return true;
	}
}
