<?php

namespace PropstackLite\Storage;

/**
 * Datenbankschema `{prefix}psl_properties` inklusive Versionierung.
 * Änderungen: VERSION erhöhen, sql() anpassen, docs/database.md + docs/changelog.md pflegen.
 */
final class Schema {

	public const VERSION        = 1;
	public const VERSION_OPTION = 'psl_db_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'psl_properties';
	}

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::sql() );
		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	public static function maybeUpgrade(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) < self::VERSION ) {
			self::install();
		}
	}

	public static function drop(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		delete_option( self::VERSION_OPTION );
	}

	/** dbDelta-konformes SQL (zwei Leerzeichen nach PRIMARY KEY, ein Feld pro Zeile). */
	public static function sql(): string {
		global $wpdb;
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		return "CREATE TABLE {$table} (
  propstack_id bigint(20) unsigned NOT NULL,
  slug varchar(190) NOT NULL DEFAULT '',
  state varchar(20) NOT NULL DEFAULT 'active',
  status_id bigint(20) unsigned DEFAULT NULL,
  marketing_type varchar(10) DEFAULT NULL,
  rs_type varchar(40) DEFAULT NULL,
  object_type varchar(40) DEFAULT NULL,
  city varchar(100) DEFAULT NULL,
  zip_code varchar(20) DEFAULT NULL,
  district varchar(100) DEFAULT NULL,
  price decimal(14,2) DEFAULT NULL,
  base_rent decimal(12,2) DEFAULT NULL,
  total_rent decimal(12,2) DEFAULT NULL,
  living_space decimal(12,2) DEFAULT NULL,
  plot_area decimal(12,2) DEFAULT NULL,
  rooms decimal(5,1) DEFAULT NULL,
  data longtext DEFAULT NULL,
  data_hash char(32) DEFAULT NULL,
  remote_created_at datetime DEFAULT NULL,
  remote_updated_at datetime DEFAULT NULL,
  first_seen_at datetime NOT NULL,
  last_seen_at datetime NOT NULL,
  content_changed_at datetime NOT NULL,
  sold_at datetime DEFAULT NULL,
  removed_at datetime DEFAULT NULL,
  PRIMARY KEY  (propstack_id),
  KEY state_status (state,status_id),
  KEY type_idx (marketing_type,rs_type),
  KEY city_idx (city),
  KEY price_idx (price),
  KEY created_idx (remote_created_at)
) {$collate};";
	}
}
