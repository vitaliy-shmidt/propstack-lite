<?php

namespace PropstackLite\Tests\Integration;

use PropstackLite\Settings;

/**
 * Update aus 0.2.x ohne Aktivierungs-Hook (ZIP-Upload „Version ersetzen“, FTP):
 * Settings::maybeMigrateLegacy() muss die Migration beim Plugin-Start nachholen.
 */
final class LegacyMigrationTest extends IntegrationTestCase {

	private const LEGACY = [
		'api_key'             => 'legacy-key-not-real',
		'endpoint'            => 'https://api.propstack.de/v1/units',
		'cache_minutes'       => 15,
		'webhook_token'       => 'legacytoken123',
		'query_params'        => 'status=207633&per=50',
		'detail_url_template' => '/immobilie/?ps_id={id}',
		'dev_no_cache'        => 0,
	];

	protected function tearDown(): void {
		delete_option( 'propstack_lite_cache_salt' );
		delete_transient( 'propstack_lite_cache_test' );
		parent::tearDown();
	}

	public function test_legacy_settings_are_migrated_on_boot(): void {
		update_option( Settings::OPTION, self::LEGACY );
		add_option( 'propstack_lite_cache_salt', 'abc12345' );
		set_transient( 'propstack_lite_cache_test', 'x', 600 );

		$this->assertTrue( Settings::maybeMigrateLegacy() );

		$stored = get_option( Settings::OPTION );
		$this->assertSame( [ 207633 ], $stored['public_status_ids'] );
		$this->assertSame( 'legacy-key-not-real', $stored['api_key'], 'API-Key bleibt erhalten' );
		$this->assertSame( 'legacytoken123', $stored['webhook_token'], 'Webhook-Token bleibt erhalten' );
		foreach ( [ 'endpoint', 'query_params', 'cache_minutes', 'detail_url_template', 'dev_no_cache' ] as $key ) {
			$this->assertArrayNotHasKey( $key, $stored );
		}
		$this->assertFalse( get_option( 'propstack_lite_cache_salt' ) );
		wp_cache_flush(); // Migration löscht per SQL; der In-Request-Objektcache kennt den Transient noch
		$this->assertFalse( get_transient( 'propstack_lite_cache_test' ) );

		$this->assertFalse( Settings::maybeMigrateLegacy(), 'idempotent: zweiter Start migriert nichts' );
		$this->assertSame( $stored, get_option( Settings::OPTION ) );
	}

	public function test_current_settings_are_left_untouched(): void {
		$this->settings( [ 'public_status_ids' => [ 701 ], 'webhook_token' => 'tok' ] );
		$before = get_option( Settings::OPTION );
		$this->assertFalse( Settings::maybeMigrateLegacy() );
		$this->assertSame( $before, get_option( Settings::OPTION ) );
	}
}
