<?php

namespace PropstackLite\Cli;

use PropstackLite\Api\ApiException;
use PropstackLite\Mapping\FieldCatalog;
use PropstackLite\Plugin;
use PropstackLite\Storage\PropertyStore;
use PropstackLite\Sync\SyncState;

/**
 * Diagnose und Sync über WP-CLI.
 *
 * ## EXAMPLES
 *
 *     wp psl sync
 *     wp psl sync --full
 *     wp psl sync --id=12345
 *     wp psl status
 *     wp psl statuses
 *     wp psl audit
 */
final class Command {

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Synchronisiert Propstack → lokaler Bestand.
	 *
	 * ## OPTIONS
	 *
	 * [--full]
	 * : Voll-Sync statt inkrementell.
	 *
	 * [--id=<id>]
	 * : Nur ein Objekt synchronisieren.
	 */
	public function sync( array $args, array $assoc ): void {
		$service = $this->plugin->syncService();
		if ( isset( $assoc['id'] ) ) {
			$result = $service->syncOne( (int) $assoc['id'] );
		} elseif ( isset( $assoc['full'] ) ) {
			$result = $service->runFull();
		} else {
			$result = $service->runIncremental();
		}

		if ( $result->isOk() ) {
			\WP_CLI::success( $result->summary() );
			return;
		}
		\WP_CLI::error( $result->summary() );
	}

	/**
	 * Zeigt Sync-Status und Bestandszahlen.
	 */
	public function status(): void {
		$settings = $this->plugin->settings();
		$store    = PropertyStore::create();
		$state    = ( new SyncState() )->get();

		$rows = [
			[ 'key' => 'API-Key konfiguriert', 'value' => $settings->hasApiKey() ? 'ja' . ( $settings::apiKeyFromConstant() ? ' (Konstante)' : ' (Option)' ) : 'NEIN' ],
			[ 'key' => 'Öffentliche Status-IDs', 'value' => implode( ', ', $settings->publicStatusIds() ) ?: '–' ],
			[ 'key' => 'Verkauft-Status-IDs', 'value' => implode( ', ', $settings->soldStatusIds() ) ?: '–' ],
			[ 'key' => 'Reserviert-Status-IDs', 'value' => implode( ', ', $settings->reservedStatusIds() ) ?: '–' ],
			[ 'key' => 'Öffentlich sichtbar', 'value' => (string) $store->countVisible( $settings->publicStatusIds() ) ],
		];
		foreach ( $store->countsByState() as $state_name => $n ) {
			$rows[] = [ 'key' => 'Bestand: ' . $state_name, 'value' => (string) $n ];
		}
		foreach ( [ 'last_full_at', 'last_incremental_at', 'last_success_at', 'incremental_cursor', 'last_error_at', 'last_error' ] as $key ) {
			$rows[] = [ 'key' => $key, 'value' => (string) ( $state[ $key ] ?? '–' ) ];
		}
		if ( isset( $state['last_result'] ) ) {
			$rows[] = [ 'key' => 'last_result', 'value' => wp_json_encode( $state['last_result'] ) ];
		}
		foreach ( [ 'psl_sync_incremental', 'psl_sync_full' ] as $hook ) {
			$next   = wp_next_scheduled( $hook );
			$rows[] = [ 'key' => 'Nächster ' . $hook, 'value' => $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'nicht geplant' ];
		}

		\WP_CLI\Utils\format_items( 'table', $rows, [ 'key', 'value' ] );
	}

	/**
	 * Listet die Propstack-Objektstatus (für die Sichtbarkeits-Einstellungen).
	 */
	public function statuses(): void {
		try {
			$statuses = $this->plugin->unitsEndpoint()->statuses();
		} catch ( ApiException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		$public = $this->plugin->settings()->publicStatusIds();
		$sold   = $this->plugin->settings()->soldStatusIds();
		$items  = array_map(
			static fn ( $s ) => [
				'id'          => $s['id'],
				'name'        => $s['name'],
				'öffentlich'  => in_array( $s['id'], $public, true ) ? 'ja' : '',
				'verkauft'    => in_array( $s['id'], $sold, true ) ? 'ja' : '',
			],
			$statuses
		);
		\WP_CLI\Utils\format_items( 'table', $items, [ 'id', 'name', 'öffentlich', 'verkauft' ] );
	}

	/**
	 * Prüft den lokalen Bestand auf Datenschutzverstöße.
	 *
	 * Prüft u. a.: keine internen CRM-Felder, keine Straße/Koordinaten bei verborgener Adresse,
	 * nur Propstack-HTTPS-Bild-URLs, keine Daten bei entfernten Objekten.
	 */
	public function audit(): void {
		$store      = PropertyStore::create();
		$forbidden  = array_flip( FieldCatalog::FORBIDDEN_KEYS );
		$violations = [];
		$checked    = 0;

		foreach ( $store->auditRows() as $row ) {
			++$checked;
			$id = (int) $row['propstack_id'];
			if ( 'removed' === $row['state'] && null !== $row['data'] ) {
				$violations[] = [ 'id' => $id, 'problem' => 'Entferntes Objekt enthält noch Daten' ];
			}
			if ( null === $row['data'] ) {
				continue;
			}
			$data = json_decode( (string) $row['data'], true );
			if ( ! is_array( $data ) ) {
				$violations[] = [ 'id' => $id, 'problem' => 'Ungültiges JSON' ];
				continue;
			}
			foreach ( self::keysRecursive( $data ) as $key ) {
				if ( isset( $forbidden[ $key ] ) ) {
					$violations[] = [ 'id' => $id, 'problem' => 'Verbotenes Feld: ' . $key ];
				}
			}
			$addr = $data['address'] ?? [];
			if ( ( $addr['hidden'] ?? true ) && ( ! empty( $addr['street'] ) || ! empty( $addr['houseNumber'] ) || isset( $addr['lat'] ) || isset( $addr['lng'] ) ) ) {
				$violations[] = [ 'id' => $id, 'problem' => 'Verborgene Adresse enthält Straße/Koordinaten' ];
			}
			array_walk_recursive(
				$data,
				static function ( $value, $key ) use ( $id, &$violations ) {
					if ( is_string( $value ) && preg_match( '#^https?://#i', $value ) ) {
						$host = strtolower( (string) parse_url( $value, PHP_URL_HOST ) );
						if ( ! str_starts_with( $value, 'https://' ) || ( 'propstack.de' !== $host && ! str_ends_with( $host, '.propstack.de' ) ) ) {
							$violations[] = [ 'id' => $id, 'problem' => 'Unerlaubte URL in Feld ' . $key ];
						}
					}
				}
			);
		}

		if ( [] !== $violations ) {
			\WP_CLI\Utils\format_items( 'table', $violations, [ 'id', 'problem' ] );
			\WP_CLI::error( count( $violations ) . " Verstöße in {$checked} Zeilen." );
			return;
		}
		\WP_CLI::success( "Keine Verstöße in {$checked} Zeilen gefunden." );
	}

	/** @return list<string> */
	private static function keysRecursive( array $data ): array {
		$keys = [];
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) ) {
				$keys[] = $key;
			}
			if ( is_array( $value ) ) {
				$keys = array_merge( $keys, self::keysRecursive( $value ) );
			}
		}
		return $keys;
	}
}
