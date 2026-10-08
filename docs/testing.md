# Tests

## Strategie

| Ebene | Werkzeug | Läuft ohne WordPress | Inhalt |
|---|---|---|---|
| JavaScript | `node --test tests/js/tracking.test.cjs tests/js/list.test.cjs` (Node ≥ 18, ohne Abhängigkeiten; Dateien explizit angeben) | ja | Attributionslogik: Touches, First/Last, TTL, Klassifikation, Bereinigung/XSS, Consent/Cookie; Such-Skript (leere/Standardwerte nicht senden) |
| Unit | PHPUnit 10 (`tests/Unit`) | ja | Mapper/Datenschutzregeln, Sanitizer, Slugger, Formatter, StateResolver, Client (Retry, Fehler, Secrets), Pagination, SEO-Werte (Title, Description, Robots, OG, JSON-LD) |
| Integration | PHPUnit 10 (`tests/Integration`) + WordPress-Wegwerfinstanz | nein | Store (Sichtbarkeit, Filter, SQL-Whitelist), alle Sync-Übergänge mit simuliertem Propstack (`FakePropstack`), Shortcode-Ausgabe, keine HTTP-Requests im Frontend |
| HTTP (End-to-End) | PHPUnit 10 (`tests/Http`) + laufender Webserver der Testinstanz | nein | echte Requests: Routing-Matrix 200/301/404/410, Legacy, Unterverzeichnis, Head-Tags je SEO-Modus ohne Dubletten, Schema-Validierung, Sitemaps, Datenschutz und XSS im HTML, 0 Propstack-Requests |
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

### SEO-Modi (Phase 5)

Die SEO-HTTP-Tests prüfen je eine Plugin-Konstellation; Klassen anderer Modi werden übersprungen (Modus wird aus `active_plugins` erkannt). Für den vollständigen Nachweis die Suite viermal laufen lassen und dazwischen per WP-CLI umschalten (danach Rewrite-Flush):

| Lauf | Plugins | Testklasse |
|---|---|---|
| Core | weder `wordpress-seo` noch `seo-by-rank-math` aktiv | `SeoCoreTest` |
| Yoast | nur `wordpress-seo` | `SeoYoastTest` |
| Rank Math | nur `seo-by-rank-math` (+ Option `rank_math_registration_skip = 1`, entspricht „Überspringen“ im Rank-Math-Assistenten) | `SeoRankMathTest` |
| Konflikt | beide | `SeoConflictTest` |

```bash
wp plugin activate wordpress-seo && wp rewrite flush   # Beispiel Yoast-Lauf
```

In der Testinstanz installiert (standardmäßig inaktiv): Yoast SEO 28.6, Rank Math 1.0.279. mu-plugin `psl-test-hooks.php` zusätzlich: Option `psl_test_sitemap_max_urls` verkleinert die Sitemap-Seitengröße (Core/Yoast) für Paginierungstests; Rank Math über seine Einstellung `items_per_page`.

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

## Ergebnisse Phase 8 (2026-10-08, 0.9.0 – Release Candidate)

Testobjekt ist das **Release-ZIP** (`propstack-lite-0.9.0.zip`, SHA256 `7370ca18…236f`): `tests/bootstrap.php` lädt bei WordPress-Tests die Plugin-Klassen aus der installierten Kopie, nicht aus dem Repository.

| Suite | Ergebnis |
|---|---|
| JavaScript | 20/20 grün |
| Unit | 220 Tests, 1051 Assertions – grün unter PHP **8.1.31, 8.2.12, 8.3.2** (neu `OperationsTest`: Diagnose-Bewertung, Codes, Logger ohne PII, Versionskonsistenz, keine Composer-Laufzeitpakete) |
| PHPStan Level 5 | 0 Fehler |
| Guard (`tests/compat/requirements-guard.php`) | bestanden unter echtem **PHP 7.4.33** und mit simuliertem WordPress 6.3 |
| Integration (Repo, Entwicklungsinstanz) | 44 Tests, 248 Assertions – grün unter PHP 8.3 und 8.1 |
| Integration (Release-ZIP, frische Instanz) | 44 Tests, 247 Assertions – grün |
| HTTP Release-ZIP (PHP 8.3) | Core 83/999 (23 übersprungen), Yoast 83/995 (24), Konflikt 83/869 (27), Rank Math 83/1006 (22) – grün |
| HTTP Repo (Entwicklungsinstanz, Webserver **PHP 8.1**) | Core 83/999, Yoast 83/994, Konflikt 83/869, Rank Math 83/1006 – grün |
| Upgrade per Release-ZIP | 0.2.0, 0.3.0, 0.4.0, 0.4.1, 0.5.0, 0.6.0 → 0.9.0 mit je 442 synthetischen Objekten: Einstellungen, API-Key, Status, CF7-Mapping, Tracking, Webhook-Token erhalten; Schema 2; `search_price` 0 Abweichungen; eine Tabelle; Cron je Event einmal; keine Dev-Dateien älterer Pakete (115 Dateien) – **OK** |
| Rollback 0.9.0 → 0.6.0 → 0.9.0 | ohne DB-Restore funktionsfähig |
| ZIP-Installation | frisch: Dateien = Manifest, Aktivierung (Schema 2, Rewrite-Regeln, Cron je einmal), Konfiguration, Sync per `wp cron event run --due-now`, `wp psl audit` 0 Verstöße |
| Lebenszyklus | Aktivierung → Deaktivierung (Cron/Rewrite weg, Daten bleiben) → Reaktivierung (12 Karten, Sync) → Deinstallation (Tabelle, Optionen, Transients, Cron weg) |
| PHP 7.4 (echt, WP 7.1.2) | Aktivierung von WordPress verweigert („erfordert PHP 8.1“); bereits aktiv → kein Fatal, keine Klasse geladen, Shortcode leer |
| WordPress 6.3.5 | Upload abgelehnt („erfordert jedoch 6.4“), FTP-Kopie: Aktivierung abgelehnt |
| WordPress 6.4.5 (PHP 8.1) | Installation aus ZIP, Sync 442 Objekte, Liste/Filter (noindex + Header)/Detail (Canonical, Schema, keine Köderdaten)/Sitemap (442 URLs)/301/404, 0 Warnings; Browser-Regression (Twenty Twenty-Four) |
| Browser (Edge headless) | Liste 390–1366 px ohne Überlauf, 4:3, Labels, eindeutige IDs, CLS 0, JS-Submit ohne leere Parameter, Pagination/Zurück, ohne JS funktionsfähig, mobiles Panel per Tastatur |
| Lighthouse (Entwicklungsinstanz, echtes Objekt) | Liste mobil 94 / A11y 98–100 / BP 96 / SEO 91, LCP 2,7 s, CLS 0 (vorher 0,284); Desktop 99, CLS 0. Detail mobil 91 / 100 / 96 / 100, LCP 3,2 s, CLS 0; Desktop 99, LCP 0,7 s. A11y 98 nur mit Standard `heading="h3"` direkt unter der H1 (`heading-order`), mit `heading="h2"` 100 |

**Performance (500 synthetische Objekte):** DB je Abfrage 0,5–6 ms (Liste/Filter/Pagination/Optionen, alle `type=ref`); Voll-Sync 0,9–2,5 s, inkrementell ohne Änderungen 30–70 ms; Seitenzeiten auf dem PHP-Built-in-Server 0,5–1,5 s, gleichauf mit einer WordPress-Referenzseite (Plugin-Anteil im Bereich weniger Millisekunden; Server single-threaded ohne OPcache, nicht repräsentativ). Asset-Größen (roh/gzip): `psl-tracking.js` 8,0/3,2 KB, `psl-gallery.js` 5,7/1,9 KB, `psl-lead-event.js` 3,9/1,7 KB, `psl-list.js` 2,0/1,1 KB, `psl-detail.css` 11,8/3,1 KB, `psl-list.css` 6,4/1,6 KB. 0 Propstack-Requests in allen Frontend-Tests (HTTP-Spy).

**Befunde (behoben):** Liste mobil CLS 0,284 (Panel-Einklappen nach Rendern), CLS 0,10 mit Twenty Twenty-Four (Webfont-abhängiger Umbruch), Page-Cache beim ersten Speichern nicht geleert, `WP_Query::$is_front_page`; Test-Annahmen über die Testinstanz.

**Testumgebungs-Hinweise:** Frische Instanzen brauchen eine WordPress-Seite `/immobilien/` (wie die Live-Site) für die HTTP-Tests. Mehrere Testläufe dürfen nie gleichzeitig dieselbe Datenbank nutzen (Tests leeren `psl_properties`); beim Abbruch eines Laufs auch Kindprozesse beenden.

**Nicht durchgeführt:** Staging (Avada, echtes SEO-Plugin, Cache/Hosting, Apache/nginx), echtes Mobilgerät, reales Consent-Tool/GTM, Propstack-E2E (BLOCKED).

**GitHub Actions** (Commit `38813e8`, Lauf 37739102772): alle Jobs erfolgreich – PHP 8.1/8.2/8.3 (Syntax, Unit, Guard, Release-Build zweimal mit Byte-Vergleich), PHPStan auf 8.3, Guard unter PHP 7.4, JavaScript.

## Ergebnisse Phase 7 (2026-10-07, 0.6.0)

- JavaScript: 20 Tests – grün (neu `list.test.cjs`: 3).
- Unit: 209 Tests, 947 Assertions – grün unter PHP 8.3.2 **und** 8.2.12 (neu `ListingSearchTest`, 69 Tests: gültige/ungültige Filter, Defaults, Normalisierung, Security-Eingaben – SQL-Injection in `city`/`sort`, XSS, negative/riesige Werte, Arrays statt Skalar, `seite[]=1`, riesige `per`, unbekannte/Tracking-Parameter –, feste Shortcode-Einschränkungen, statische Liste, alle Sortierungen mit Tie-Breaker, Platzhalter-SQL, Pagination Seite 1/2/zu hoch/ohne Treffer, Auslassungen, `listingUrl`, `forListing`, `searchPrice`).
- Integration: 39 Tests, 214 Assertions – grün (neu `ListingSearchIntegrationTest`, 19 Tests mit 12 synthetischen Objekten: Kauf/Miete, Wohnung/Haus/Gewerbe/Ferienwohnung, mehrere Orte/Preise/Flächen/Zimmer, reserviert, nicht öffentlich, verkauft, entfernt, Preis auf Anfrage, nur Warmmiete; jeder Filter und Kombinationen liefern exakt die erwarteten IDs; Sortierungen; stabile Seiten bei gleichen Zeitstempeln; Filteroptionen; Shortcode mit GET: Formularzustand, Pagination-Links, Trefferanzahl, Leerzustände, XSS, keine Freischaltung; Adressschutz und Bild-Fallback auf Karten; 0 HTTP-Requests; Migration v1 → v2).
- HTTP je SEO-Modus – grün: Core 83/999 (23 übersprungen), Yoast 83/994 (24), Rank Math 83/1006 (22), Konflikt 83/866 (27). Neu `ListingHttpTest` (10 Tests, läuft in jedem Modus): `/immobilien/` index + Self-Canonical, `?seite=2` index + Self-Canonical + „Seite 2“ im Title, Filter `marketing_type=buy|rent`, `property_type`, `city`, `price_max`, `living_space_min`, `rooms_min`, Kombinationen → exakte Trefferzahl, `noindex, follow` + `X-Robots-Tag`; Pagination mit Filtern; Sortierungen; Leerzustand; Seite hinter der letzten; Trackingparameter ändern weder SEO noch Treffer; 13 feindliche/ungültige Parameter (SQL, XSS, Arrays, riesige Werte, `status`) ohne Wirkung und ohne PHP-Warnings (`debug.log`); 0 Propstack-Requests (HTTP-Spy).
- Befunde während der Tests (behoben): Konflikt-Modus – das nicht integrierte Rank Math gab auf der Übersicht den Seiten-Permalink als zweiten Canonical aus (Sicherheitsnetz erweitert). Yoast gibt auf noindex-Seiten keinen Canonical aus (Yoast-Verhalten, Test akzeptiert „keiner oder Self“).
- Browser (Edge headless, 500 synthetische Objekte): kein horizontaler Überlauf bei 390/768/1024/1366 px, CLS 0, Karten-Bilder exakt 4:3, alle Felder mit Label, eindeutige IDs; mit JS: Absenden ergibt `?marketing_type=rent&price_max=2000` (ohne leere Parameter), „Weiter“ behält Filter, Formularzustand auf Seite 2 korrekt, Browser-Zurück funktioniert; ohne JS: Panel offen, Absenden funktioniert (URL mit leeren Parametern, Server ignoriert sie); mobil mit JS: Panel eingeklappt, per Tastatur (Enter auf „Filter“) zu öffnen, mit aktivem Filter offen; keine JS-Fehler, keine externen Requests (außer den synthetischen Bild-URLs). Screenshots nicht im Repository.
- Lasttest 500 Objekte: alle Abfragen `type=ref`, < 10 ms inkl. Hydration ([listing.md](listing.md#query-architektur-und-indizes)).
- Testinstanz: Migration v1 → v2 lief beim ersten Aufruf automatisch (Spalte ergänzt, Bestandsobjekt nachberechnet).
- **Nicht durchgeführt:** Avada/Live-Site, echte Mobilgeräte, Lighthouse der Übersicht (Bild-URLs synthetisch).

## Ergebnisse Phase 6 (2026-10-06, 0.5.0)

- JavaScript: 17 Tests – grün (Direct, erster UTM-Besuch, zweiter Direct, anderer UTM-Besuch, First bleibt/Last wechselt, gclid/gbraid/wbraid, Referrer Suche/Social/Referral/intern, TTL, ungültige Werte, XSS, keine PII; Consent none/api, Widerruf, Cookie-Attribute).
- Unit: 140 Tests, 593 Assertions – grün (neu `TrackingTest`: Parsing/TTL/Bereinigung, Feldabbildung, Aliase, Mail nur mit Zuordnung, Event ohne PII, Consent-Default).
- Integration: 20 Tests, 93 Assertions – grün.
- HTTP je SEO-Modus – grün: Core 73/597 (23 übersprungen), Yoast 73/592 (24), Rank Math 73/604 (22), Konflikt 73/464 (27). Neu `TrackingHttpTest` (10 Tests): Standard aus, Provider `none` ignoriert Marketingdaten, Skripte seitenweit bzw. nur mit Formular, Propstack-Zuordnung aktiv/inaktiv (Mail-Capture), Manipulation/XSS, Event-Daten nur bei `mail_sent` (nicht bei Validierung, Spam, manipulierter ID, Mailfehler, Rate-Limit, anderem Formular), dataLayer aus, neue Lead-ID bei Wiederverwendung, keine Klick-IDs/Attribution in Logs.
- Browser (Edge): ohne Consent kein Cookie/0 Events/Anfrage ok; mit Consent Google Ads → Facebook → Direct ⇒ First google, Last facebook; Cookie 90 Tage, SameSite=Lax, Pfad `/Picaflor/`, 459 B; Validierungsfehler 0, Erfolg genau 1 Event, 2× DOM-Event 0, gefälschtes Event 0, Reload 0, Spam 0, Mailfehler 0, Rate-Limit 0; Widerruf löscht Cookie; dataLayer ohne PII/Klick-ID; keine externen Requests; keine JS-Fehler; Detailseiten-Regression (Layout 390–1366 px, Lightbox, Anker, ohne JS) unverändert.
- Skriptgrößen: `psl-tracking.js` 8,0 KB roh / 3,2 KB gzip, `psl-lead-event.js` 3,9 KB / 1,7 KB.
- **Nicht durchgeführt:** Staging-Smoke-Test auf DomainFactory (kein Zugang), reales Consent-Tool, GTM-Vorschau, Propstack-E2E (BLOCKED).

## Ergebnisse Release-Candidate-Abnahme (2026-10-06, 0.4.1)

Bericht: [acceptance-rc-2026-10-06.md](acceptance-rc-2026-10-06.md).

- Unit: 130 Tests, 547 Assertions – grün (auch unter PHP 8.2.12).
- Integration: 20 Tests, 93 Assertions – grün (neu: `LegacyMigrationTest`, `PageCachePurgerTest`).
- HTTP je Modus – grün: Core 63 Tests/489 Assertions (23 übersprungen), Yoast 63/484 (24), Rank Math 63/496 (22), Konflikt 63/356 (27). Neu: ungültige IDs → 404, Rank-Math-Breadcrumb-Pfad, Breadcrumb-Einträge mit URL, Yoast ohne Website-Standardbild auf Objekten ohne Foto.
- Zusätzlich manuell/skriptgesteuert: frische Installation über die Admin-Oberfläche, Aktivierung/Deaktivierung/Reaktivierung/Deinstallation, Upgrade 0.2.0 und 0.3.0 → 0.4.1, Sync-Fehlerfälle (Ausfall, 401, 429, 503, kaputtes JSON, Lock), Lebenszyklus über die Fake-API, 53 synthetische Objekte + 500 Lastzeilen, Browser (Edge) 390/768/1024/1366 px, Lightbox mit 24 Bildern, ohne JS, CF7 im Browser, Lighthouse, WP Super Cache, Security-Smoke.
- Werkzeuge (nur Testinstanz, nicht im Repository): Fake-Propstack-API als mu-plugin (Option `rc_fake_api`, Daten aus `wp-content/rc-fake-units.json`), Generator für synthetische Objekte, Puppeteer-Skripte.

## Ergebnisse Phase 5 (2026-10-06)

- Unit: 130 Tests, 543 Assertions – grün (neu: `SeoServiceTest` – Title inkl. Beispiel der Vorgabe, Kürzung ohne Wortschnitt, Objektart/Vermarktung, keine Straße; Description mit Bausteinen, ≤ 160 Zeichen UTF-8, ganze Sätze, kein HTML, nie „0 €“; Robots je Zustand und 410; Canonical/`og:url`; OG/Twitter mit/ohne Bild, keine privaten/Grundriss-Bilder; JSON-LD Kauf/Miete, nie `price: 0`, verborgene Adresse ohne Straße/Geo, `Place`-Fallback, Agentur nur aus Site-Daten, BreadcrumbList; Klartext-Bereinigung).
- Integration: 16 Tests, 66 Assertions – grün.
- HTTP (Suite je Modus vollständig ausgeführt, Skips = Klassen der anderen Modi):
  - Core: 62 Tests, 484 Assertions, 22 übersprungen – grün
  - Yoast SEO 28.6: 62 Tests, 479 Assertions, 23 übersprungen – grün
  - Rank Math 1.0.279: 62 Tests, 486 Assertions, 22 übersprungen – grün
  - Konflikt (beide aktiv): 62 Tests, 354 Assertions, 26 übersprungen – grün
- Geprüft je Modus: genau 1 Title/Description/Canonical/Robots/OG-Set/`twitter:card`/JSON-LD-Block; Title/Description/Canonical/`og:url`/`og:image`/`og:locale`; H1 = Propstack-Titel; reserviert `index`, Verkauft-Phase `noindex, follow` (+ Header), 410 ohne Canonical/Listing/Weiterleitung, 404 ohne Canonical, Legacy 301; Canonical unverändert mit `utm_*`/`gclid`/`fbclid`; Preis auf Anfrage ohne `Offer` und ohne Bild ohne `og:image`; Schema-Validierung (genau ein `RealEstateListing`, `Offer` mit Preis > 0 und EUR, eindeutige `@id`s, aufgelöste Referenzen, Adresse ohne Straße, eine BreadcrumbList); XSS (Script, Attribut-Ausbruch, `onerror`-URL, Anführungszeichen direkt im Store) nicht ausführbar, JSON-LD gültig; Sitemap-Index und -Inhalt (nur aktive/reservierte öffentliche Objekte, URL = Canonical inkl. `/Picaflor/`, lastmod), Paginierung (Core, Yoast, Rank Math); 0 Propstack-Requests bei Detail-, 410- und Sitemap-Aufrufen.
- Zusätzlich: Rank Math ohne Registrierung → Plugin fällt in den Core-Modus; Konflikt → nur Yoast erhält Werte, Admin-Hinweis „Mehrere SEO-Plugins aktiv. Für Propstack-Detailseiten wird nur Yoast SEO integriert.“, noindex-Sicherheitsnetz für Rank Math.
- Befunde während der Tests (behoben): Yoast leitet `…-sitemap1.xml` auf `…-sitemap.xml` um (Seite 1 jetzt ohne Nummer); Rank Math cacht Sitemaps (Invalidierung nach Sync/Einstellungsänderung); Rank Math ohne Registrierung gibt nichts aus (Erkennung angepasst); Konflikt: Rank Math meldete auf noindex-Seiten „index“ (Sicherheitsnetz).
- **Nicht durchgeführt:** Google Rich Results Test / Schema.org-Validator online (keine öffentliche URL); Tests mit der Live-Site (Avada) und deren SEO-Plugin-Konfiguration.
- Propstack-E2E-Test (Anfragen): nicht gesendet, Voraussetzungen nicht verifizierbar ([leads.md](leads.md)).

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
