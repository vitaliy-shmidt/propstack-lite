<?php

namespace PropstackLite\Support;

use PropstackLite\Api\ApiException;

/**
 * Stabile, maschinenlesbare Fehler- und Diagnosecodes für Admin, Site Health, WP-CLI, Logs und Support.
 * Codes ändern sich nicht zwischen Versionen (nur ergänzen). Texte sind deutsch und frei von Secrets.
 * Liste mit Abhilfe: docs/operations.md.
 */
final class ErrorCode {

	// Konfiguration / API
	public const API_KEY_MISSING      = 'api_key_missing';
	public const API_AUTH_FAILED      = 'api_auth_failed';
	public const API_UNREACHABLE      = 'api_unreachable';
	public const API_RATE_LIMITED     = 'api_rate_limited';
	public const API_SERVER_ERROR     = 'api_server_error';
	public const API_INVALID_RESPONSE = 'api_invalid_response';
	public const API_REQUEST_FAILED   = 'api_request_failed';
	public const NO_PUBLIC_STATUS     = 'no_public_status';

	// Sync / Cron
	public const SYNC_LOCKED  = 'sync_locked';
	public const SYNC_FAILED  = 'sync_failed';
	public const SYNC_NEVER   = 'sync_never';
	public const SYNC_STALE   = 'sync_stale';
	public const CRON_OVERDUE = 'cron_overdue';

	// Datenbank
	public const SCHEMA_MISSING  = 'schema_missing';
	public const SCHEMA_OUTDATED = 'schema_outdated';
	public const SCHEMA_NEWER    = 'schema_newer';

	// Anfragen / Tracking / SEO
	public const CF7_MISSING              = 'cf7_missing';
	public const LEAD_FORM_MISSING        = 'lead_form_missing';
	public const LEAD_TARGET_MISSING      = 'lead_target_missing';
	public const CONSENT_PROVIDER_MISSING = 'consent_provider_missing';
	public const SEO_PLUGIN_CONFLICT      = 'seo_plugin_conflict';

	public const LABELS = [
		self::API_KEY_MISSING          => 'Kein Propstack-API-Key konfiguriert.',
		self::API_AUTH_FAILED          => 'Propstack lehnt den API-Key ab (401/403).',
		self::API_UNREACHABLE          => 'Propstack ist nicht erreichbar (Netzwerk/Timeout).',
		self::API_RATE_LIMITED         => 'Propstack-Ratenlimit erreicht (429).',
		self::API_SERVER_ERROR         => 'Propstack meldet einen Serverfehler (5xx).',
		self::API_INVALID_RESPONSE     => 'Propstack lieferte eine ungültige Antwort.',
		self::API_REQUEST_FAILED       => 'Propstack-Anfrage abgelehnt.',
		self::NO_PUBLIC_STATUS         => 'Keine öffentlichen Propstack-Status ausgewählt – die Website zeigt keine Immobilien.',
		self::SYNC_LOCKED              => 'Ein anderer Sync-Lauf ist aktiv.',
		self::SYNC_FAILED              => 'Der Sync ist mit einem unerwarteten Fehler abgebrochen.',
		self::SYNC_NEVER               => 'Es hat noch kein erfolgreicher Sync stattgefunden.',
		self::SYNC_STALE               => 'Der letzte erfolgreiche Sync ist zu lange her.',
		self::CRON_OVERDUE             => 'Geplante Sync-Läufe werden nicht ausgeführt (WP-Cron/System-Cron prüfen).',
		self::SCHEMA_MISSING           => 'Die Tabelle des Immobilienbestands fehlt oder ist unvollständig.',
		self::SCHEMA_OUTDATED          => 'Das Datenbankschema ist veraltet und wurde noch nicht migriert.',
		self::SCHEMA_NEWER             => 'Das Datenbankschema stammt von einer neueren Plugin-Version – Sync ist zum Schutz der Daten gesperrt.',
		self::CF7_MISSING              => 'Contact Form 7 ist nicht aktiv, obwohl Immobilienanfragen konfiguriert sind.',
		self::LEAD_FORM_MISSING        => 'Das konfigurierte Anfrageformular existiert nicht.',
		self::LEAD_TARGET_MISSING      => 'Keine Propstack-Zieladresse für Anfragen hinterlegt.',
		self::CONSENT_PROVIDER_MISSING => 'Tracking ist aktiviert, aber kein Consent-Provider gewählt – es wird nichts erfasst.',
		self::SEO_PLUGIN_CONFLICT      => 'Mehrere SEO-Plugins aktiv.',
	];

	public static function label( string $code ): string {
		return self::LABELS[ $code ] ?? $code;
	}

	/** Code für einen API-Fehler (Kategorie aus ApiException). */
	public static function fromApiException( ApiException $e ): string {
		return match ( $e->getCategory() ) {
			ApiException::CONFIG           => self::API_KEY_MISSING,
			ApiException::AUTH             => self::API_AUTH_FAILED,
			ApiException::NETWORK          => self::API_UNREACHABLE,
			ApiException::RATE_LIMIT       => self::API_RATE_LIMITED,
			ApiException::SERVER           => self::API_SERVER_ERROR,
			ApiException::INVALID_RESPONSE => self::API_INVALID_RESPONSE,
			default                        => self::API_REQUEST_FAILED,
		};
	}
}
