<?php

namespace PropstackLite\Admin;

use PropstackLite\Leads\Cf7Integration;
use PropstackLite\Leads\LeadSetupCheck;
use PropstackLite\Seo\SeoPlugins;
use PropstackLite\Settings;
use PropstackLite\Storage\PropertyStore;
use PropstackLite\Storage\Schema;
use PropstackLite\Support\ErrorCode;
use PropstackLite\Sync\Scheduler;
use PropstackLite\Sync\SyncLock;
use PropstackLite\Sync\SyncState;
use PropstackLite\Theme\AvadaAdapter;

/**
 * Zentrale Betriebsdiagnose: sammelt Fakten (facts) und bewertet sie (evaluate) zu Prüfungen mit
 * stabilen Codes. Gemeinsame Quelle für Site Health, Admin-Hinweise, Statusseite und `wp psl doctor`.
 *
 * - facts() liest WordPress-Zustand (nur Admin/CLI/Site Health – nie im Besucher-Request).
 * - evaluate() ist reine Logik (ohne WordPress testbar).
 * Keine Secrets: der API-Key erscheint nur als „ja/nein“ und Quelle.
 */
final class Diagnostics {

	public const CRITICAL    = 'critical';
	public const RECOMMENDED = 'recommended';

	public const GROUP_CONFIG   = 'config';
	public const GROUP_DATABASE = 'database';
	public const GROUP_SYNC     = 'sync';
	public const GROUP_LEADS    = 'leads';
	public const GROUP_TRACKING = 'tracking';
	public const GROUP_SEO      = 'seo';

	/** Kein erfolgreicher Sync seit … (Voll-Sync alle 6 h → zwei verpasste Läufe). */
	public const STALE_AFTER = 12 * 3600;
	/** Geplantes Event so lange überfällig → Cron läuft nicht. */
	public const CRON_GRACE = 3600;

	public function __construct( private Settings $settings ) {}

	/** @return array<string, mixed> */
	public function facts(): array {
		$state  = ( new SyncState() )->get();
		$lock   = new SyncLock();
		$schema = Schema::status();
		$leads  = ( new LeadSetupCheck( $this->settings ) )->run();
		$store  = PropertyStore::create();
		$counts = $schema['table'] ? $store->countsByState() : [ 'active' => 0, 'sold' => 0, 'removed' => 0 ];

		return [
			'pluginVersion'    => defined( 'PSL_VERSION' ) ? PSL_VERSION : '',
			'php'              => PHP_VERSION,
			'wp'               => (string) get_bloginfo( 'version' ),
			'schema'           => $schema,
			'apiKey'           => $this->settings->hasApiKey(),
			'apiKeySource'     => $this->settings->hasApiKey() ? ( Settings::apiKeyFromConstant() ? 'Konstante PSL_API_KEY' : 'Einstellung' ) : '–',
			'publicStatusIds'  => count( $this->settings->publicStatusIds() ),
			'visible'          => $schema['table'] && [] === $schema['missingColumns'] ? $store->countVisible( $this->settings->publicStatusIds() ) : 0,
			'counts'           => $counts,
			'lastSuccessAt'    => self::ts( $state['last_success_at'] ?? null ),
			'lastFullAt'       => self::ts( $state['last_full_at'] ?? null ),
			'lastIncrementalAt' => self::ts( $state['last_incremental_at'] ?? null ),
			'lastErrorAt'      => self::ts( $state['last_error_at'] ?? null ),
			'lastError'        => (string) ( $state['last_error'] ?? '' ),
			'lastErrorCode'    => ( new SyncState() )->lastErrorCode(),
			'syncFailing'      => ( new SyncState() )->hasUnresolvedError(),
			'syncRunning'      => $lock->isLocked(),
			'syncHeartbeat'    => $lock->heartbeat(),
			'nextIncremental'  => wp_next_scheduled( Scheduler::HOOK_INCREMENTAL ) ?: null,
			'nextFull'         => wp_next_scheduled( Scheduler::HOOK_FULL ) ?: null,
			'wpCronDisabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'seoMode'          => SeoPlugins::mode(),
			'seoActive'        => SeoPlugins::active(),
			'avada'            => AvadaAdapter::isActive(),
			'cf7'              => Cf7Integration::isAvailable(),
			'cf7FormId'        => $this->settings->cf7FormId(),
			'leadForm'         => $leads['form'],
			'leadTarget'       => $leads['email'],
			'leadReady'        => $leads['ready'],
			'tracking'         => $this->settings->trackingAttribution() || $this->settings->trackingDataLayer(),
			'consentProvider'  => $this->settings->consentProviderId(),
			'now'              => time(),
		];
	}

	/**
	 * Bewertung der Fakten. Nur Zustände, die ein Admin beheben sollte – keine Hinweise auf bewusst
	 * nicht genutzte Funktionen (z. B. ohne konfigurierte Anfragen keine CF7-Warnung).
	 *
	 * @param array<string, mixed> $f
	 * @return list<array{code: string, severity: string, group: string, message: string}>
	 */
	public static function evaluate( array $f ): array {
		$checks = [];
		$add    = static function ( string $code, string $severity, string $group, string $detail = '' ) use ( &$checks ): void {
			$checks[] = [ 'code' => $code, 'severity' => $severity, 'group' => $group, 'message' => ErrorCode::label( $code ) . ( '' === $detail ? '' : ' ' . $detail ) ];
		};

		if ( empty( $f['apiKey'] ) ) {
			$add( ErrorCode::API_KEY_MISSING, self::CRITICAL, self::GROUP_CONFIG, 'Konstante PSL_API_KEY in wp-config.php setzen.' );
		}
		if ( 0 === (int) ( $f['publicStatusIds'] ?? 0 ) ) {
			$add( ErrorCode::NO_PUBLIC_STATUS, self::CRITICAL, self::GROUP_CONFIG );
		}

		$schema   = (array) ( $f['schema'] ?? [] );
		$schemaOk = true;
		if ( empty( $schema['table'] ) || [] !== ( $schema['missingColumns'] ?? [] ) ) {
			$missing = (array) ( $schema['missingColumns'] ?? [] );
			$add( ErrorCode::SCHEMA_MISSING, self::CRITICAL, self::GROUP_DATABASE, [] === $missing ? 'Plugin deaktivieren und wieder aktivieren.' : 'Fehlende Spalten: ' . implode( ', ', $missing ) . '.' );
			$schemaOk = false;
		} elseif ( (int) ( $schema['installed'] ?? 0 ) < (int) ( $schema['expected'] ?? 0 ) ) {
			$add( ErrorCode::SCHEMA_OUTDATED, self::CRITICAL, self::GROUP_DATABASE, sprintf( '(Version %d, erwartet %d)', $schema['installed'], $schema['expected'] ) );
			$schemaOk = false;
		} elseif ( (int) ( $schema['installed'] ?? 0 ) > (int) ( $schema['expected'] ?? 0 ) ) {
			$add( ErrorCode::SCHEMA_NEWER, self::CRITICAL, self::GROUP_DATABASE, sprintf( '(Version %d, dieses Plugin kennt %d) – neuere Plugin-Version wieder einspielen.', $schema['installed'], $schema['expected'] ) );
			$schemaOk = false;
		}

		$configOk = ! empty( $f['apiKey'] ) && (int) ( $f['publicStatusIds'] ?? 0 ) > 0 && $schemaOk;
		if ( $configOk ) {
			$now = (int) ( $f['now'] ?? time() );
			if ( ! empty( $f['syncFailing'] ) ) {
				$code = (string) ( $f['lastErrorCode'] ?? '' );
				$add( '' === $code ? ErrorCode::SYNC_FAILED : $code, self::RECOMMENDED, self::GROUP_SYNC, 'Letzter Lauf: ' . (string) ( $f['lastError'] ?? '' ) );
			}
			if ( null === ( $f['lastSuccessAt'] ?? null ) ) {
				$add( ErrorCode::SYNC_NEVER, self::RECOMMENDED, self::GROUP_SYNC, 'Einmal „Jetzt vollständig synchronisieren“ ausführen.' );
			} elseif ( $now - (int) $f['lastSuccessAt'] > self::STALE_AFTER ) {
				$add( ErrorCode::SYNC_STALE, self::RECOMMENDED, self::GROUP_SYNC, sprintf( '(vor %d Stunden)', intdiv( $now - (int) $f['lastSuccessAt'], 3600 ) ) );
			}
			$overdue = static fn ( $ts ) => null === $ts || (int) $ts < $now - self::CRON_GRACE;
			if ( $overdue( $f['nextIncremental'] ?? null ) || $overdue( $f['nextFull'] ?? null ) ) {
				$add( ErrorCode::CRON_OVERDUE, self::RECOMMENDED, self::GROUP_SYNC, ! empty( $f['wpCronDisabled'] ) ? 'DISABLE_WP_CRON ist gesetzt – System-Cron einrichten (docs/operations.md).' : '' );
			}
		}

		$leadConfigured = (int) ( $f['cf7FormId'] ?? 0 ) > 0;
		if ( $leadConfigured && empty( $f['cf7'] ) ) {
			$add( ErrorCode::CF7_MISSING, self::RECOMMENDED, self::GROUP_LEADS );
		} elseif ( $leadConfigured && null === ( $f['leadForm'] ?? null ) ) {
			$add( ErrorCode::LEAD_FORM_MISSING, self::RECOMMENDED, self::GROUP_LEADS );
		}
		if ( $leadConfigured && empty( $f['leadTarget'] ) ) {
			$add( ErrorCode::LEAD_TARGET_MISSING, self::RECOMMENDED, self::GROUP_LEADS );
		}

		if ( ! empty( $f['tracking'] ) && 'none' === ( $f['consentProvider'] ?? 'none' ) ) {
			$add( ErrorCode::CONSENT_PROVIDER_MISSING, self::RECOMMENDED, self::GROUP_TRACKING );
		}
		if ( count( (array) ( $f['seoActive'] ?? [] ) ) > 1 ) {
			$add( ErrorCode::SEO_PLUGIN_CONFLICT, self::RECOMMENDED, self::GROUP_SEO, 'Integriert wird nur ' . ( SeoPlugins::LABELS[ $f['seoMode'] ?? '' ] ?? '' ) . '.' );
		}

		return $checks;
	}

	/** @return list<array{code: string, severity: string, group: string, message: string}> */
	public function checks(): array {
		return self::evaluate( $this->facts() );
	}

	/**
	 * Lesbare Faktenliste für Statusseite, Site-Health-Debuginfo und CLI (keine Secrets).
	 *
	 * @param array<string, mixed> $f
	 * @return array<string, string>
	 */
	public static function summary( array $f ): array {
		$date = static fn ( $ts ) => null === $ts ? '–' : ( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y H:i', (int) $ts ) : gmdate( 'Y-m-d H:i', (int) $ts ) . ' UTC' );
		$c    = (array) ( $f['counts'] ?? [] );
		return [
			'Plugin-Version'              => (string) ( $f['pluginVersion'] ?? '' ),
			'Datenbankschema'             => sprintf( '%d (erwartet %d)', $f['schema']['installed'] ?? 0, $f['schema']['expected'] ?? 0 ),
			'PHP'                         => (string) ( $f['php'] ?? '' ),
			'WordPress'                   => (string) ( $f['wp'] ?? '' ),
			'API-Key'                     => ! empty( $f['apiKey'] ) ? 'ja (' . $f['apiKeySource'] . ')' : 'nein',
			'Öffentliche Status (Anzahl)' => (string) ( $f['publicStatusIds'] ?? 0 ),
			'Öffentlich sichtbare Objekte' => (string) ( $f['visible'] ?? 0 ),
			'Bestand aktiv/verkauft/entfernt' => sprintf( '%d / %d / %d', $c['active'] ?? 0, $c['sold'] ?? 0, $c['removed'] ?? 0 ),
			'Letzter erfolgreicher Sync'  => $date( $f['lastSuccessAt'] ?? null ),
			'Letzter Voll-Sync'           => $date( $f['lastFullAt'] ?? null ),
			'Letzter inkrementeller Sync' => $date( $f['lastIncrementalAt'] ?? null ),
			'Nächster inkrementeller Sync' => $date( $f['nextIncremental'] ?? null ),
			'Nächster Voll-Sync'          => $date( $f['nextFull'] ?? null ),
			'Sync läuft'                  => ! empty( $f['syncRunning'] ) ? 'ja (letzter Fortschritt ' . $date( $f['syncHeartbeat'] ?? null ) . ')' : 'nein',
			'Letzter Fehler'              => ! empty( $f['syncFailing'] ) ? ( $date( $f['lastErrorAt'] ?? null ) . ' [' . ( $f['lastErrorCode'] ?: ErrorCode::SYNC_FAILED ) . '] ' . $f['lastError'] ) : '–',
			'Cron'                        => ! empty( $f['wpCronDisabled'] ) ? 'DISABLE_WP_CRON gesetzt (System-Cron erforderlich)' : 'WP-Cron (seitenaufrufabhängig)',
			'SEO-Modus'                   => SeoPlugins::LABELS[ $f['seoMode'] ?? '' ] ?? '–',
			'Avada erkannt'               => ! empty( $f['avada'] ) ? 'ja' : 'nein',
			'Contact Form 7'              => ! empty( $f['cf7'] ) ? 'aktiv' . ( (int) $f['cf7FormId'] > 0 ? ', Formular-ID ' . (int) $f['cf7FormId'] . ( empty( $f['leadReady'] ) ? ' (nicht vollständig konfiguriert)' : ' (bereit)' ) : ', kein Formular gewählt' ) : 'nicht aktiv',
			'Tracking'                    => ! empty( $f['tracking'] ) ? 'an' : 'aus',
			'Consent-Provider'            => (string) ( $f['consentProvider'] ?? 'none' ),
		];
	}

	private static function ts( mixed $mysql ): ?int {
		if ( ! is_string( $mysql ) || '' === $mysql ) {
			return null;
		}
		$ts = strtotime( $mysql . ' UTC' );
		return false === $ts ? null : $ts;
	}
}
