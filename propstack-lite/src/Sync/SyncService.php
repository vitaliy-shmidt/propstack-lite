<?php

namespace PropstackLite\Sync;

use PropstackLite\Api\ApiException;
use PropstackLite\Api\UnitsEndpoint;
use PropstackLite\Mapping\MappingException;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Settings;
use PropstackLite\Storage\PropertyStore;
use PropstackLite\Support\Clock;
use PropstackLite\Support\Logger;

/**
 * Synchronisiert Propstack → lokaler Bestand. Läuft nur in Cron, WP-CLI oder Admin-Aktionen,
 * nie in Besucher-Requests.
 *
 * - Full: alle Objekte mit öffentlichem Status; nicht mehr gelieferte, bisher aktive Objekte
 *   werden gezielt nachgeprüft (sold/removed/gelöscht).
 * - Incremental: alle seit dem letzten Lauf geänderten Objekte (jeder Status), 24 h Überlappung,
 *   da `updated_at_from` in Tests nicht sekundengenau filterte.
 * - Single: ein Objekt nach ID (CLI, später Webhook).
 *
 * Fehler während des Abrufs brechen den Lauf ab, bevor Objekte als entfernt markiert werden –
 * der vorhandene Bestand bleibt bei API-Ausfällen online.
 */
final class SyncService {

	public const SOLD_RETENTION_DAYS = 30;
	public const INCREMENTAL_OVERLAP = 'PT24H';
	public const RECONCILE_CHUNK     = 100;

	public function __construct(
		private UnitsEndpoint $units,
		private PropertyMapper $mapper,
		private PropertyStore $store,
		private Settings $settings,
		private SyncLock $lock,
		private SyncState $state,
		private Logger $logger,
		private Clock $clock
	) {}

	public function runFull(): SyncResult {
		return $this->run(
			'full',
			function ( SyncResult $result, StateResolver $resolver, string $now ): void {
				$list = $this->units->listAll( [ 'status' => $this->settings->publicStatusIds() ] );
				if ( ! $list['complete'] ) {
					throw new ApiException( 'Objektliste unvollständig geladen (Pagination). Bestand bleibt unverändert.', ApiException::INVALID_RESPONSE );
				}
				$result->add( 'fetched', count( $list['items'] ) );

				$seen = [];
				foreach ( $list['items'] as $raw ) {
					$id = PropertyMapper::idOf( $raw );
					if ( null !== $id ) {
						$seen[ $id ] = true;
					}
					$this->apply( $raw, $resolver, $result, $now );
				}

				// Bisher aktive Objekte, die nicht mehr geliefert wurden, gezielt nachprüfen.
				$missing = array_values( array_diff( $this->store->idsInState( 'active' ), array_keys( $seen ) ) );
				$this->reconcile( $missing, $resolver, $result, $now );
			}
		);
	}

	public function runIncremental(): SyncResult {
		$cursor = $this->state->incrementalCursor();
		if ( null === $cursor ) {
			return $this->runFull();
		}

		return $this->run(
			'incremental',
			function ( SyncResult $result, StateResolver $resolver, string $now ) use ( $cursor ): void {
				$from = ( new \DateTimeImmutable( $cursor, new \DateTimeZone( 'UTC' ) ) )
					->sub( new \DateInterval( self::INCREMENTAL_OVERLAP ) )
					->format( 'Y-m-d\TH:i:s\Z' );

				$list = $this->units->listAll( [ 'updated_at_from' => $from, 'archived' => -1 ] );
				if ( ! $list['complete'] ) {
					throw new ApiException( 'Änderungsliste unvollständig geladen. Bestand bleibt unverändert.', ApiException::INVALID_RESPONSE );
				}
				$result->add( 'fetched', count( $list['items'] ) );
				foreach ( $list['items'] as $raw ) {
					$this->apply( $raw, $resolver, $result, $now );
				}
			}
		);
	}

	public function syncOne( int $id ): SyncResult {
		return $this->run(
			'single',
			function ( SyncResult $result, StateResolver $resolver, string $now ) use ( $id ): void {
				$raw = $this->units->get( $id );
				if ( null === $raw ) {
					$this->applyDeleted( $id, $resolver, $result, $now );
					return;
				}
				$result->add( 'fetched' );
				$this->apply( $raw, $resolver, $result, $now );
			},
			false
		);
	}

	/**
	 * Gemeinsamer Rahmen: Konfigurationsprüfung, Lock, Fehlerbehandlung, Status, Bereinigung.
	 *
	 * @param callable(SyncResult, StateResolver, string): void $work
	 */
	private function run( string $type, callable $work, bool $recordCursor = true ): SyncResult {
		$result  = new SyncResult( $type );
		$started = microtime( true );
		$now     = $this->clock->mysql();

		$public = $this->settings->publicStatusIds();
		if ( [] === $public ) {
			$result->status  = SyncResult::SKIPPED;
			$result->message = 'Keine öffentlichen Propstack-Status konfiguriert – Bestand bleibt unverändert.';
			$this->state->recordError( $result, $now );
			return $result;
		}

		if ( ! $this->lock->acquire() ) {
			$result->status  = SyncResult::LOCKED;
			$result->message = 'Ein anderer Sync-Lauf ist aktiv.';
			return $result;
		}

		try {
			$resolver = new StateResolver( $public, $this->settings->soldStatusIds() );
			$work( $result, $resolver, $now );

			$cutoff = $this->clock->mysql( $this->clock->now()->sub( new \DateInterval( 'P' . self::SOLD_RETENTION_DAYS . 'D' ) ) );
			$result->add( 'purged', $this->store->purgeSoldBefore( $cutoff ) );

			$result->durationMs = (int) round( ( microtime( true ) - $started ) * 1000 );
			if ( $recordCursor ) {
				$this->state->recordSuccess( $result, $now );
			}
			$this->logger->info( $result->summary() );
		} catch ( ApiException $e ) {
			$this->fail( $result, $e->getMessage(), $started, $now );
		} catch ( \Throwable $e ) {
			// Unerwartete Fehler: nur Klasse + Meldung, keine Rohdaten.
			$this->fail( $result, get_class( $e ) . ': ' . $e->getMessage(), $started, $now );
		} finally {
			$this->lock->release();
		}

		return $result;
	}

	private function fail( SyncResult $result, string $message, float $started, string $now ): void {
		$result->status     = SyncResult::ERROR;
		$result->message    = mb_substr( $message, 0, 300 );
		$result->durationMs = (int) round( ( microtime( true ) - $started ) * 1000 );
		$this->state->recordError( $result, $now );
		$this->logger->error( $result->summary() );
	}

	private function apply( array $raw, StateResolver $resolver, SyncResult $result, string $now ): void {
		$id = PropertyMapper::idOf( $raw );
		if ( null === $id ) {
			$result->add( 'invalid' );
			return;
		}

		$statusId = PropertyMapper::statusIdOf( $raw );
		$action   = $resolver->resolve( $statusId, PropertyMapper::archivedOf( $raw ), false, $this->store->stateOf( $id ) );

		switch ( $action ) {
			case StateResolver::UPSERT:
				try {
					$property = $this->mapper->map( $raw );
				} catch ( MappingException ) {
					$result->add( 'invalid' );
					$this->logger->warning( 'Objekt konnte nicht gemappt werden', [ 'id' => $id ] );
					return;
				}
				$result->add( $this->store->upsertActive( $property, $now ) );
				break;
			case StateResolver::SOLD:
				$this->store->markSold( $id, $statusId, $now );
				$result->add( 'sold' );
				break;
			case StateResolver::REMOVE:
				$this->store->markRemoved( $id, $statusId, $now );
				$result->add( 'removed' );
				break;
			case StateResolver::KEEP:
				$result->add( 'kept' );
				break;
			default:
				$result->add( 'ignored' );
		}
	}

	private function applyDeleted( int $id, StateResolver $resolver, SyncResult $result, string $now ): void {
		if ( StateResolver::REMOVE === $resolver->resolve( null, false, true, $this->store->stateOf( $id ) ) ) {
			$this->store->markRemoved( $id, null, $now );
			$result->add( 'removed' );
			return;
		}
		$result->add( 'ignored' );
	}

	/**
	 * Prüft bisher aktive Objekte nach, die der Voll-Sync nicht mehr geliefert hat.
	 * Ein Request pro 100 IDs (inkl. archivierter); nicht gelieferte IDs gelten als gelöscht.
	 *
	 * @param list<int> $ids
	 */
	private function reconcile( array $ids, StateResolver $resolver, SyncResult $result, string $now ): void {
		foreach ( array_chunk( $ids, self::RECONCILE_CHUNK ) as $chunk ) {
			$list = $this->units->listAll( [ 'property_ids' => $chunk, 'archived' => -1 ] );
			if ( ! $list['complete'] ) {
				throw new ApiException( 'Nachprüfung unvollständig geladen. Bestand bleibt unverändert.', ApiException::INVALID_RESPONSE );
			}
			$found = [];
			foreach ( $list['items'] as $raw ) {
				$id = PropertyMapper::idOf( $raw );
				if ( null === $id || ! in_array( $id, $chunk, true ) ) {
					continue;
				}
				$found[ $id ] = true;
				$result->add( 'reconciled' );
				$this->apply( $raw, $resolver, $result, $now );
			}
			foreach ( $chunk as $id ) {
				if ( isset( $found[ $id ] ) ) {
					continue;
				}
				$result->add( 'reconciled' );
				// Sicherheitsnetz: Liefert der Sammelabruf gar nichts, jede ID einzeln prüfen,
				// statt aufgrund einer API-Eigenheit den ganzen Block als gelöscht zu markieren.
				$raw = [] === $found ? $this->units->get( $id ) : null;
				if ( null !== $raw ) {
					$this->apply( $raw, $resolver, $result, $now );
					continue;
				}
				$this->applyDeleted( $id, $resolver, $result, $now );
			}
		}
	}
}
