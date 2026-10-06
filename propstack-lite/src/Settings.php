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
		// Phase 4: Immobilienanfragen (Contact Form 7 → Propstack-Mail)
		'cf7_form_id'         => 0,
		'inquiry_email'       => '',
		'inquiry_bcc'         => '',
		'field_map'           => self::DEFAULT_FIELD_MAP,
		'cf_map'              => [],
	];

	/** Formularfelder (intern) → erwartete CF7-Feldnamen (im Admin änderbar). */
	public const DEFAULT_FIELD_MAP = [
		'salutation' => '',
		'first_name' => 'your-first-name',
		'last_name'  => 'your-last-name',
		'email'      => 'your-email',
		'phone'      => 'your-phone',
		'message'    => 'your-message',
		'consent'    => 'psl-consent',
	];

	/** Pflichtfelder der Feldzuordnung (Anrede ist optional). */
	public const REQUIRED_FIELDS = [ 'first_name', 'last_name', 'email', 'phone', 'message', 'consent' ];

	/** Werte, die später als Propstack-Custom-Fields (client_cf_*) übertragen werden können. */
	public const ATTRIBUTION_KEYS = [ 'lead_id', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'gbraid', 'wbraid' ];

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

	public function cf7FormId(): int {
		return max( 0, (int) $this->all()['cf7_form_id'] );
	}

	/** Propstack-Empfangsadresse (validiert gespeichert). */
	public function inquiryEmail(): string {
		return (string) $this->all()['inquiry_email'];
	}

	public function inquiryBcc(): string {
		return (string) $this->all()['inquiry_bcc'];
	}

	/** @return array<string, string> interner Feldname => CF7-Feldname */
	public function fieldMap(): array {
		return self::cleanFieldMap( $this->all()['field_map'] );
	}

	/** @return array<string, string> Attributionsschlüssel => Propstack-Custom-Field-Name (ohne Präfix client_cf_) */
	public function customFieldMap(): array {
		return self::cleanCustomFieldMap( $this->all()['cf_map'] );
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
			'cf7_form_id'         => self::cleanFormId( $input['cf7_form_id'] ?? 0 ),
			'inquiry_email'       => self::cleanEmail( $input['inquiry_email'] ?? '', 'inquiry_email', 'Propstack-Anfrage-E-Mail-Adresse' ),
			'inquiry_bcc'         => self::cleanEmail( $input['inquiry_bcc'] ?? '', 'inquiry_bcc', 'interne Kopie (BCC)' ),
			'field_map'           => self::cleanFieldMap( $input['field_map'] ?? [] ),
			'cf_map'              => self::cleanCustomFieldMap( $input['cf_map'] ?? [] ),
		];

		$this->cache = null;
		return $clean;
	}

	/** Formular-ID als Integer; existiert das CF7-Formular nicht, wird 0 gespeichert und ein Hinweis angezeigt. */
	private static function cleanFormId( mixed $value ): int {
		$id = absint( $value );
		if ( $id > 0 && function_exists( 'wpcf7_contact_form' ) && null === wpcf7_contact_form( $id ) ) {
			add_settings_error( self::OPTION, 'psl_cf7_form', 'Das ausgewählte Contact-Form-7-Formular existiert nicht.' );
			return 0;
		}
		return $id;
	}

	private static function cleanEmail( mixed $value, string $code, string $label ): string {
		$email = sanitize_email( wp_unslash( (string) $value ) );
		if ( '' !== trim( (string) $value ) && ( '' === $email || ! is_email( $email ) ) ) {
			add_settings_error( self::OPTION, 'psl_' . $code, 'Ungültige E-Mail-Adresse: ' . $label . '.' );
			return '';
		}
		return '' === $email ? '' : $email;
	}

	/** @return array<string, string> */
	public static function cleanFieldMap( mixed $value ): array {
		$value = is_array( $value ) ? $value : [];
		$map   = [];
		foreach ( self::DEFAULT_FIELD_MAP as $key => $default ) {
			$name        = isset( $value[ $key ] ) ? trim( (string) $value[ $key ] ) : $default;
			$map[ $key ] = preg_match( '/^[a-zA-Z][0-9a-zA-Z:._-]*$/', $name ) ? $name : ( 'salutation' === $key ? '' : $default );
		}
		return $map;
	}

	/** @return array<string, string> nur bekannte Schlüssel, nur gültige Propstack-Feldnamen */
	public static function cleanCustomFieldMap( mixed $value ): array {
		$value = is_array( $value ) ? $value : [];
		$map   = [];
		foreach ( self::ATTRIBUTION_KEYS as $key ) {
			$name = isset( $value[ $key ] ) ? strtolower( trim( (string) $value[ $key ] ) ) : '';
			if ( '' !== $name && preg_match( '/^[a-z0-9_]{1,64}$/', $name ) ) {
				$map[ $key ] = $name;
			}
		}
		return $map;
	}

	/** Schreibt Werte direkt (CLI/Tests) – ohne Settings-API-Formular. */
	public function update( array $values ): void {
		$merged = array_merge( $this->all(), array_intersect_key( $values, self::DEFAULTS ) );
		foreach ( [ 'public_status_ids', 'sold_status_ids', 'reserved_status_ids' ] as $key ) {
			$merged[ $key ] = self::intList( $merged[ $key ] );
		}
		$merged['sync_interval'] = self::clampInterval( $merged['sync_interval'] );
		$merged['cf7_form_id']   = absint( $merged['cf7_form_id'] );
		foreach ( [ 'inquiry_email', 'inquiry_bcc' ] as $key ) {
			$merged[ $key ] = is_email( (string) $merged[ $key ] ) ? (string) $merged[ $key ] : '';
		}
		$merged['field_map'] = self::cleanFieldMap( $merged['field_map'] );
		$merged['cf_map']    = self::cleanCustomFieldMap( $merged['cf_map'] );
		update_option( self::OPTION, $merged, false );
		$this->cache = null;
	}

	/**
	 * Migration beim Plugin-Start nachholen, falls noch 0.2.x-Daten vorhanden sind. Nötig, weil ein
	 * Update per ZIP-Upload („Version ersetzen“) oder FTP den Aktivierungs-Hook nicht auslöst.
	 * Im Normalbetrieb nur ein Array-Schlüsselvergleich auf der ohnehin geladenen Option.
	 */
	public static function maybeMigrateLegacy(): bool {
		$stored = get_option( self::OPTION, null );
		$legacy = is_array( $stored ) && array_intersect( self::LEGACY_KEYS, array_keys( $stored ) );
		if ( $legacy || false !== get_option( 'propstack_lite_cache_salt', false ) ) {
			self::migrateLegacy();
			return true;
		}
		return false;
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
