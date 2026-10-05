<?php

namespace PropstackLite\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PropstackLite\Plugin;
use PropstackLite\Settings;
use PropstackLite\Storage\PropertyStore;
use PropstackLite\Storage\Schema;
use PropstackLite\Sync\SyncLock;
use PropstackLite\Sync\SyncState;

/**
 * Basis für Tests gegen eine WordPress-Wegwerfinstanz (PSL_WP_LOAD).
 * Sichert Plugin-Optionen, leert die Tabelle und stellt danach alles wieder her.
 */
abstract class IntegrationTestCase extends TestCase {

	private mixed $settingsBackup;
	private mixed $stateBackup;

	protected PropertyStore $store;

	protected function setUp(): void {
		if ( ! function_exists( 'add_action' ) || ! class_exists( Plugin::class ) || ! defined( 'PSL_VERSION' ) ) {
			$this->markTestSkipped( 'WordPress mit aktivem Plugin erforderlich (PSL_WP_LOAD setzen).' );
		}
		Schema::maybeUpgrade();
		$this->settingsBackup = get_option( Settings::OPTION, null );
		$this->stateBackup    = get_option( SyncState::OPTION, null );
		$this->store          = PropertyStore::create();
		$this->store->truncate();
		( new SyncState() )->reset();
		( new SyncLock() )->release();
	}

	protected function tearDown(): void {
		$this->store->truncate();
		null === $this->settingsBackup ? delete_option( Settings::OPTION ) : update_option( Settings::OPTION, $this->settingsBackup, false );
		null === $this->stateBackup ? delete_option( SyncState::OPTION ) : update_option( SyncState::OPTION, $this->stateBackup, false );
		( new SyncLock() )->release();
		Plugin::instance()->settings()->flush();
	}

	protected function settings( array $values ): Settings {
		$settings = Plugin::instance()->settings();
		$settings->update( $values );
		return $settings;
	}

	/** Roh-Datensatz im Propstack-Format `expand=1`. */
	protected static function raw( int $id, int $statusId, array $overrides = [] ): array {
		return array_merge(
			[
				'id'              => $id,
				'title'           => [ 'label' => 'Überschrift', 'value' => "Objekt {$id}" ],
				'property_status' => [ 'id' => $statusId, 'name' => "Status {$statusId}" ],
				'archived'        => false,
				'marketing_type'  => 'BUY',
				'rs_type'         => 'APARTMENT',
				'hide_address'    => false,
				'street'          => 'Teststraße',
				'house_number'    => (string) $id,
				'zip_code'        => '10115',
				'city'            => 'Berlin',
				'price'           => [ 'label' => 'Preis', 'value' => 100000.0 + $id ],
				'living_space'    => [ 'label' => 'Wohnfläche', 'value' => 50 + ( $id % 50 ) ],
				'number_of_rooms' => [ 'label' => 'Zimmer', 'value' => 2 ],
				'created_at'      => '2026-01-01T10:00:00Z',
				'updated_at'      => '2026-10-01T10:00:00Z',
				'images'          => [
					[ 'id' => $id * 10, 'position' => 1, 'is_private' => false, 'is_floorplan' => false, 'medium_url' => "https://images.propstack.de/p/{$id}-m.jpg", 'url' => "https://images.propstack.de/p/{$id}.jpg" ],
					[ 'id' => $id * 10 + 1, 'position' => 0, 'is_private' => true, 'is_floorplan' => false, 'medium_url' => "https://images.propstack.de/p/{$id}-PRIVATE.jpg" ],
				],
			],
			$overrides
		);
	}
}
