# Datenbank

## Tabelle `{prefix}psl_properties`

Schema-Version: **1** (Option `psl_db_version`). Definition: `src/Storage/Schema.php`. Angelegt bei Aktivierung und bei Bedarf in `plugins_loaded` (`Schema::maybeUpgrade()`), entfernt in `uninstall.php`.

| Spalte | Typ | Index | Zweck |
|---|---|---|---|
| `propstack_id` | bigint(20) unsigned NOT NULL | PRIMARY | Propstack-Objekt-ID |
| `slug` | varchar(190) NOT NULL | – | aktueller Slug (dekorativ, Routing über ID) |
| `state` | varchar(20) NOT NULL, Default `active` | `state_status` | `active` \| `sold` \| `removed` |
| `status_id` | bigint(20) unsigned NULL | `state_status` | Propstack-Status beim letzten Sync |
| `marketing_type` | varchar(10) NULL | `type_idx` | `BUY`/`RENT` (Filter) |
| `rs_type` | varchar(40) NULL | `type_idx` | Objektart (Filter) |
| `object_type` | varchar(40) NULL | – | `LIVING`/`COMMERCIAL` … |
| `city` | varchar(100) NULL | `city_idx` | Filter |
| `zip_code` | varchar(20) NULL | – | Filter |
| `district` | varchar(100) NULL | – | Filter/Anzeige |
| `price` | decimal(14,2) NULL | `price_idx` | Kaufpreis |
| `base_rent` | decimal(12,2) NULL | – | Kaltmiete |
| `total_rent` | decimal(12,2) NULL | – | Warmmiete |
| `living_space` | decimal(12,2) NULL | – | **Hauptfläche** (`Property::mainArea()`: Wohnfläche, sonst `property_space_value`) |
| `plot_area` | decimal(12,2) NULL | – | Grundstücksfläche |
| `rooms` | decimal(5,1) NULL | – | Zimmer |
| `data` | longtext NULL | – | JSON des gemappten `Property` (Whitelist). **NULL** bei `removed` und bei `sold` nach 30 Tagen |
| `data_hash` | char(32) NULL | – | MD5 von `data`, Änderungserkennung |
| `remote_created_at` | datetime NULL | `created_idx` | Propstack `created_at` (UTC), Default-Sortierung |
| `remote_updated_at` | datetime NULL | – | Propstack `updated_at` (UTC) |
| `first_seen_at` | datetime NOT NULL | – | erster Sync als öffentlich (UTC) |
| `last_seen_at` | datetime NOT NULL | – | letzter Sync, in dem das Objekt geliefert wurde |
| `content_changed_at` | datetime NOT NULL | – | letzte inhaltliche Änderung (später Sitemap-`lastmod`) |
| `sold_at` | datetime NULL | – | Beginn der 30-Tage-Frist (bleibt beim ersten Zeitpunkt) |
| `removed_at` | datetime NULL | – | Zeitpunkt der Entfernung (→ 410) |

Alle Zeitstempel in **UTC** (`Y-m-d H:i:s`). Zeichensatz: `$wpdb->get_charset_collate()` (getestet: utf8mb4).

### Zeilenlebenszyklus

```
(nie gespeichert) ──öffentlich──► active ──Status „verkauft“──► sold ──30 Tage──► sold (data = NULL)
                                    │  ▲                         │
                                    │  └──── wieder öffentlich ──┘ (auch aus removed)
                                    └──anderer Status / archiviert / gelöscht──► removed (data = NULL)
```

Zeilen werden nie gelöscht, damit ehemals öffentliche IDs später mit HTTP 410 beantwortet werden können (Phase 2). Objekte, die nie öffentlich waren, werden nie gespeichert.

### Öffentliche Abfrage

`PropertyStore::queryPublic()` erzwingt: `state = 'active' AND data IS NOT NULL AND status_id IN (öffentliche Status)`. Ohne öffentliche Status → leeres Ergebnis. Alle Werte über `$wpdb->prepare`; Sortierspalten nur aus fester Whitelist.

## Optionen

| Option | Autoload | Inhalt |
|---|---|---|
| `propstack_lite_settings` | nein | Einstellungen (`api_key`, `public_status_ids`, `sold_status_ids`, `reserved_status_ids`, `sync_interval`, `webhook_token`) |
| `psl_db_version` | ja | Schema-Version |
| `psl_sync_state` | nein | letzte Läufe, Cursor, letzter Fehler, letztes Ergebnis |
| `psl_sync_lock` | nein | Lock-Ablaufzeitpunkt (Unix-Zeit), nur während eines Laufs |
| Einstellungen Phase 4 in `propstack_lite_settings` | – | `cf7_form_id`, `inquiry_email`, `inquiry_bcc`, `field_map`, `cf_map` (keine Lead-Inhalte) |
| Transient `psl_rl_{hmac}` | – | Rate-Limit-Zähler je gehashtem Client (10 min), keine Klartext-IP |
| Transient `psl_lead_used_{uuid}` | – | bereits verwendete Lead-IDs (1 Tag) |
| `psl_rewrite_version` | ja | Version der Rewrite-Regeln (Phase 2); Abweichung → einmaliger Flush. Wird bei Deaktivierung/Deinstallation gelöscht |
| Transient `psl_property_statuses` | – | Statusliste für die Einstellungsseite (1 h) |

## Migrationen

| Version | Datum | Änderung | Migrationsweg |
|---|---|---|---|
| 1 | 2026-10-05 | Tabelle angelegt | `Schema::install()` per `dbDelta`. Zusätzlich `Settings::migrateLegacy()`: übernimmt `status=` aus den 0.2.x-`query_params` als öffentliche Status, entfernt alte Settings-Schlüssel, Option `propstack_lite_cache_salt` und Transients `propstack_lite_cache*`. Seit 0.4.1 läuft die Migration zusätzlich beim Plugin-Start (`Settings::maybeMigrateLegacy()`), weil ein Update per ZIP-Upload („Version ersetzen“) oder FTP den Aktivierungs-Hook nicht auslöst; danach wird ein Voll-Sync eingeplant. Getestet: 0.2.0 → 0.4.1 und 0.3.0 → 0.4.1 per Dateiersatz (Einstellungen, Key, Webhook-Token erhalten; eine Tabelle; kein Datenverlust). |

Neue Schema-Version: `Schema::VERSION` erhöhen, `Schema::sql()` anpassen (dbDelta-Formatregeln beachten), bei Datenmigrationen Schritt in `maybeUpgrade()` ergänzen, diese Tabelle und `changelog.md` pflegen. Das JSON-Format in `data` ist separat versioniert (`Property::FORMAT_VERSION`); ein Voll-Sync schreibt alle aktiven Zeilen neu.
