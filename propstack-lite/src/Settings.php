<?php

namespace PropstackLite;

/**
 * Plugin-Einstellungen (Option `propstack_lite_settings`).
 *
 * Der API-Key kann alternativ als Konstante `PSL_API_KEY` in wp-config.php gesetzt werden;
 * die Konstante hat Vorrang und wird nie in der Datenbank gespeichert.
 */
final class Settings {

	public const OPTION = 'propstack_lite_settings';

	public const MIN_INTERVAL = 5;
	public const MAX_INTERVAL = 1440;

	private const DEFAULTS = [
		'api_key'             => '',
		'public_status_ids'   => [],
		'sold_status_ids'     => [],
		'reserved_status_ids' => [],
		'sync_interval'       => 15,
		'webhook_token'       => '',
	];

	/** Einstellungen aus Version 0.2.x, die bei der Migration entfernt werden. */
	private const LEGACY_KEYS = [ 'endpoint', 'query_params', 'cache_minutes', 'detail_url_template', 'dev_no_cache' ];

	private ?array $cache = null;

	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, [] );
			$this->cache = array_merge( self::DEFAULTS, is_array( $stored ) ? array_intersect_key( $stored, self::DEFAULTS ) : [] );
		}
		return $this->cache;
	}

	public function flush(): void {
		$this->cache = null;
	}

	public function apiKey(): string {
		if ( self::apiKeyFromConstant() ) {
			return trim( (string) PSL_API_KEY );
		}
		return trim( (string) $this->all()['api_key'] );
	}

	public static function apiKeyFromConstant(): bool {
		return defined( 'PSL_API_KEY' ) && '' !== trim( (string) PSL_API_KEY );
	}

	public function hasApiKey(): bool {
		return '' !== $this->apiKey();
	}

	/** @return list<int> */
	public function publicStatusIds(): array {
		return self::intList( $this->all()['public_status_ids'] );
	}

	/** @return list<int> */
	public function soldStatusIds(): array {
		return self::intList( $this->all()['sold_status_ids'] );
	}

	/** @return list<int> */
	public function reservedStatusIds(): array {
		return self::intList( $this->all()['reserved_status_ids'] );
	}

	public function syncInterval(): int {
		return self::clampInterval( $this->all()['sync_interval'] );
	}

	public function webhookToken(): string {
		return (string) $this->all()['webhook_token'];
	}

	/**
	 * sanitize_callback der Settings API.
	 * Ein leeres API-Key-Feld behält den gespeicherten Key (Passwortfeld wird nie vorbefüllt).
	 */
	public function sanitize( mixed $input ): array {
		$input   = is_array( $input ) ? $input : [];
		$current = $this->all();

		$apiKey = $current['api_key'];
		if ( ! empty( $input['api_key_clear'] ) ) {
			$apiKey = '';
		} elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
			$apiKey = sanitize_text_field( wp_unslash( (string) $input['api_key'] ) );
		}

		$token = $current['webhook_token'];
		if ( isset( $input['webhook_token'] ) ) {
			$token = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['webhook_token'] ) ?? '';
		}

		$clean = [
			'api_key'             => $apiKey,
			'public_status_ids'   => self::intList( $input['public_status_ids'] ?? [] ),
			'sold_status_ids'     => self::intList( $input['sold_status_ids'] ?? [] ),
			'reserved_status_ids' => self::intList( $input['reserved_status_ids'] ?? [] ),
			'sync_interval'       => self::clampInterval( $input['sync_interval'] ?? self::DEFAULTS['sync_interval'] ),
			'webhook_token'       => $token,
		];

		$this->cache = null;
		return $clean;
	}

	/** Schreibt Werte direkt (CLI/Tests) – ohne Settings-API-Formular. */
	public function update( array $values ): void {
		$merged = array_merge( $this->all(), array_intersect_key( $values, self::DEFAULTS ) );
		foreach ( [ 'public_status_ids', 'sold_status_ids', 'reserved_status_ids' ] as $key ) {
			$merged[ $key ] = self::intList( $merged[ $key ] );
		}
		$merged['sync_interval'] = self::clampInterval( $merged['sync_interval'] );
		update_option( self::OPTION, $merged, false );
		$this->cache = null;
	}

	/**
	 * Migration von 0.2.x: übernimmt `status=` aus den alten Default-Query-Params als
	 * öffentliche Status und entfernt veraltete Einstellungen, Cache-Salt und Transients.
	 */
	public static function migrateLegacy(): void {
		$stored = get_option( self::OPTION, null );
		if ( is_array( $stored ) && array_intersect( self::LEGACY_KEYS, array_keys( $stored ) ) ) {
			$public = self::intList( $stored['public_status_ids'] ?? [] );
			if ( [] === $public && ! empty( $stored['query_params'] ) ) {
				parse_str( (string) $stored['query_params'], $qs );
				$status = $qs['status'] ?? [];
				$public = self::intList( is_array( $status ) ? $status : explode( ',', (string) $status ) );
			}
			$migrated                      = array_diff_key( $stored, array_flip( self::LEGACY_KEYS ) );
			$migrated['public_status_ids'] = $public;
			update_option( self::OPTION, array_merge( self::DEFAULTS, array_intersect_key( $migrated, self::DEFAULTS ) ), false );
		}

		delete_option( 'propstack_lite_cache_salt' );

		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_propstack\_lite\_cache%' OR option_name LIKE '\_transient\_timeout\_propstack\_lite\_cache%'"
		);
	}

	/** @return list<int> */
	public static function intList( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			$value = '' === $value || null === $value ? [] : [ $value ];
		}
		$ids = [];
		foreach ( $value as $v ) {
			if ( is_numeric( $v ) && (int) $v > 0 ) {
				$ids[] = (int) $v;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private static function clampInterval( mixed $value ): int {
		return max( self::MIN_INTERVAL, min( self::MAX_INTERVAL, (int) $value ) );
	}
}
