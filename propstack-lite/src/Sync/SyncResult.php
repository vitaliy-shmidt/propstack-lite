<?php

namespace PropstackLite\Sync;

/** Ergebnis eines Sync-Laufs (für CLI, Admin-Anzeige und Sync-Status). */
final class SyncResult {

	public const OK      = 'ok';
	public const ERROR   = 'error';
	public const LOCKED  = 'locked';
	public const SKIPPED = 'skipped';

	public const COUNTERS = [ 'fetched', 'inserted', 'updated', 'unchanged', 'sold', 'removed', 'kept', 'ignored', 'invalid', 'purged', 'reconciled' ];

	public array $counts;

	public function __construct(
		public readonly string $type,
		public string $status = self::OK,
		public string $message = '',
		public int $durationMs = 0,
		public string $code = ''
	) {
		$this->counts = array_fill_keys( self::COUNTERS, 0 );
	}

	public function add( string $counter, int $n = 1 ): void {
		$this->counts[ $counter ] = ( $this->counts[ $counter ] ?? 0 ) + $n;
	}

	public function isOk(): bool {
		return self::OK === $this->status;
	}

	public function summary(): string {
		$parts = [];
		foreach ( $this->counts as $key => $n ) {
			if ( $n > 0 ) {
				$parts[] = "{$key}={$n}";
			}
		}
		return sprintf(
			'%s-Sync %s%s (%d ms)%s%s',
			$this->type,
			$this->status,
			'' === $this->code ? '' : ' [' . $this->code . ']',
			$this->durationMs,
			[] === $parts ? '' : ': ' . implode( ', ', $parts ),
			'' === $this->message ? '' : ' – ' . $this->message
		);
	}

	public function toArray(): array {
		return [
			'type'        => $this->type,
			'status'      => $this->status,
			'message'     => $this->message,
			'code'        => $this->code,
			'duration_ms' => $this->durationMs,
			'counts'      => $this->counts,
		];
	}
}
