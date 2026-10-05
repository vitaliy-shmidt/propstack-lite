<?php

namespace PropstackLite\Tests\Integration;

use PropstackLite\Api\Client;
use PropstackLite\Api\UnitsEndpoint;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Storage\ListCriteria;
use PropstackLite\Storage\PropertyStore;
use PropstackLite\Support\Clock;
use PropstackLite\Support\Logger;
use PropstackLite\Sync\SyncLock;
use PropstackLite\Sync\SyncResult;
use PropstackLite\Sync\SyncService;
use PropstackLite\Sync\SyncState;

final class SyncServiceTest extends IntegrationTestCase {

	private const PUBLIC = 501;
	private const SOLD   = 502;
	private const OTHER  = 503;

	private FakePropstack $api;

	protected function setUp(): void {
		parent::setUp();
		$this->api = new FakePropstack();
		$this->settings( [ 'public_status_ids' => [ self::PUBLIC ], 'sold_status_ids' => [ self::SOLD ] ] );
	}

	private function service( string $now = '2026-10-05 12:00:00' ): SyncService {
		return new SyncService(
			new UnitsEndpoint( new Client( 'test', $this->api->transport, static fn () => null ) ),
			new PropertyMapper(),
			PropertyStore::create(),
			$this->settings( [] ),
			new SyncLock(),
			new SyncState(),
			new Logger(),
			new Clock( new \DateTimeImmutable( $now, new \DateTimeZone( 'UTC' ) ) )
		);
	}

	private function visibleIds(): array {
		$result = $this->store->queryPublic( [ self::PUBLIC ], new ListCriteria( sortBy: 'price', order: 'asc', perPage: 100 ) );
		return array_map( static fn ( $p ) => $p->id, $result['items'] );
	}

	public function test_full_sync_stores_only_public_objects(): void {
		$this->api->put( self::raw( 1, self::PUBLIC ) );
		$this->api->put( self::raw( 2, self::PUBLIC ) );
		$this->api->put( self::raw( 3, self::OTHER ) );
		$this->api->put( self::raw( 4, self::SOLD ) );

		$result = $this->service()->runFull();

		$this->assertTrue( $result->isOk(), $result->summary() );
		$this->assertSame( 2, $result->counts['inserted'] );
		$this->assertSame( [ 1, 2 ], $this->visibleIds() );
		$this->assertNull( $this->store->find( 3 ), 'nie öffentlich → nie gespeichert' );
		$this->assertNull( $this->store->find( 4 ), 'verkauft, aber nie öffentlich → nie gespeichert' );

		$again = $this->service()->runFull();
		$this->assertSame( 2, $again->counts['unchanged'] );
	}

	public function test_sold_object_is_kept_30_days_then_data_purged(): void {
		$this->api->put( self::raw( 1, self::PUBLIC ) );
		$this->service( '2026-10-01 10:00:00' )->runFull();

		$this->api->put( self::raw( 1, self::SOLD ) );
		$result = $this->service( '2026-10-02 10:00:00' )->runFull();
		$this->assertSame( 1, $result->counts['sold'] );

		$row = $this->store->find( 1 );
		$this->assertSame( 'sold', $row->state );
		$this->assertSame( '2026-10-02 10:00:00', $row->soldAt );
		$this->assertNotNull( $row->property, 'Daten bleiben für die 30-Tage-Anzeige' );
		$this->assertSame( [], $this->visibleIds(), 'verkauft → nicht mehr in der Liste' );

		// Status wechselt später auf „inaktiv“: 30-Tage-Frist läuft unverändert weiter.
		$this->api->put( self::raw( 1, self::OTHER, [ 'archived' => true ] ) );
		$this->service( '2026-10-10 10:00:00' )->runIncremental();
		$this->assertSame( 'sold', $this->store->find( 1 )->state );
		$this->assertSame( '2026-10-02 10:00:00', $this->store->find( 1 )->soldAt );

		$this->service( '2026-11-02 10:00:01' )->runFull();
		$row = $this->store->find( 1 );
		$this->assertSame( 'sold', $row->state );
		$this->assertNull( $row->property, 'nach 30 Tagen: Daten entfernt, Zeile bleibt für 410' );
	}

	public function test_status_change_to_non_public_removes_immediately(): void {
		$this->api->put( self::raw( 1, self::PUBLIC ) );
		$this->service()->runFull();

		$this->api->put( self::raw( 1, self::OTHER ) );
		$result = $this->service()->runFull();

		$this->assertSame( 1, $result->counts['removed'] );
		$row = $this->store->find( 1 );
		$this->assertSame( 'removed', $row->state );
		$this->assertNull( $row->property );
		$this->assertNotNull( $row->removedAt );
	}

	public function test_deleted_object_is_removed(): void {
		$this->api->put( self::raw( 1, self::PUBLIC ) );
		$this->api->put( self::raw( 2, self::PUBLIC ) );
		$this->service()->runFull();

		$this->api->delete( 2 );
		$this->service()->runFull();

		$this->assertSame( 'removed', $this->store->find( 2 )->state );
		$this->assertSame( [ 1 ], $this->visibleIds() );
	}

	public function test_reactivation_after_removal(): void {
		$this->api->put( self::raw( 1, self::PUBLIC ) );
		$this->service()->runFull();
		$this->api->put( self::raw( 1, self::OTHER ) );
		$this->service()->runFull();
		$this->api->put( self::raw( 1, self::PUBLIC ) );
		$this->service()->runFull();

		$row = $this->store->find( 1 );
		$this->assertSame( 'active', $row->state );
		$this->assertNull( $row->removedAt );
		$this->assertNotNull( $row->property );
	}

	public function test_api_failure_leaves_store_untouched(): void {
		$this->api->put( self::raw( 1, self::PUBLIC ) );
		$this->service()->runFull();

		$this->api->failLists = true;
		$result               = $this->service()->runFull();

		$this->assertSame( SyncResult::ERROR, $result->status );
		$this->assertSame( [ 1 ], $this->visibleIds(), 'Bestand bleibt bei API-Ausfall online' );
		$this->assertTrue( ( new SyncState() )->hasUnresolvedError() );
		$this->assertFalse( ( new SyncLock() )->isLocked(), 'Lock wird auch im Fehlerfall freigegeben' );
	}

	public function test_without_public_statuses_nothing_happens(): void {
		$this->api->put( self::raw( 1, self::PUBLIC ) );
		$this->service()->runFull();

		$this->settings( [ 'public_status_ids' => [] ] );
		$requests = count( $this->api->transport->requests );
		$result   = $this->service()->runFull();

		$this->assertSame( SyncResult::SKIPPED, $result->status );
		$this->assertSame( $requests, count( $this->api->transport->requests ), 'kein API-Request' );
		$this->assertSame( 'active', $this->store->find( 1 )->state, 'nichts wird entfernt' );
	}

	public function test_incremental_never_stores_non_public_objects(): void {
		$this->service()->runFull(); // setzt den Cursor
		$this->api->put( self::raw( 7, self::OTHER ) );
		$this->api->put( self::raw( 8, self::PUBLIC ) );

		$result = $this->service()->runIncremental();

		$this->assertTrue( $result->isOk(), $result->summary() );
		$this->assertSame( 'incremental', $result->type );
		$this->assertNull( $this->store->find( 7 ) );
		$this->assertSame( 'active', $this->store->find( 8 )->state );

		$q = \PropstackLite\Tests\Support\FakeTransport::parseQuery( (string) parse_url( end( $this->api->transport->requests )['url'], PHP_URL_QUERY ) );
		$this->assertSame( '-1', $q['archived'] );
		$this->assertSame( '2026-10-04T12:00:00Z', $q['updated_at_from'], '24 h Überlappung zum letzten Lauf' );
		$this->assertArrayNotHasKey( 'status', $q, 'inkrementell: alle Status, Klassifizierung lokal' );
	}

	public function test_lock_prevents_parallel_runs(): void {
		( new SyncLock() )->acquire();
		$result = $this->service()->runFull();
		$this->assertSame( SyncResult::LOCKED, $result->status );
	}

	public function test_reconcile_falls_back_to_single_requests_if_bulk_returns_nothing(): void {
		$this->api->put( self::raw( 1, self::PUBLIC ) );
		$this->service()->runFull();

		// Objekt wechselt Status; Sammelabfrage liefert (API-Eigenheit) gar nichts.
		$this->api->put( self::raw( 1, self::SOLD ) );
		$this->api->emptyPropertyIdsResult = true;
		$this->service()->runFull();

		$this->assertSame( 'sold', $this->store->find( 1 )->state, 'nicht fälschlich als gelöscht markiert' );
	}

	public function test_single_sync(): void {
		$this->api->put( self::raw( 5, self::PUBLIC ) );
		$result = $this->service()->syncOne( 5 );
		$this->assertTrue( $result->isOk() );
		$this->assertSame( 'active', $this->store->find( 5 )->state );

		$this->api->delete( 5 );
		$this->service()->syncOne( 5 );
		$this->assertSame( 'removed', $this->store->find( 5 )->state );
	}
}
