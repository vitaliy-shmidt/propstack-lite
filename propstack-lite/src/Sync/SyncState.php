<?php

namespace PropstackLite\Sync;

/**
 * Persistenter Sync-Status (Option `psl_sync_state`, nicht autoloaded):
 * Zeitpunkte der letzten Läufe, Cursor für den inkrementellen Sync, letzter Fehler.
 * Fehlertexte stammen aus ApiException bzw. festen Meldungen und enthalten keine Secrets.
 */
final class SyncState {

	public const OPTION = 'psl_sync_state';

	public function get(): array {
		$state = get_option( self::OPTION, [] );
		return is_array( $state ) ? $state : [];
	}

	public function incrementalCursor(): ?string {
		$cursor = $this->get()['incremental_cursor'] ?? null;
		return is_string( $cursor ) && '' !== $cursor ? $cursor : null;
	}

	public function recordSuccess( SyncResult $result, string $startedAt ): void {
		$state                                   = $this->get();
		$state[ 'last_' . $result->type . '_at' ] = $startedAt;
		$state['last_success_at']                = $startedAt;
		$state['last_result']                    = $result->toArray();
		$state['incremental_cursor']             = $startedAt;
		update_option( self::OPTION, $state, false );
	}

	public function recordError( SyncResult $result, string $at ): void {
		$state                  = $this->get();
		$state['last_error_at'] = $at;
		$state['last_error']      = mb_substr( $result->type . ': ' . $result->message, 0, 300 );
		$state['last_error_code'] = $result->code;
		$state['last_result']   = $result->toArray();
		update_option( self::OPTION, $state, false );
	}

	/** Stabiler Fehlercode des letzten fehlgeschlagenen Laufs (siehe Support\ErrorCode) oder ''. */
	public function lastErrorCode(): string {
		return $this->hasUnresolvedError() ? (string) ( $this->get()['last_error_code'] ?? '' ) : '';
	}

	/** War der letzte (nicht durch Lock übersprungene) Lauf erfolglos? */
	public function hasUnresolvedError(): bool {
		$status = $this->get()['last_result']['status'] ?? null;
		return in_array( $status, [ SyncResult::ERROR, SyncResult::SKIPPED ], true );
	}

	public function reset(): void {
		delete_option( self::OPTION );
	}
}
