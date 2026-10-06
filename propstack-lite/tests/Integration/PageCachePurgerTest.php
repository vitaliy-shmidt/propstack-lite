<?php

namespace PropstackLite\Tests\Integration;

use PropstackLite\Support\PageCachePurger;
use PropstackLite\Sync\SyncResult;

/** Full-Page-Cache wird nur bei sichtbaren Bestandsänderungen und Einstellungsänderungen geleert. */
final class PageCachePurgerTest extends IntegrationTestCase {

	private int $purges = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->purges = 0;
		add_action( 'psl_purge_page_cache', [ $this, 'onPurge' ] );
	}

	protected function tearDown(): void {
		remove_action( 'psl_purge_page_cache', [ $this, 'onPurge' ] );
		parent::tearDown();
	}

	public function onPurge(): void {
		$this->purges++;
	}

	private static function syncResult( array $counts, string $status = SyncResult::OK ): SyncResult {
		$r = new SyncResult( 'incremental', $status );
		foreach ( $counts as $k => $n ) {
			$r->add( $k, $n );
		}
		return $r;
	}

	public function test_relevant_changes(): void {
		foreach ( [ 'inserted', 'updated', 'sold', 'removed', 'purged', 'reconciled' ] as $counter ) {
			$this->assertTrue( PageCachePurger::hasRelevantChanges( self::syncResult( [ $counter => 1 ] ) ), $counter );
		}
		$this->assertFalse( PageCachePurger::hasRelevantChanges( self::syncResult( [ 'fetched' => 9, 'unchanged' => 9, 'kept' => 2, 'ignored' => 1 ] ) ) );
		$this->assertFalse( PageCachePurger::hasRelevantChanges( self::syncResult( [ 'sold' => 1 ], SyncResult::ERROR ) ), 'fehlgeschlagener Sync leert nichts' );
		$this->assertFalse( PageCachePurger::hasRelevantChanges( null ) );
	}

	public function test_sync_hook_and_settings_change_purge(): void {
		do_action( 'psl_sync_finished', self::syncResult( [ 'unchanged' => 3 ] ) );
		$this->assertSame( 0, $this->purges, 'Sync ohne Änderungen' );
		do_action( 'psl_sync_finished', self::syncResult( [ 'sold' => 1 ] ) );
		$this->assertSame( 1, $this->purges, 'Sync mit Verkauf' );
		$this->settings( [ 'public_status_ids' => [ 4711 ] ] );
		$this->assertSame( 2, $this->purges, 'Einstellungen gespeichert' );
	}
}
