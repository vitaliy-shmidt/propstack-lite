<?php

namespace PropstackLite\Tests\Integration;

use PropstackLite\Admin\Diagnostics;
use PropstackLite\Admin\SiteHealth;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Plugin;
use PropstackLite\Storage\Schema;
use PropstackLite\Sync\Scheduler;
use PropstackLite\Sync\SyncLock;
use PropstackLite\Sync\SyncResult;
use PropstackLite\Sync\SyncState;

/** Phase 8: Schema-Diagnose, Downgrade-Schutz, Site Health, Lock, Cron-Planung ohne Dubletten. */
final class OperationsIntegrationTest extends IntegrationTestCase {

	public function test_schema_status_is_healthy(): void {
		$status = Schema::status();
		$this->assertTrue( $status['table'] );
		$this->assertSame( [], $status['missingColumns'] );
		$this->assertSame( Schema::VERSION, $status['installed'] );
		$this->assertFalse( Schema::isNewerThanCode() );
	}

	public function test_newer_schema_blocks_sync_without_touching_data(): void {
		$this->settings( [ 'public_status_ids' => [ 801 ] ] );
		$this->store->upsertActive( ( new PropertyMapper() )->map( self::raw( 1, 801 ) ), '2026-10-08 10:00:00' );
		$calls = 0;
		$spy   = static function ( $pre ) use ( &$calls ) {
			++$calls;
			return $pre;
		};
		add_filter( 'pre_http_request', $spy );
		update_option( Schema::VERSION_OPTION, Schema::VERSION + 1 );
		try {
			Schema::maybeUpgrade(); // darf nichts tun
			$result = Plugin::instance()->syncService()->runFull();
		} finally {
			update_option( Schema::VERSION_OPTION, Schema::VERSION );
			remove_filter( 'pre_http_request', $spy );
		}

		$this->assertSame( SyncResult::SKIPPED, $result->status );
		$this->assertSame( 'schema_newer', $result->code );
		$this->assertSame( 0, $calls, 'kein API-Request' );
		$this->assertSame( 1, $this->store->countsByState()['active'], 'Bestand unverändert' );
		$this->assertSame( 'schema_newer', ( new SyncState() )->lastErrorCode() );
	}

	public function test_locked_sync_reports_code_and_lock_refresh(): void {
		$this->settings( [ 'public_status_ids' => [ 801 ] ] );
		$lock = new SyncLock();
		$this->assertTrue( $lock->acquire() );
		try {
			$result = Plugin::instance()->syncService()->runFull();
			$this->assertSame( SyncResult::LOCKED, $result->status );
			$this->assertSame( 'sync_locked', $result->code );

			update_option( SyncLock::OPTION, time() + 5, false );
			$lock->refresh();
			$this->assertGreaterThan( time() + SyncLock::TTL - 5, (int) get_option( SyncLock::OPTION ) );
			$this->assertNotNull( $lock->heartbeat() );
		} finally {
			$lock->release();
		}
		$lock->refresh();
		$this->assertFalse( get_option( SyncLock::OPTION, false ), 'refresh legt keinen Lock neu an' );
	}

	public function test_diagnostics_and_site_health_without_secrets(): void {
		$this->settings( [ 'public_status_ids' => [ 801 ] ] );
		$diagnostics = new Diagnostics( Plugin::instance()->settings() );
		$facts       = $diagnostics->facts();
		$summary     = Diagnostics::summary( $facts );

		$this->assertSame( PSL_VERSION, $summary['Plugin-Version'] );
		$this->assertStringContainsString( (string) Schema::VERSION, $summary['Datenbankschema'] );
		if ( defined( 'PSL_API_KEY' ) && '' !== PSL_API_KEY ) {
			$this->assertStringNotContainsString( PSL_API_KEY, (string) wp_json_encode( [ $facts, $summary ] ), 'kein API-Key' );
		}

		$health = new SiteHealth( $diagnostics );
		$tests  = $health->tests( [ 'direct' => [] ] );
		$this->assertCount( 5, $tests['direct'] );
		foreach ( [ 'config', 'database', 'sync', 'leads', 'tracking' ] as $key ) {
			$r = $health->result( $key );
			$this->assertContains( $r['status'], [ 'good', 'recommended', 'critical' ] );
			$this->assertSame( 'propstack_lite_' . $key, $r['test'] );
		}
		$this->assertSame( 'good', $health->result( 'database' )['status'] );
		$info = $health->debugInformation( [] );
		$this->assertArrayHasKey( 'propstack-lite', $info );
	}

	public function test_scheduling_is_idempotent(): void {
		Scheduler::unschedule();
		Scheduler::schedule();
		Scheduler::schedule();
		Scheduler::scheduleFullSoon();
		Scheduler::scheduleFullSoon();

		$counts = [];
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				$counts[ $hook ] = ( $counts[ $hook ] ?? 0 ) + 1;
			}
		}
		$this->assertSame( 1, $counts[ Scheduler::HOOK_INCREMENTAL ] ?? 0 );
		$this->assertSame( 1, $counts[ Scheduler::HOOK_FULL ] ?? 0 );
		$this->assertSame( 1, $counts[ Scheduler::HOOK_FULL_ONCE ] ?? 0 );
		wp_clear_scheduled_hook( Scheduler::HOOK_FULL_ONCE );
	}
}
