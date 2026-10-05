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
- mu-plugin `tests/Support/mu-plugins/psl-test-hooks.php` (nur Testinstanz): optionaler Filter für das Status-Label (Option `psl_test_status_label`), um Escaping von Filter-Ausgaben zu testen; Mail-Capture (Option `psl_test_mail_capture` → Mails nach `wp-content/psl-mail-capture.jsonl`, kein Versand) und simulierter Mailfehler (Option `psl_test_mail_fail`).
- Contact Form 7 6.1.7 in der Testinstanz installiert; `InquiryTest` legt eigene Testformulare an und löscht sie wieder.
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

## Ergebnisse Phase 4 (2026-10-05)

- Unit: 107 Tests, 416 Assertions – grün (neu: `LeadTest` – Lead-Kontext mit manipulierten/unbekannten/nicht anfragbaren IDs, Zustimmung, Header-Injection; Mailformatter mit allen/optionalen Feldern, Sonderzeichen, XSS, internen Feldern, Custom Fields; Rate-Limiter mit Fenster, Clients, ohne Klartext-IP).
- Integration: 16 Tests, 66 Assertions – grün.
- HTTP: 31 Tests, 326 Assertions – grün (neu: `InquiryTest` mit **echtem Contact Form 7 6.1.7** über den CF7-REST-Feedback-Endpunkt; Mail-Capture über `pre_wp_mail`, nichts wird versendet): Formular nur auf aktiv/reserviert; gültige Anfrage → Propstack-Mail (Empfänger, HTML, `Reply-To`, alle Felder, `property_id`, URL); reserviert erlaubt; manipulierte IDs (verkauft, entfernt, nicht öffentlich, unbekannt, ungültig) abgelehnt ohne Mail; gefälschte Titel/URL ignoriert; fremdes CF7-Formular unverändert (Empfänger, kein Block, Honeypot ignoriert); Rate-Limit (6. Anfrage abgelehnt, keine Klartext-IP); fehlende Zieladresse → kein Formular, POST abgelehnt; Zustimmung fehlt → CF7 `acceptance_missing`; Honeypot → `spam`; XSS/Header-Injection; Lead-ID-Custom-Field und serverseitige Eindeutigkeit; Mailfehler → `mail_failed`; keine Anfrageinhalte in der DB; Logs ohne PII; 0 Propstack-Requests; Seite ohne CF7 rendert ohne Fehler.
- Browser (Edge, synthetische Daten): Formular auf Desktop/390 px ohne Überlauf; CTA springt zu `#psl-contact` und setzt den Fokus; Honeypot unsichtbar; echte Absendung im Browser → CF7-Event `wpcf7mailsent` mit `mail_sent`, abgefangene Mail mit vollständigem `ps-kontaktanfrage`-Block.
- Hinweis Testumgebung: CF7 wertet Anfragen ohne User-Agent als Spam – der Test-HTTP-Client sendet daher einen Browser-User-Agent.
- **Nicht durchgeführt:** echter E2E-Test mit dem Propstack-Postfach (schreibender CRM-Vorgang, Anleitung in [leads.md](leads.md)).

## Ergebnisse Phase 3 (2026-10-05)

- Unit: 94 Tests, 336 Assertions – grün (neu: `FormatterTest`, `PropertyViewModelTest` mit anonymisierter Fixture `unit-full.json`).
- Integration: 16 Tests, 66 Assertions – grün.
- HTTP: 16 Tests, 205 Assertions – grün (neu: `DetailPageContentTest`: alle Abschnitte beim vollständigen Objekt, keine leeren Sektionen beim Minimalobjekt, Galerie-/Lightbox-Markup, Grundriss nur im Grundriss-Bereich, Datenschutz, öffentliche Maklerfelder, XSS inkl. Status-Label, Assets nur auf Detailseiten, Request-Nachweis).
- Request-Nachweis: 0 ausgehende Requests bei allen Detailseiten-Aufrufen.
- **Browser (Edge headless über `puppeteer-core`, nur Testumgebung):** kein horizontaler Überlauf bei 390/768/1024/1366 px (je 3 echte Objekte, darunter 23 Bilder, 4 Grundrisse, Mietobjekt); Lightbox per Klick, Enter, Pfeiltasten (Umlauf), Buttons, Wischen; ESC und Schließen-Button; Tab/Shift+Tab bleiben im Dialog; Fokus kehrt zum Auslöser zurück; Grundrisse als eigene Gruppe; CTA springt zu `#psl-contact`; keine Koordinaten bei verborgener Adresse im DOM; keine JS-Fehler des Plugins (einziger Konsolenfehler: `/favicon.ico` 404 des Testservers).
- **Lighthouse 12 (Edge, Detailseite mit 23 Bildern):** mobil Performance 88, Accessibility 100, Best Practices 96, LCP 3,7 s, CLS 0, TBT 0 ms; Desktop Performance 100, Accessibility 100, Best Practices 96, LCP 0,5 s, CLS 0. Einordnung: render-blocking/ungenutztes CSS stammt überwiegend vom Theme (152 KiB); „Properly size images“ betrifft das Hauptbild (keine Propstack-Zwischengröße 600–1920 px); der PHP-Built-in-Testserver (single-threaded, ~380 ms TTFB) ist nicht repräsentativ. Werte sind Orientierung, nicht Produktionsmessung.
- Visuell geprüft (Screenshots, nicht im Repository): Desktop und Mobil; behoben: Lightbox-Fokusfalle, Wischen (Bild-Drag), Wortumbruch der H1 auf Mobil, `sizes` des Hauptbilds.
- Werkzeuge `puppeteer-core`/`lighthouse` liegen nur in der Testumgebung (kein Bestandteil des Repositories).

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
