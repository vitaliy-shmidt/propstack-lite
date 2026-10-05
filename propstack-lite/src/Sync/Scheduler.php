<?php

namespace PropstackLite\Sync;

use PropstackLite\Settings;

/**
 * WP-Cron-Planung.
 *
 * - `psl_sync_incremental`: alle N Minuten (Einstellung „Sync-Intervall“)
 * - `psl_sync_full`:        alle 6 Stunden (Sicherheitsnetz für Löschungen/Statuswechsel)
 * - `psl_sync_full_once`:   einmaliger Voll-Sync, z. B. nach Änderung der Status-Einstellungen
 *
 * WP-Cron läuft nur bei Seitenaufrufen; für verlässliche Intervalle einen System-Cron einrichten
 * (siehe docs/sync.md). Der Sync selbst läuft im wp-cron.php-Request, nicht im Besucher-Request.
 */
final class Scheduler {

	public const HOOK_INCREMENTAL = 'psl_sync_incremental';
	public const HOOK_FULL        = 'psl_sync_full';
	public const HOOK_FULL_ONCE   = 'psl_sync_full_once';

	public const SCHEDULE_INTERVAL  = 'psl_interval';
	public const SCHEDULE_SIX_HOURS = 'psl_six_hours';

	public function __construct( private Settings $settings ) {}

	public function register( callable $runIncremental, callable $runFull ): void {
		add_filter( 'cron_schedules', [ $this, 'addSchedules' ] );
		add_action( self::HOOK_INCREMENTAL, $runIncremental );
		add_action( self::HOOK_FULL, $runFull );
		add_action( self::HOOK_FULL_ONCE, $runFull );
		add_action( 'update_option_' . Settings::OPTION, [ $this, 'onSettingsChanged' ], 10, 2 );
		add_action( 'add_option_' . Settings::OPTION, [ $this, 'onSettingsAdded' ], 10, 2 );
	}

	public function addSchedules( array $schedules ): array {
		$schedules[ self::SCHEDULE_INTERVAL ]  = [
			'interval' => $this->settings->syncInterval() * MINUTE_IN_SECONDS,
			'display'  => 'Propstack Lite: Sync-Intervall',
		];
		$schedules[ self::SCHEDULE_SIX_HOURS ] = [
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => 'Propstack Lite: alle 6 Stunden',
		];
		return $schedules;
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::HOOK_INCREMENTAL ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE_INTERVAL, self::HOOK_INCREMENTAL );
		}
		if ( ! wp_next_scheduled( self::HOOK_FULL ) ) {
			wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, self::SCHEDULE_SIX_HOURS, self::HOOK_FULL );
		}
	}

	public static function unschedule(): void {
		foreach ( [ self::HOOK_INCREMENTAL, self::HOOK_FULL, self::HOOK_FULL_ONCE ] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	public static function scheduleFullSoon(): void {
		if ( ! wp_next_scheduled( self::HOOK_FULL_ONCE ) ) {
			wp_schedule_single_event( time() + 10, self::HOOK_FULL_ONCE );
		}
	}

	/** Intervall geändert → neu planen; Status-Auswahl geändert → zeitnah Voll-Sync. */
	public function onSettingsChanged( mixed $old, mixed $new ): void {
		$this->settings->flush();
		$old = is_array( $old ) ? $old : [];
		$new = is_array( $new ) ? $new : [];

		if ( ( $old['sync_interval'] ?? null ) !== ( $new['sync_interval'] ?? null ) ) {
			wp_clear_scheduled_hook( self::HOOK_INCREMENTAL );
			self::schedule();
		}
		foreach ( [ 'public_status_ids', 'sold_status_ids', 'api_key' ] as $key ) {
			if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
				self::scheduleFullSoon();
				break;
			}
		}
	}

	public function onSettingsAdded( string $option, mixed $value ): void {
		$this->onSettingsChanged( [], $value );
	}
}
