<?php

namespace PropstackLite\Support;

/** Zeitquelle (UTC), in Tests fixierbar. */
final class Clock {

	public function __construct( private ?\DateTimeImmutable $fixed = null ) {}

	public function now(): \DateTimeImmutable {
		return ( $this->fixed ?? new \DateTimeImmutable( 'now' ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/** Format für DATETIME-Spalten (UTC). */
	public function mysql( ?\DateTimeImmutable $time = null ): string {
		return ( $time ?? $this->now() )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
