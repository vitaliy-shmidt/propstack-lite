<?php

namespace PropstackLite\Sync;

/**
 * Verhindert parallele Sync-Läufe (Cron, CLI, Admin-Button, Webhook).
 *
 * `add_option()` ist atomar (UNIQUE auf option_name). Ein hängengebliebener Lock
 * verfällt nach TTL Sekunden.
 */
final class SyncLock {

	public const OPTION = 'psl_sync_lock';
	public const TTL    = 900;

	public function acquire(): bool {
		$expires = time() + self::TTL;
		if ( add_option( self::OPTION, $expires, '', false ) ) {
			return true;
		}
		$current = (int) get_option( self::OPTION, 0 );
		if ( $current > 0 && $current < time() ) {
			delete_option( self::OPTION );
			return add_option( self::OPTION, $expires, '', false );
		}
		return false;
	}

	/**
	 * Lock verlängern (lange Läufe, z. B. großer Bestand oder langsame API): verhindert, dass nach
	 * Ablauf der TTL ein zweiter Lauf parallel startet. Nur wirksam, solange der Lock existiert.
	 */
	public function refresh(): void {
		if ( false !== get_option( self::OPTION, false ) ) {
			update_option( self::OPTION, time() + self::TTL, false );
		}
	}

	/** Unix-Zeit der letzten Lock-Erneuerung (≈ Start bzw. letzter Fortschritt) oder null. */
	public function heartbeat(): ?int {
		$current = (int) get_option( self::OPTION, 0 );
		return $current >= time() ? $current - self::TTL : null;
	}

	public function release(): void {
		delete_option( self::OPTION );
	}

	public function isLocked(): bool {
		$current = (int) get_option( self::OPTION, 0 );
		return $current >= time();
	}
}
