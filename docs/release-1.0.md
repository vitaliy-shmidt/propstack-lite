# Release 1.0 – Release Candidate 0.9.0

Stand: 2026-10-08. **0.9.0 ist der Release Candidate für 1.0.** Version 1.0.0 wird erst nach Staging-Abnahme und ausdrücklicher Freigabe gesetzt. Betrieb/Diagnose: [operations.md](operations.md).

## Voraussetzungen

| | Mindestens | Getestet |
|---|---|---|
| **PHP** | **8.1** | 8.1.31, 8.2.12 (nur Unit-Tests), 8.3.2 – Unit, Integration, HTTP; 8.1 zusätzlich mit WordPress 6.4.5 (Sync, Liste, Filter, Detail, Sitemap) |
| **WordPress** | **6.4** | 7.1.2 (vollständige Suiten), 6.4.5 (Installation aus ZIP, Sync, HTTP-Smoke-Test). 6.3.5: Installation/Aktivierung wird korrekt abgelehnt |
| Datenbank | MySQL/MariaDB mit InnoDB, utf8mb4 | MariaDB 10.4 |
| PHP-Erweiterungen | json, mbstring (WordPress-Standard) | – |
| Contact Form 7 | optional (für Anfragen) | 6.1.7 |
| SEO-Plugin | optional: Yoast SEO oder Rank Math | Yoast 28.6, Rank Math 1.0.279, ohne SEO-Plugin |
| Theme | beliebig (Avada über optionalen Adapter) | Twenty Twenty-One, Twenty Twenty-Five; **Avada nicht getestet** |

Auf zu altem PHP oder WordPress verweigert WordPress die Installation/Aktivierung (Plugin-Header) bzw. – bei bereits aktivem Plugin nach einem PHP-Downgrade – bootet das Plugin nicht und zeigt Administratoren einen Hinweis; kein Fatal Error, der Shortcode gibt nichts aus (getestet unter echtem PHP 7.4.33 und mit simuliertem WordPress 6.3).

**Webserver:** getestet ausschließlich mit dem PHP-Built-in-Server (Rewrite per Router-Skript). Das Plugin nutzt nur WordPress-Rewrite-Regeln (keine `.htaccess`-/nginx-Annahmen); Apache/nginx der Live-Site sind **nicht getestet**.

## Release-Paket

- Datei: `propstack-lite-0.9.0.zip` (Ordner `propstack-lite/`), SHA256 `7370ca1863312473507df9a43971bd132247ad8a4a31e8523e5f0f4f5d4b236f`, daneben `propstack-lite-0.9.0.zip.sha256` und `propstack-lite-0.9.0.files.txt` (Dateiliste).
- Enthalten: `propstack-lite.php`, `uninstall.php`, `src/**/*.php`, `templates/**/*.php`, `assets/css/*.css`, `assets/js/*.js` – sonst nichts.
- **Nicht** enthalten: `tests/`, Fixtures, `vendor/`, `composer.*`, `phpunit.xml.dist`, `phpstan.neon.dist`, `bin/`, `dist/`, `docs/` (interne Projektdoku mit Testdetails – bewusst nicht ausgeliefert), `.git*`, `.github/`, IDE-Dateien, Logs, Screenshots, Datenbank-Dumps, `Propstack-API.txt`.

### Composer, `vendor/` und Paketgröße

| | Stand 2026-10-08 |
|---|---|
| `require` (Laufzeit) | nur `php >=8.1` – **keine Composer-Laufzeitpakete**; `composer.lock`: 0 Produktions-, 28 Dev-Pakete |
| `require-dev` | `phpunit/phpunit` ^10.5, `phpstan/phpstan` ^2.1, `php-stubs/wordpress-stubs` ^6.6 (+ Abhängigkeiten); Brain Monkey und PHPCS werden nicht verwendet |
| Produktionscode und Composer | kein Bezug auf `vendor/` oder Composer (eigener PSR-4-Autoloader in `propstack-lite.php`; Unit-Test `OperationsTest::test_no_composer_runtime_dependencies`) |
| Entwicklungsordner `propstack-lite/` | ≈ 272 MB, davon `vendor/` ≈ 270 MB (größte Dev-Pakete: `phpstan/phpstan` 255,8 MB, `php-stubs/wordpress-stubs` 5,1 MB, `phpunit/phpunit` 4,5 MB, `nikic/php-parser` 1,4 MB, `phpunit/php-code-coverage` 1,3 MB) |
| `vendor/` in Git | nein (`.gitignore`: `propstack-lite/vendor/`, 0 getrackte Dateien) |
| `composer install --no-dev --optimize-autoloader` | geprüft in separatem Verzeichnis: „Nothing to install“, 0 Pakete (nur leerer Composer-Autoloader) – wird **nicht** benötigt und nicht ausgeliefert |
| Release-ZIP `propstack-lite-0.9.0.zip` | 181,3 KB (185.646 Byte), entpackt 436,7 KB Dateiinhalt (≈ 702 KB auf NTFS belegt), 115 Dateien: 109 PHP, 4 JS, 2 CSS; SHA256 `7370ca1863312473507df9a43971bd132247ad8a4a31e8523e5f0f4f5d4b236f` |

**`vendor/` entfällt im Release vollständig.** Der Build arbeitet mit einer Datei-Whitelist und bricht zusätzlich ab, wenn `composer.json` eine Laufzeitabhängigkeit enthält (dann wäre ein Build-Weg mit `composer install --no-dev --optimize-autoloader` in einem separaten Build-Verzeichnis nötig) oder ein Pfad wie `vendor/`, `tests/`, `fixtures/`, `bin/`, `docs/`, `.git*`, `.idea`, `.vscode`, `phpstan`, `phpunit`, `brain`, `wordpress-stubs`, `phpcs` bzw. eine Datei `.log/.sql/.zip/.png/.jpg/.txt/.md/.neon/.xml/.dist/.lock/.json` ins Paket gelangen würde (Gegenprobe mit eingeschleuster Abhängigkeit: Abbruch). Ein Build aus einer separaten Kopie **mit** vorhandenem `vendor/` ergibt denselben SHA256. Das entpackte Release wurde auf Dev-Abhängigkeiten, Tests, Fixtures, IDE-Dateien, Logs, lokale Pfade/Daten, echte IDs und den API-Key geprüft (Packaging-Audit, Abnahmekriterium der Phase 8).

### Release-Build

```bash
cd propstack-lite
composer build                 # = php bin/build-release.php → dist/propstack-lite-{version}.zip
php bin/build-release.php --expect=0.9.0 --out=/pfad/zum/ziel
```

Der Build bricht ab, wenn Header-Version ≠ `PSL_VERSION`, „Requires PHP/at least“ ≠ `PSL_MIN_PHP/PSL_MIN_WP`, kein Changelog-Eintrag „(Version x.y.z)“ existiert, eine PHP-Datei Syntaxfehler hat, ein lokaler Pfad/Host (`C:\Users`, `xampp/htdocs`, `127.0.0.1:8099` …) oder der API-Key aus `Propstack-API.txt` in einer Datei steht. Das ZIP ist **reproduzierbar**: sortierte Einträge, Zeitstempel = Datum des Changelog-Eintrags (überschreibbar per `SOURCE_DATE_EPOCH`), LF-Zeilenenden, feste Rechte – zwei Builds (auch PHP 8.2 vs. 8.3, Windows) ergeben denselben SHA256; die CI baut zweimal und vergleicht.

### Versionsquellen

Einzige Quelle im Code ist `PSL_VERSION` (`propstack-lite.php`); der Header muss übereinstimmen (Build + Unit-Test `OperationsTest::test_version_is_consistent`). Assets werden mit `PSL_VERSION` versioniert (Cache-Busting). Die **Schema-Version** (`Schema::VERSION`, aktuell 2) ist bewusst unabhängig und ändert sich nur mit Tabellenänderungen. Changelog-Überschrift und ZIP-Name folgen der Plugin-Version.

## Installation (neu)

1. WordPress → Plugins → Installieren → **Plugin hochladen** → `propstack-lite-0.9.0.zip` → aktivieren.
2. `wp-config.php`: `define( 'PSL_API_KEY', '…' );` (bevorzugt statt Datenbank-Option) und System-Cron ([operations.md](operations.md#empfohlener-system-cron-produktion)).
3. Einstellungen → Propstack Lite → **Sichtbarkeit**: öffentliche Status (z. B. „Website“), Verkauft/Vermietet-, Reserviert-Status wählen → Speichern (plant automatisch einen Voll-Sync) oder „Jetzt vollständig synchronisieren“.
4. Seite `/immobilien/` mit `[propstack_list per="12" heading="h2"]` anlegen (siehe Shortcode).
5. Anfragen: CF7-Formular laut [leads.md](leads.md#benötigtes-cf7-formular), dann Formular-ID und Propstack-Zieladresse in den Einstellungen.
6. Optional SEO-Plugin (Yoast/Rank Math) – wird automatisch erkannt; Tracking erst mit Consent-Tool ([tracking.md](tracking.md)).
7. Kontrolle: Diagnose-Box bzw. `wp psl doctor` ohne kritische Probleme; Site Health ohne Propstack-Empfehlungen.

Getestet aus dem Release-ZIP: Installation, Aktivierung (Tabelle, Schema 2, Rewrite-Regeln, Cron-Events je einmal), Konfiguration, Sync über `wp cron event run --due-now`, alle Integrations- und HTTP-Suiten in allen SEO-Modi gegen die installierte ZIP-Kopie.

## Update

**Von 0.2.0 bis 0.6.0** (alle getestet): Plugins → Installieren → Plugin hochladen → ZIP → „**Die aktuelle Version mit der hochgeladenen ersetzen**“. Alternativ per FTP den Ordner `propstack-lite/` vollständig ersetzen (nicht überkopieren).

Beim ersten Request nach dem Update laufen automatisch (ohne Reaktivierung): Settings-Migration aus 0.2.x, Schema-Migration 1 → 2 (`search_price`, Backfill aus dem Bestand, `price_idx` entfernt), Rewrite-Version, Cron-Selbstheilung. Erhalten bleiben (geprüft für 0.2.0, 0.3.0, 0.4.0, 0.4.1, 0.5.0, 0.6.0): Einstellungen, API-Key, Status-Konfiguration (aus 0.2.x: `status=` der alten Query-Parameter), CF7-Formular/Feldzuordnung/Zieladresse, Tracking-Einstellungen und Propstack-Feldzuordnung, Webhook-Token, alle Bestandszeilen; keine doppelten Tabellen, keine doppelten Cron-Events, keine Entwicklungsdateien älterer Pakete.

Danach: Diagnose prüfen, einmal „Jetzt vollständig synchronisieren“.

### Sichtbare Änderung seit 0.6.0 / Shortcode-Anpassung beim Live-Update

`[propstack_list]` ist seit 0.6.0 standardmäßig eine interaktive Suche (Filter, Sortierung, Pagination). **Vor dem Live-Update alle Einbindungen prüfen:**

| Einbindung | Empfehlung |
|---|---|
| Hauptübersicht `/immobilien/` | `[propstack_list per="12" heading="h2"]` – ein früher hochgesetztes `per` (Behelf vor der Pagination) auf 12 zurücksetzen; `heading="h2"`, wenn die Seite nur die Seiten-H1 trägt |
| Teaser (Startseite, Seitenleisten, Landingpages) | `[propstack_list per="3" show_filters="0" show_sort="0" pagination="0"]` (+ feste Filter wie `marketing_type="RENT"`) – statisch wie vor 0.6, liest keine URL-Parameter |

Ältere Attribute (`per`, `limit`, `page`, `marketing_type`, `rs_type`, `city`, `zip_code`, `price_from/to`, `min/max_price`, `sort_by`, `order`, `heading`) funktionieren weiter; `status` und andere historische API-Passthroughs bleiben wirkungslos (getestet). Details: [listing.md](listing.md#shortcode-propstack_list).

### Downgrade

Nicht unterstützt, aber geschützt: Die Datenbank wird nie zurückmigriert. Eine ältere Plugin-Version ab 0.9.0 erkennt eine neuere Schema-Version (`schema_newer`), migriert nicht und sperrt den Sync (Lesen bleibt möglich). 0.9.0 → 0.6.0 ist unkritisch (gleiche Schema-Version 2). Ältere Versionen vor 0.9.0 kennen diesen Schutz nicht – nach einem Schema-Update (künftige 1.x) nicht auf < 0.9.0 zurückgehen ohne DB-Backup.

## Konfiguration (Kurzreferenz)

- **Status:** nur Objekte mit `state = active` und einem öffentlichen Status erscheinen; „Reserviert“ → Badge; „Verkauft/Vermietet“ → 30 Tage Hinweisseite (noindex), danach 410 ([routing-seo.md](routing-seo.md)).
- **CF7 / Propstack-Lead:** CF7 sendet eine HTML-Mail mit Block `ps-kontaktanfrage` an die Propstack-Adresse; Objektzuordnung serverseitig verifiziert, keine Lead-Inhalte gespeichert ([leads.md](leads.md)). Propstack-Postfach-Automatisierung ist außerhalb des Plugins einzurichten.
- **Tracking:** Standard aus; ohne Consent-Provider wird nichts erfasst ([tracking.md](tracking.md)).
- **SEO:** Yoast > Rank Math > eigener Core-Modus; Detailseiten mit Title/Description/Canonical/Robots/OG/JSON-LD, Sitemap; Übersicht: Self-Canonical, Filter `noindex, follow` ([seo.md](seo.md), [listing.md](listing.md#seo-verhalten)).
- **Cron:** System-Cron empfohlen ([operations.md](operations.md#cron-und-sync)).

## Deaktivierung und Deinstallation

| Aktion | Wirkung |
|---|---|
| Deaktivieren | entfernt Cron-Events (`psl_sync_*`) und die Rewrite-Regeln (Detail-URLs → 404); **Daten, Einstellungen und API-Key bleiben**. Reaktivierung stellt alles wieder her (getestet). |
| Löschen (Deinstallation) | entfernt Tabelle `{prefix}psl_properties`, alle Optionen (`propstack_lite_settings`, `psl_db_version`, `psl_sync_state`, `psl_sync_lock`, `psl_rewrite_version`, Legacy `propstack_lite_cache_salt`), alle `psl_*`-Transients und Cron-Events. Propstack bleibt unverändert. Folge: Die 410-Historie ehemaliger Objekte geht verloren (nach Neuinstallation antworten alte IDs mit 404). Eine Option „Daten behalten“ gibt es bewusst nicht – Propstack ist führend, ein Voll-Sync baut den Bestand neu auf. CF7-Formulare und SEO-Plugin-Daten bleiben. |

## Live-Rollout-Plan

### Vorher
1. Vollständiges **Datenbank-Backup** und **Datei-Backup** (`wp-content/plugins/propstack-lite/`, `wp-config.php`).
2. Aktuelle Plugin-Version notieren (Plugins-Seite) und deren ZIP bereithalten (Rollback).
3. **PHP ≥ 8.1** und **WordPress ≥ 6.4** prüfen (Website-Zustand → Info).
4. Cron prüfen: `DISABLE_WP_CRON` gesetzt? System-Cron vorhanden?
5. CF7 aktiv, Formular und Mailversand (SMTP/Absender) funktionieren.
6. Liste aller Seiten mit `[propstack_list]` erstellen und Ziel-Attribute festlegen (Tabelle oben).
7. Wartungsfenster außerhalb der Hauptzeit; Page-Cache-Plugin bekannt.

### Update
1. ZIP hochladen → „Version ersetzen“ (bzw. Ordner ersetzen).
2. Einstellungsseite öffnen → keine kritischen Hinweise; Diagnose: Plugin-Version 0.9.0, **Datenbankschema 2 (erwartet 2)**.
3. Einstellungen prüfen (Status, CF7-Formular-ID, Zieladresse, Tracking) – sollten unverändert sein.
4. „**Jetzt vollständig synchronisieren**“ → Erfolgsmeldung, Objektzahl plausibel.
5. Shortcodes gemäß Tabelle anpassen.
6. Page-Cache leeren (das Plugin leert unterstützte Caches nach Sync/Einstellungsänderung, siehe [architecture.md](architecture.md)).

### Smoke-Test
- `/immobilien/`: Trefferzahl, Karten, Pagination, ein Filter (Kauf/Miete), Sortierung; „Filter zurücksetzen“.
- Detailseite: Galerie/Lightbox, Fakten, Ansprechpartner, Formular sichtbar.
- Formular: Testanfrage nur, wenn das Propstack-Postfach dafür freigegeben ist ([leads.md](leads.md#ende-zu-ende-test-bereit-nur-nach-ausdrücklicher-freigabe)); sonst nur Validierung prüfen.
- SEO: Quelltext Detailseite – genau ein Canonical, Robots `index`; Filterseite `noindex, follow`; Sitemap (`/wp-sitemap.xml` bzw. Sitemap des SEO-Plugins) enthält Objekte.
- Mobil (echtes Gerät): Liste, Filter-Panel, Detailseite.

### Nachher (24–48 h)
- Site Health → keine Propstack-Empfehlungen; `wp psl doctor`.
- Logs: `[propstack-lite] ERROR|WARNING`.
- Cron: „Letzter inkrementeller Sync“ aktualisiert sich; kein `sync_stale`.
- Mail: Anfragen kommen in Propstack an (sofern Postfach aktiv).

## Rollback

| Situation | Maßnahme |
|---|---|
| Darstellungs-/Funktionsproblem, Daten in Ordnung | **Plugin-Rollback genügt:** vorherige ZIP hochladen → „Version ersetzen“. 0.9.0 → 0.6.0 ohne DB-Restore möglich (gleiche Schema-Version 2). Einstellungen bleiben. |
| Rollback auf < 0.6.0 | Plugin-ZIP der Version + **DB-Restore** empfohlen (Schema 2 ist mit 0.3–0.5 lesbar, `search_price` wird dort ignoriert; ältere Versionen sind aber nicht gegen dieses Schema getestet). |
| Daten beschädigt / Migration fehlgeschlagen (`schema_missing`) | DB-Backup zurückspielen, vorherige Plugin-Version einspielen; alternativ Tabelle verwerfen und neu synchronisieren (Propstack ist führend; Verlust nur der 410-Historie). |
| Nur der Bestand ist falsch | kein Restore nötig: Einstellungen korrigieren → Voll-Sync. |

Kein automatisches Rollback-System.

## Bekannte Einschränkungen

- Avada, das tatsächliche SEO-Plugin der Live-Site, Page-Cache/Hosting (DomainFactory), Apache/nginx und echte Mobilgeräte sind **nicht getestet** (kein Staging-Zugang).
- Propstack-E2E der Anfragen: **BLOCKED** (Postfach/Automatisierung/CRM-Prüfzugang nicht bestätigt).
- Tracking ohne reales Consent-Tool nicht in Betrieb (Provider `none`).
- Kartenbilder ohne `srcset` (keine Propstack-Zwischengröße); mobile Detailseiten laden das 1920-px-Bild.
- Zentraler Fallback-Ansprechpartner nur per Filter; ähnliche Immobilien, Merkliste, Kartensuche nicht enthalten.
- WP-Cron ohne System-Cron ist seitenaufrufabhängig.
