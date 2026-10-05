# Synchronisation

Klasse `Sync\SyncService`. Läuft **nur** in WP-Cron (`wp-cron.php`), WP-CLI, der Admin-Aktion „Jetzt synchronisieren“ oder als vom Webhook geplanter Cron-Lauf – nie im Besucher-Request.

## Sync-Arten

| Art | Auslöser | Ablauf |
|---|---|---|
| **Full** | Cron `psl_sync_full` (alle 6 h), `psl_sync_full_once` (nach Aktivierung / Änderung der Status-Einstellungen / API-Key), `wp psl sync --full`, Admin-Button | 1. `GET /units?status[]=<öffentliche Status>&expand=1&per=100&sort_by=id&order=asc` über alle Seiten. 2. Jedes Objekt durch `StateResolver` → Upsert. 3. **Reconcile:** bisher `active`, aber nicht mehr geliefert → `GET /units?property_ids[]=…&archived=-1&expand=1` (100 IDs/Request); nicht gelieferte IDs gelten als gelöscht. Liefert der Sammelabruf für einen Block gar nichts, wird jede ID einzeln per `GET /units/{id}?new=1` geprüft (Schutz vor Massenentfernung durch API-Eigenheiten). |
| **Incremental** | Cron `psl_sync_incremental` (Intervall aus Einstellungen, Default 15 min), `psl_sync_incremental_once` (Webhook), `wp psl sync` | `GET /units?updated_at_from=<Cursor − 24 h>&archived=-1&expand=1` (alle Status), lokale Klassifizierung. Ohne Cursor → Full. Erkennt keine Löschungen (dafür Full). |
| **Single** | `wp psl sync --id=N` (später Webhook) | `GET /units/{id}?new=1`; 404 → gelöscht. Setzt keinen Cursor. |

Warum 24 h Überlappung: `updated_at_from` filterte in Tests nicht zuverlässig sekundengenau ([propstack-api.md](propstack-api.md)). Doppelte Verarbeitung ist unkritisch (`data_hash` → `unchanged`).

## Statuslogik (`Sync\StateResolver`)

| Remote-Zustand | gespeicherter Zustand | Aktion |
|---|---|---|
| öffentlicher Status, nicht archiviert | beliebig | **upsert** → `active` (setzt `sold_at`/`removed_at` zurück) |
| Status „verkauft/vermietet“ (Einstellung) | `active` | **sold** (`sold_at` = jetzt) |
| beliebig außer öffentlich | `sold` | **keep** – 30-Tage-Frist läuft weiter (auch wenn danach archiviert/inaktiv) |
| anderer Status / archiviert / ohne Status | `active` | **remove** (`data` = NULL, `removed_at`) |
| gelöscht (404 bzw. nicht im Reconcile) | `active`/`sold` | **remove** |
| alles andere | nicht gespeichert / `removed` | **ignore** (nie öffentlich → nie gespeichert) |

Ist ein Status sowohl „öffentlich“ als auch „verkauft“ gewählt, gewinnt „öffentlich“.

### Verkauft/vermietet – 30-Tage-Logik

- Datenebene (implementiert): `state = sold`, Objekt nicht mehr in öffentlichen Listen; jeder Sync-Lauf leert `data` bei `sold_at` älter als 30 Tage (`purged`), die Zeile bleibt.
- Darstellung (**Geplant, Phase 2/3**): innerhalb von 30 Tagen HTTP 200 mit Hinweis „Verkauft“ (BUY) bzw. „Vermietet“ (RENT), `noindex,follow`, kein Formular, ähnliche Objekte; danach HTTP 410. Siehe [routing-seo.md](routing-seo.md).
- „Reserviert“: Status muss öffentlich **und** als „Reserviert“ gewählt sein → bleibt `active`, Liste zeigt Badge.

## Schutzmechanismen

- **Keine öffentlichen Status konfiguriert** → Lauf wird übersprungen (`skipped`), Bestand unverändert, Admin-Hinweis.
- **API-Fehler** (Netzwerk, 401/403, 5xx nach Retries, ungültiges JSON, unvollständige Pagination) → Abbruch **vor** jeder Entfernung; Bestand bleibt online; Fehler in `psl_sync_state`, Admin-Hinweis.
- **Lock** (`psl_sync_lock` via atomarem `add_option`, TTL 15 min) gegen parallele Läufe; wird im `finally` freigegeben.
- **Retries:** 3 Versuche bei Netzwerkfehlern, 429 und 5xx; Backoff 1 s, 2 s bzw. `Retry-After` (max. 30 s). 401/403/404/4xx werden nicht wiederholt.
- Ungültige Datensätze (ohne ID) → `invalid`, Lauf geht weiter.

## Cron

| Hook | Zeitplan |
|---|---|
| `psl_sync_incremental` | `psl_interval` (Einstellung, 5–1440 min, Default 15) |
| `psl_sync_full` | `psl_six_hours` |
| `psl_sync_full_once` | einmalig, z. B. nach Einstellungsänderung |
| `psl_sync_incremental_once` | einmalig, vom Webhook geplant |

WP-Cron läuft nur bei Seitenaufrufen. Empfehlung Produktion: `define( 'DISABLE_WP_CRON', true );` und System-Cron, z. B. alle 5 Minuten `wp cron event run --due-now --path=/pfad/zu/wordpress` oder Aufruf von `https://domain.tld/wp-cron.php`. Fehlende Events plant `Plugin::boot()` selbstständig neu.

## WP-CLI

| Befehl | Zweck |
|---|---|
| `wp psl status` | Konfiguration (Key ja/nein, Status-IDs), Bestandszahlen, letzte Läufe, Fehler, nächste Cron-Termine |
| `wp psl sync` | inkrementeller Sync (ohne Cursor: Full) |
| `wp psl sync --full` | Voll-Sync inkl. Reconcile |
| `wp psl sync --id=<id>` | ein Objekt |
| `wp psl statuses` | Propstack-Status mit IDs und Markierung öffentlich/verkauft |
| `wp psl audit` | Datenschutz-Prüfung des Bestands: verbotene Felder, Straße/Koordinaten bei verborgener Adresse, nicht erlaubte URLs, Daten bei entfernten Objekten. Exit-Code ≠ 0 bei Verstößen |

## Admin-Diagnose

„Einstellungen → Propstack Lite“: öffentlich sichtbare Objekte, Bestand je Zustand, letzter Voll-/Inkrement-Sync, letzter Fehler, nächster Termin, Lock-Status, Buttons „Jetzt vollständig synchronisieren“ und „Statusliste neu laden“ (Nonce + `manage_options`). Admin-Hinweise auf Dashboard, Plugins- und Einstellungsseite bei fehlendem Key, fehlenden öffentlichen Status oder fehlgeschlagenem letzten Lauf.

## Logging

`Support\Logger` schreibt nur bei `WP_DEBUG_LOG` nach `debug.log`, Präfix `[propstack-lite]`. Inhalte: Sync-Zusammenfassung (Art, Status, Dauer, Zähler), Fehlermeldungen aus `ApiException` (enthalten nie den Key), IDs nicht mappbarer Objekte. Kontextschlüssel, die nach Secrets/PII klingen, werden verworfen.

Zähler im Ergebnis: `fetched`, `inserted`, `updated`, `unchanged`, `sold`, `removed`, `kept`, `ignored`, `invalid`, `purged`, `reconciled`.

## Webhook

Implementiert (Phase 1): `POST /wp-json/propstack/v1/webhook`, Token per Header `X-PSL-Token` (oder Parameter `token`), Prüfung im `permission_callback` mit `hash_equals`; leeres Token = deaktiviert. Antwort `202`, plant `psl_sync_incremental_once` – kein Sync im Request.

**Geplant (Phase 2):** HMAC-Prüfung über `X-Propstack-Signature` (Secret aus Einstellungen), Auswertung von `property_created/updated/deleted` für gezielten Einzel-Sync. Registrierung der Hooks in Propstack (`POST /v1/hooks`) erfordert einen Key mit entsprechenden Rechten (aktueller Key: 401).
