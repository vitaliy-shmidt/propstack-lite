# Betrieb: Diagnose, Cron, Logging, Fehlercodes

Stand: Phase 8 (2026-10-08, Version 0.9.0). Für Administratoren und Support. Release-/Rollout-Ablauf: [release-1.0.md](release-1.0.md).

## Wo sehe ich den Zustand?

| Ort | Inhalt |
|---|---|
| **Einstellungen → Propstack Lite** | Sync-Status (letzter Voll-/inkrementeller Sync, nächster geplanter Lauf, „Sync läuft“, letzter Fehler + Code), SEO-Modus, Anfrage-Status, Box **Diagnose** (Versionen, Schema, Umgebung, Integrationen) und alle aktuellen Hinweise |
| **Werkzeuge → Website-Zustand** (Site Health) | 5 gebündelte Tests: Konfiguration, Datenbank, Synchronisation, Anfragen, Tracking/SEO; Tab „Bericht“ → Abschnitt „Propstack Listings Lite“ (zum Kopieren für den Support) |
| **Plugins-Seite** | nur kritische Probleme und fehlschlagende Syncs |
| **WP-CLI** | `wp psl doctor` (Diagnose, Exit-Code 1 bei kritischen Problemen, `--format=json`), `wp psl status`, `wp psl audit` |

Alle drei Wege nutzen dieselbe Bewertung (`Admin\Diagnostics`) und dieselben Codes. Secrets erscheinen nirgends (API-Key nur „ja/nein“ und Quelle).

## Fehler- und Diagnosecodes

Stabil (werden nur ergänzt, nie umbenannt). Definition: `src/Support/ErrorCode.php`.

| Code | Schwere | Bedeutung | Abhilfe |
|---|---|---|---|
| `api_key_missing` | kritisch | kein API-Key | `define( 'PSL_API_KEY', '…' );` in wp-config.php |
| `no_public_status` | kritisch | keine öffentlichen Status gewählt | Einstellungen → Sichtbarkeit |
| `schema_missing` | kritisch | Tabelle/Spalten fehlen | Plugin deaktivieren + aktivieren; sonst DB-Rechte prüfen |
| `schema_outdated` | kritisch | Migration nicht gelaufen | eine Admin-Seite aufrufen (Migration beim Plugin-Start) bzw. reaktivieren |
| `schema_newer` | kritisch | DB stammt von neuerer Plugin-Version (Downgrade) – Sync gesperrt | neuere Version wieder einspielen ([database.md](database.md#downgrade)) |
| `api_auth_failed` | Empfehlung | 401/403 von Propstack | Key prüfen/erneuern |
| `api_unreachable` | Empfehlung | Netzwerk/Timeout | Hosting-Firewall, DNS, Propstack-Status |
| `api_rate_limited` | Empfehlung | 429 | Intervall erhöhen; läuft beim nächsten Cron weiter |
| `api_server_error` | Empfehlung | 5xx | abwarten; Bestand bleibt online |
| `api_invalid_response` | Empfehlung | unvollständige/ungültige Antwort | abwarten, sonst Support (Bestand bleibt unverändert) |
| `api_request_failed` | Empfehlung | sonstige 4xx | Support |
| `sync_failed` | Empfehlung | unerwarteter Fehler | `debug.log` (Klasse + Meldung, keine Rohdaten) |
| `sync_locked` | Info | anderer Lauf aktiv (kein Fehler) | – |
| `sync_never` | Empfehlung | noch nie erfolgreich synchronisiert | „Jetzt vollständig synchronisieren“ |
| `sync_stale` | Empfehlung | letzter Erfolg > 12 h | Cron prüfen |
| `cron_overdue` | Empfehlung | geplante Läufe > 1 h überfällig oder fehlen | System-Cron einrichten (unten) |
| `cf7_missing` | Empfehlung | Anfragen konfiguriert, CF7 inaktiv | CF7 aktivieren |
| `lead_form_missing` | Empfehlung | gewähltes CF7-Formular existiert nicht | Formular wählen |
| `lead_target_missing` | Empfehlung | Propstack-Zieladresse fehlt | Einstellungen → Immobilienanfragen |
| `consent_provider_missing` | Empfehlung | Tracking an, Provider `none` (erfasst nichts) | Consent-Provider wählen ([tracking.md](tracking.md)) |
| `seo_plugin_conflict` | Empfehlung | mehrere SEO-Plugins | eines deaktivieren |

Anfragen (CF7) loggen Status (`prepared`, `sent`, `aborted`, `spam`, `mail_failed`) und bei Abbruch einen Code: `property_missing`, `property_not_found`, `property_not_inquirable`, `consent_missing`, `invalid_input`, `rate_limited`, `not_configured`, `honeypot` ([leads.md](leads.md)).

## Cron und Sync

| Event | Intervall | Zweck |
|---|---|---|
| `psl_sync_incremental` | Einstellung (Standard 15 min) | Änderungen seit dem letzten Lauf (24 h Überlappung) |
| `psl_sync_full` | 6 h | Sicherheitsnetz: Löschungen/Statuswechsel |
| `psl_sync_full_once` | einmalig | nach Aktivierung, Update aus 0.2.x, Änderung der Status-/Key-Einstellungen |
| `psl_sync_incremental_once` | einmalig | Webhook (`POST /wp-json/propstack/v1/webhook`) |

- **Doppelstarts:** atomarer Lock (`add_option`), TTL 15 min, wird während langer Läufe alle 25 Objekte sowie nach jedem Listenabruf verlängert. Ein zweiter Lauf endet sofort mit `sync_locked`.
- **Verpasste Läufe:** WP-Cron holt überfällige Events beim nächsten Auslöser nach; der inkrementelle Sync arbeitet mit Cursor + 24 h Überlappung, verliert also keine Änderungen. Der Voll-Sync gleicht alles ab.
- **API-Ausfall:** Abbruch vor jeder Statusänderung – der vorhandene Bestand bleibt online; Fehler mit Code im Status, `Notice` auf der Plugins-Seite, Site Health „Empfehlung“.
- **Planung idempotent:** jedes Event höchstens einmal (getestet, auch nach Upgrades aus allen Altversionen).
- **Zeitbedarf (Messung, 442 Objekte, Fake-API lokal):** Voll-Sync 0,9–2,5 s (erstmalig mit Inserts), ohne Änderungen ≈ 1 s; inkrementell ohne Änderungen ≈ 40–70 ms.

### Empfohlener System-Cron (Produktion)

WP-Cron läuft nur bei Seitenaufrufen. Für verlässliche Intervalle:

1. In `wp-config.php`: `define( 'DISABLE_WP_CRON', true );`
2. System-Cron alle 5 Minuten, bevorzugt mit WP-CLI:
   ```
   */5 * * * *  cd /pfad/zur/wordpress-installation && wp cron event run --due-now --quiet
   ```
   Ohne WP-CLI (z. B. Hosting-Cronjob-Oberfläche):
   ```
   */5 * * * *  curl -fsS "https://www.example.de/wp-cron.php?doing_wp_cron" > /dev/null
   ```
3. Kontrolle: `wp cron event list | grep psl_` bzw. Diagnose „Nächster inkrementeller Sync“ liegt in der Zukunft; Site Health ohne `cron_overdue`.

Hosting-Zugangsdaten gehören nicht in diese Doku. Bei DomainFactory steht die Cronjob-Einrichtung im Kundenmenü (Pfad zur PHP-CLI dort ablesen).

## Logging

Format: `[propstack-lite] LEVEL Nachricht {"code":"…","…":…}` über `error_log()`.

| Stufe | wann geschrieben | Beispiele |
|---|---|---|
| ERROR | immer (`debug.log` bei `WP_DEBUG_LOG`, sonst PHP-/Server-Log) | Sync-Abbruch, Mailversand fehlgeschlagen |
| WARNING | immer | Sync übersprungen (Konfiguration/Schema), abgebrochene Anfrage |
| INFO | nur bei `WP_DEBUG` | Sync-Zusammenfassung, Lead-Status `prepared`/`sent`, `sync_locked` |

Filter `psl_log_enabled` (bool, Level) schaltet Stufen ab. **Datenschutz:** Kontext nur skalar; Schlüssel mit `key|token|secret|pass|mail|phone|tel|name|address|street|message|body|ip|gclid|gbraid|wbraid|fbclid|utm|referr|url|cookie|attr` werden verworfen, E-Mail-Adressen in Texten ersetzt, URLs ohne Query-String (keine UTM/Klick-IDs). Keine API-Keys (unit-getestet).

## Support-Checkliste

1. `wp psl doctor` (oder Site Health → Bericht kopieren)
2. `wp psl status` – letzter Fehler + Code
3. `wp psl audit` – Datenschutzprüfung des Bestands
4. `debug.log` nach `[propstack-lite]` filtern
5. Bei Darstellungsproblemen: Theme-Overrides unter `{theme}/propstack-lite/` prüfen
