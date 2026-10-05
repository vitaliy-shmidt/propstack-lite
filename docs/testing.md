# Tests

## Strategie

| Ebene | Werkzeug | Läuft ohne WordPress | Inhalt |
|---|---|---|---|
| Unit | PHPUnit 10 (`tests/Unit`) | ja | Mapper/Datenschutzregeln, Sanitizer, Slugger, Formatter, StateResolver, Client (Retry, Fehler, Secrets), Pagination |
| Integration | PHPUnit 10 (`tests/Integration`) + WordPress-Wegwerfinstanz | nein | Store (Sichtbarkeit, Filter, SQL-Whitelist), alle Sync-Übergänge mit simuliertem Propstack (`FakePropstack`), Shortcode-Ausgabe, keine HTTP-Requests im Frontend |
| HTTP (End-to-End) | PHPUnit 10 (`tests/Http`) + laufender Webserver der Testinstanz | nein | echte Requests: Routing-Matrix 200/301/404/410, Legacy, Unterverzeichnis, Canonical/Robots, Datenschutz und XSS im HTML, 0 Propstack-Requests |
| Contract (manuell) | WP-CLI gegen echte API (nur lesend) | nein | `wp psl sync --full`, `wp psl audit` |
| Manuell/Staging | Checklisten unten | – | Admin, Frontend, später Avada/CF7/SEO/Tracking |

## Ausführen

```bash
cd propstack-lite
composer install
vendor/bin/phpunit --testsuite unit
PSL_WP_LOAD=/pfad/zur/wegwerf-instanz/wp-load.php vendor/bin/phpunit --testsuite integration
PSL_WP_LOAD=… PSL_TEST_BASE_URL=http://127.0.0.1:8099/Picaflor vendor/bin/phpunit --testsuite http
```

**Integrations- und HTTP-Tests leeren `{prefix}psl_properties` und ändern Plugin-Optionen (werden danach wiederhergestellt) – nie gegen Staging/Produktion.** Ohne `PSL_WP_LOAD` bzw. `PSL_TEST_BASE_URL` werden sie übersprungen. Nach den Tests ist der Store leer → `wp psl sync --full`.

### Testinstanz (Stand Phase 2)

- WordPress 7.1.2 (de_DE), MariaDB 10.4 (XAMPP, DB `psl_dev_test`), PHP 8.3; liegt außerhalb des Projekts.
- **Unterverzeichnis `/Picaflor/`**: `home = http://127.0.0.1:8099/Picaflor`, Pretty Permalinks `/%postname%/`, PHP-Built-in-Server mit Router-Skript (simuliert Apache-Rewrites, liefert statische Dateien aus).
- Themes: Twenty Twenty-One (klassisch, wie Avada; Standard für Tests) und Twenty Twenty-Five (Block-Theme, Gegentest).
- `DISABLE_WP_CRON = true`, damit kein Cron-Sync Testdaten verändert.
- Plugin per Junction eingebunden; API-Key zur Laufzeit aus `Propstack-API.txt` als `PSL_API_KEY` (Datei wird nicht kopiert).
- mu-plugin `tests/Support/mu-plugins/psl-http-spy.php` (nur Testinstanz) protokolliert alle ausgehenden HTTP-Requests mit Kontext (`web`/`cron`/`cli`).
- Testserver nur über die selbst dokumentierte PID beenden – keine globalen `taskkill`-Befehle.

## Fixtures

`tests/fixtures/*.json` – **anonymisiert/synthetisch**, bilden die beobachtete API-Struktur nach (Label/Value-Format, Bild-Flags, Broker mit internen und öffentlichen Feldern) und enthalten bewusst interne „Köder“-Felder (`note`, `token`, `relationships`, interne Maklerkontakte), deren Werte im Modell nicht auftauchen dürfen. Keine echten Kundendaten in Fixtures aufnehmen.

## Wichtige Testfälle (implementiert)

- Adresse verborgen → keine Straße/Hausnummer/Koordinaten im Modell und in der Ausgabe; fehlendes Flag = verborgen
- private, nicht freigegebene, uneindeutig markierte Bilder werden verworfen; Grundrisse getrennt; fremde Hosts/HTTP-URLs verworfen
- interne CRM-Felder und interne Maklerkontakte erscheinen nirgends im Modell
- `0` = nicht angegeben (außer Etage); Freitext-Kaution bleibt Text; Preiszeile nie „0 €“
- Client: Key nur im Header, nie in Fehlermeldungen; Retry/Backoff; 401 ohne Retry; `Retry-After`
- Pagination: `per`/`sort_by=id`, `total_count`, Deduplizierung, Unvollständigkeit erkannt
- StateResolver: 19 Zustandskombinationen
- Sync: nur öffentliche Objekte gespeichert; verkauft → 30 Tage → Daten entfernt; Statuswechsel/Löschung → removed; Reaktivierung; API-Fehler lässt Bestand unverändert; ohne öffentliche Status kein Request; Inkrement speichert nie Nicht-Öffentliches; Lock; Reconcile-Fallback
- Store/Shortcode: Status-Whitelist erzwungen, `status`-Attribut wirkungslos, Escaping, Filter/Sortierung/Paging, SQL-Injection-Versuche in Kriterien wirkungslos, **0 Propstack-Requests beim Rendern**

## Ergebnisse Phase 2 (2026-10-05)

- Unit: 76 Tests, 220 Assertions – grün (neu: RouteResolver-Statusmatrix inkl. 30-Tage-Grenze, Status-Badges, ViewModel).
- Integration: 16 Tests, 66 Assertions – grün.
- HTTP: 9 Tests, 98 Assertions – grün. Routing-Matrix mit 22 Fällen (Soll = Ist), siehe [routing-seo.md](routing-seo.md); Unterverzeichnis `/Picaflor/` in allen URLs und `Location`-Headern; Kampagnenparameter bleiben bei 301 erhalten.
- Request-Nachweis: 9 Seitenaufrufe aller Zustände → 0 ausgehende Requests, 0 Propstack-Requests.
- Datenschutz/XSS im HTML: keine Straße bei `hide_address`, keine privaten/`is_not_for_exposee`-Bilder, keine internen CRM-Werte, kein ausführbarer Code aus `<script>`-Payloads (Titel, Beschreibung, Maklername, Bildtitel; über Mapper und direkt im Store).
- Rewrite-Lifecycle: Deaktivierung → 0 Plugin-Regeln; Aktivierung → beide Regeln ohne manuelles Speichern der Permalinks.
- **Manuell (Edge headless, Screenshots):** Desktop 1366 px und Mobil 390 px (in 390 px breitem iframe, da Headless-Fenster eine Mindestbreite haben) für gültig, reserviert, vermietet (Verkauft-Phase), 410, 404; Reload/Direktaufruf; Block-Theme-Gegentest (Twenty Twenty-Five) mit Header/Footer; Theme-Override eines Teil-Templates und Hooks `psl_property_contact`/`psl_property_status_label` geprüft. Dabei behoben: `dd`-Einrückung durch Theme-Stile, Wortumbruch langer Titel (jetzt `hyphens: auto`).
- **Nicht verifiziert:** Avada, Yoast/Rank Math, CF7, Page-Cache-Plugins, echte Mobilgeräte.

## Ergebnisse Phase 1 (2026-10-05)

- Unit: 65 Tests, 185 Assertions – grün. Integration: 16 Tests, 66 Assertions – grün.
- Echter Voll-Sync (nur lesend): Konfiguration „Website Picaflor“ → 1 öffentliches Objekt. Belastungstest mit drei öffentlichen Status → 51 Objekte in ≈ 2,7 s; `wp psl audit` ohne Verstöße; 11 Objekte mit verborgener Adresse, davon 0 mit gespeicherter Straße. Rückstellung auf einen Status → 50 Objekte korrekt `removed` (Reconcile gegen echte API), Daten entfernt.
- Frontend über HTTP (PHP-Built-in-Server, Mess-mu-plugin auf `pre_http_request`): 4 Seitenaufrufe, 0 ausgehende Requests; Propstack-Requests nur im Kontext `wp-cron.php`.
- `debug.log`: keine Warnings/Notices des Plugins.

## Manuelle Checkliste (je Release)

- [ ] Aktivierung ohne manuelles Speichern der Permalinks; Cron-Events `psl_sync_*` geplant
- [ ] Einstellungen: Statusliste lädt; Key-Feld leer lassen behält Key; Konstante wird erkannt
- [ ] `wp psl sync --full`, `wp psl status`, `wp psl audit` ohne Fehler/Verstöße
- [ ] Liste: keine Straße bei verborgener Adresse, keine privaten Bilder, kein „0 €“, Badges korrekt
- [ ] Fehlender Key / API-Fehler → Admin-Hinweis, Liste bleibt mit letztem Stand online
- [ ] Deaktivierung entfernt Cron-Events und Rewrite-Regeln; Deinstallation entfernt Tabelle und Optionen
- [ ] Detailseiten: gültig 200, falscher Slug 301, unbekannt 404, entfernt 410, verkauft (≤ 30 Tage) 200 + noindex, reserviert 200 + Badge
- [ ] Legacy `/immobilie/…` und `?ps_id=` → 301; ohne gültige ID → 404
- [ ] Desktop und Mobil: kein horizontaler Überlauf, Header/Footer des Themes vorhanden
- [ ] Mit aktivem SEO-Plugin: genau ein Canonical, keine widersprüchlichen Robots-Tags

## Geplante Tests (spätere Phasen)

- **Avada (Phase 2/3):** reale Testumgebung, Header/Footer/Container, Lightbox, mobil
- **CF7 (Phase 4):** Formatter-Unit-Tests, manipulierte `property_id`, Honeypot, Rate-Limit; Ende-zu-Ende-Test in Propstack nur nach Freigabe
- **SEO (Phase 5):** Head-Snapshots je Adapter, Schema-Validator, Sitemap-Inhalt
- **Tracking (Phase 6):** genau ein `property_lead` nach Erfolg, keiner bei Fehler; keine PII im dataLayer; kein Cookie ohne Consent
