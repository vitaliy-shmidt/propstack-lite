# Release-Candidate-Abnahme – 2026-10-06

Stand: `main` nach Phase 5 (`a79c007`) + Fixes dieser Abnahme (Version **0.4.1**). Keine Phase-6-Funktionalität.

## Ergebnis: **PASS WITH OPEN ITEMS**

Der Kernablauf funktioniert end-to-end in WordPress-Testinstanzen. Offen sind ausschließlich Punkte, die eine reale Staging-Umgebung bzw. externe Informationen benötigen (Avada, Propstack-Postfach, Mailtransport, reale SEO-/Cache-Konfiguration).

## Testumgebungen

| Umgebung | Inhalt |
|---|---|
| Frische Instanz | WordPress 7.1.2 de_DE, neue DB (Präfix `rc_`), Unterverzeichnis `/rc-site/`, Plugin aus `git archive main`, CF7 6.1.7; PHP 8.3.2 und **PHP 8.2.12** |
| Upgrade-Instanz | eigene DB; 0.2.0 (Baseline `57c45e8`) bzw. 0.3.0 (`927884c`) → 0.4.1 per Dateiersatz/ZIP („Version ersetzen“) |
| Entwicklerinstanz | WordPress 7.1.2 in `/Picaflor/`, Twenty Twenty-One, CF7 6.1.7 (+ de_DE-Sprachpaket), Yoast SEO 28.6, Rank Math 1.0.279 (Assistent abgeschlossen, Knowledge Graph, Standard-OG-Bild, Breadcrumbs), WP Super Cache 3.1.4 |
| Daten | echtes Propstack-Konto nur lesend (1 öffentliches Objekt); 55 synthetische, anonymisierte Objekte über eine Fake-API **durch den echten SyncService**; 500 Lastzeilen |
| Browser | Microsoft Edge (headless, Puppeteer), Lighthouse |

**Nicht verfügbar / nicht getestet:** Avada, echtes Mobilgerät, PHP 8.1, Staging-Mailserver (SMTP), Propstack-CRM-Sicht, öffentliche URL (Rich-Results-Test).

## Core Flow

| Schritt | Ergebnis | Nachweis |
|---|---|---|
| Sync | **PASS** | Admin-Button, WP-CLI (voll/inkrementell/einzeln), Cron-Events, `updated_at_from` mit 24 h Überlappung; Lock; Ausfall, 401 (echter falscher Key), 429, 503, kaputtes JSON → Bestand bleibt, Frontend 200, Fehler ohne Key im Log |
| Übersicht | **PASS** | Karten mit Bild/Platzhalter, Kauf-/Mietpreis, „auf Anfrage“, Ort, Objektart, Fläche, Zimmer, Badge „Reserviert“, Links; `hide_address` ohne Straße |
| Detail | **PASS** | 12 Varianten (Kauf, Miete, Haus, ohne Preis, ohne Bilder, 24 Bilder, Grundrisse, Adresse verborgen, ohne Makler, viele Merkmale, lange Texte, Büro) |
| Kontakt (CF7) | **PASS** | echtes CF7 im Browser: Validierung, deutsche Sie-Meldungen, `mail_sent`, Mail `ps-kontaktanfrage` mit Reply-To, Objekt-ID, URL, nur Kontakterlaubnis |
| Propstack-Lead | **BLOCKED** | siehe unten |
| SEO | **PASS** | Core, Yoast, Rank Math, Konflikt (je HTTP-Suite + Quelltextsichtung) |

## Installation, Aktivierung, Upgrade

- Frische Installation über die Admin-Oberfläche (Login, Formular mit Nonce): API-Key, Status „Website Picaflor“ aus der geladenen Statusliste, „Jetzt synchronisieren“ → Objekt sichtbar. Tabelle mit Indizes, Schema-Option, 3 Cron-Events, Rewrite-Regeln ohne manuelles Speichern der Permalinks; **0 PHP-Meldungen**.
- Deaktivierung: Cron und Regeln entfernt (Detail-URL 404), Daten und Einstellungen bleiben. Reaktivierung: alles wieder da, Legacy 301. Deinstallation: Tabelle, Optionen, Transients, Cron entfernt.
- Upgrade 0.3.0 → 0.4.1: Einstellungen und Store byte-identisch, eine Tabelle.
- Upgrade 0.2.0 → 0.4.1: **Bug gefunden und behoben (P1)** – siehe unten. Nach Fix: Status migriert, Key und Webhook-Token erhalten, Altlasten entfernt, Voll-Sync zeitnah, Legacy-URL 301.

## Status und Lebenszyklus (über den echten Sync)

| Fall | HTTP | Robots | Übersicht | Sitemap | Formular |
|---|---|---|---|---|---|
| öffentlich | 200 | index | ja | ja | ja |
| reserviert + öffentlich | 200 | index, Badge | ja | ja | ja |
| verkauft (Statuswechsel, inkrementeller Sync) | 200 | noindex (Meta + Header), Badge | nein | **sofort** nein | nein |
| verkauft > 30 Tage | 410 | noindex, kein Canonical | nein | nein | – |
| archiviert / Status nicht mehr öffentlich | 410 | noindex | nein | nein | – |
| nie öffentlich | 404 | – | nein | nein | – |

Statusnamen sind nicht fest verdrahtet (nur IDs aus den Einstellungen).

## HTTP / Routing / Canonical

200, 301 (falscher Slug, nur ID, ohne Slash, Großschreibung, Legacy `/immobilie/{slug}-{id}/`, `/immobilie/{id}/`, `?ps_id=` inkl. Kampagnenparameter), 404 (unbekannt, ungültig), 410 – real per HTTP. Canonical und `og:url` bleiben bei `?utm_source=test`, `?utm_campaign=test`, `?gclid=test123`, `fbclid` unverändert. Keine Soft-404 (nach Fix für ID 0 / ungültigen Query-Parameter).

## Browser / Responsive

- 390 / 768 / 1024 / 1366 px: Übersicht und 4 Detailvarianten ohne horizontalen Scroll – **nach Fix** (vorher Überlauf bei 1024 px durch „Dachgeschosswohnung“).
- Galerie: 24 Bilder, „Alle 24 Bilder ansehen“, Lightbox Pfeiltasten inkl. Umlauf, Fokusfalle, ESC mit Fokus-Rückgabe, Touch-Swipe beide Richtungen, Grundrisse als eigene Gruppe, private/`is_not_for_exposee`-Bilder nicht im DOM.
- Ohne JavaScript: Bildlinks auf HTTPS-Großbilder, Formular vorhanden, keine offene Lightbox.
- `#psl-contact`: CTA springt, Fokus auf Kontaktbereich, Abstand oben 24 px (`--psl-scroll-offset`).
- Konsole: keine Plugin-Fehler (nur fehlendes `favicon.ico` der Testumgebung, fiktive Avatar-URL der synthetischen Daten); kein Mixed Content.

## Avada: **NOT TESTED**

Avada ist lokal nicht verfügbar. Header/Footer, Page Title Bar, Sidebar, fixierter Header, Buttons, Typografie, Avada-Lightbox-Konflikte und responsives Verhalten sind **nicht verifiziert**. Statisch geprüft: Plugin-CSS ist vollständig gescoped (`.psl-*`/`[data-psl*]`; einzige globale Regel `html.psl-lightbox-open` nur bei offener Lightbox). Datenlogik und Routing laufen theme-unabhängig (Twenty Twenty-One, Twenty Twenty-Five).

## CF7: **PASS**

Pflichtfelder, ungültige E-Mail, fehlende Zustimmung (Button bis zur Zustimmung gesperrt), Honeypot, Rate-Limit, Fehlermeldung, fremdes Formular unverändert (HTTP-Suite), erfolgreiche Anfrage im Browser. **Formularmeldungen müssen auf der Live-Site gepflegt werden** – CF7 hatte im Testformular englische Texte; das Sprachpaket `de_DE` duzt. Empfohlene Sie-Texte: [leads.md](leads.md).

## SEO

| Modus | Ergebnis |
|---|---|
| Core | je 1 Title/Description/Canonical/Robots/OG-Set/Twitter/JSON-LD im Quelltext; Sitemap `wp-sitemap-propstack-N.xml` |
| Yoast 28.6 (konfiguriert) | keine Dubletten; Werte korrekt; **Fix:** kein Website-Standardbild auf Objekten ohne Foto; Sitemap `propstack-sitemap.xml` |
| Rank Math 1.0.279 (voll konfiguriert) | keine Dubletten; Standard-OG-Bild nicht zusätzlich; **Fix:** BreadcrumbList mit vollständigem Pfad statt nur „Home“ |
| Konflikt | nur Yoast integriert, Admin-Hinweis, noindex-Sicherheitsnetz |
| Schema | **Fix:** alle Breadcrumb-Einträge mit URL; kein `price: 0`; verborgene Adresse ohne Straße/Geo |
| Sitemap | nur indexierbare Objekte, reserviert enthalten, verkauft/410 nicht, absolute URL mit Unterverzeichnis, Slug, `lastmod`, Paginierung (546 Objekte: 200/200/146) |

## Security: **PASS**

XSS (Mapper und direkt im Store) in HTML, Meta, JSON-LD nicht ausführbar; manipulierte/ungültige Property-IDs abgelehnt; URL-Manipulation → 301/404; CF7-Header-Injection, Rate-Limit, Honeypot (HTTP-Suite); Webhook: ohne/falscher Token 401, GET 404, korrekt 202 ohne API-Request im Request; Admin: anonym → Login bzw. 400, Abonnent → 403 für Einstellungsseite, `admin-post`-Aktionen und `options.php`; Einstellungen unverändert.

## Privacy: **PASS**

Komplette Quelltexte von 12 Detailvarianten und der Übersicht durchsucht (HTML, Meta, JSON-LD, `data-*`, Skripte, CF7-Hidden-Fields): keine verborgene Straße/Hausnummer/Koordinaten, keine internen Notizen, Broker-Interna, Tokens, CRM-/Objekt-IDs, privaten oder Exposé-ausgeschlossenen Bilder, keine Exposé-URL. Plugin-eigene CF7-Hidden-Fields: nur `psl_property_id` und `psl_lead_id` (dazu die CF7-Standardfelder `_wpcf7*`). Mail enthält nur Formulardaten, Objekt-ID, Titel, URL.

## Performance

| Seite | Performance | A11y | Best Practices | SEO | LCP | CLS | TBT |
|---|---|---|---|---|---|---|---|
| Detail mobil | 89 | 100 | 96* | 100 | 3,3 s | 0 | 0 ms |
| Detail Desktop | 99 | 100 | 96* | 100 | 0,7 s | 0 | 0 ms |
| Übersicht mobil | 93 (vorher 91) | 98** | 96* | 91*** | 2,9 s | 0 | 0 ms |
| Übersicht Desktop | 100 | 98** | 96* | 91*** | 0,7 s | 0 | 0 ms |

\* nur 404 auf `favicon.ico` der Testumgebung · \** Kartentitel `h3` direkt unter `h1` → auf der Live-Seite `[propstack_list heading="h2"]` verwenden · \*** fehlende Meta-Description der WordPress-Seite `/immobilien/` (Sache des SEO-Plugins/Seite). Lokaler PHP-Testserver ohne OPcache: TTFB 0,4–0,6 s.

- **0 Propstack-Requests** bei Besucher-Requests (Detail, Übersicht, 410, Sitemap, Webhook) in allen Modi.
- DB mit 546 Objekten: `queryPublic` ~3 ms (Index `price_idx`), `countSitemap` 1 ms, `sitemapRows(2000)` 1,7 ms, `find` 0,2 ms.
- Bilder: Hauptbild `fetchpriority="high"`, nicht lazy; Vorschaubilder lazy mit Maßen; CLS 0.

## Full-Page-Cache (WP Super Cache 3.1.4)

Detailseiten werden von WP Super Cache standardmäßig nicht gecacht (unbekannter Seitentyp → Statuswechsel sofort sichtbar). Übersicht und Sitemap werden gecacht: **verkauftes Objekt blieb in der gecachten Sitemap** → behoben (Cache-Leerung nach Sync mit Änderungen). Andere Cache-Plugins (LiteSpeed, WP Rocket, W3TC) über deren offizielle Funktionen angebunden, **aber nicht getestet**.

## PHP / Debug

- PHP 8.3.2: alle Suiten. PHP 8.2.12: Unit-Suite, Lint, frische Instanz (Sync, Übersicht, Detail, Routing, Sitemap, Deinstallation) mit `error_reporting=-1` – 0 Meldungen. **PHP 8.1: nicht getestet** (nicht installiert).
- `WP_DEBUG`/`WP_DEBUG_LOG` während aller Tests: plugin-bezogene Warnings/Notices/Deprecated/Fatal im Laufzeitbetrieb: **0**.

## Propstack-E2E: **BLOCKED**

Keine Mail gesendet. Lesend erneut geprüft: `contacts`, `contact_sources`, `activity_types`, `hooks`, `brokers` → 401; Propstack-Adresse in den Einstellungen ist ein Platzhalter; kein Staging-Mailtransport. **Fehlende externe Informationen:** Adresse des mit Propstack verbundenen Anfrage-Postfachs; Bestätigung „Neue Portalanfrage“ aktiv; gewünschte Kontaktquelle; Staging mit SMTP; Prüfzugang in Propstack (UI oder API-Key mit Leserechten auf Kontakte).

## Gefundene und behobene Bugs (0.4.1)

| Prio | Befund | Status |
|---|---|---|
| P1 | Update 0.2.0 → neu per ZIP/FTP: Settings nicht migriert → nichts öffentlich | behoben (Migration beim Start) |
| P2 | Sitemap/Übersicht im Full-Page-Cache veraltet nach Verkauf | behoben (`PageCachePurger`) |
| P2 | Horizontaler Scroll bei 1024/390 px durch lange Komposita | behoben (CSS) |
| P2 | BreadcrumbList-Eintrag ohne URL (Rich-Results-Fehler) | behoben |
| P2 | Rank Math: BreadcrumbList nur „Home“ | behoben (`rank_math/frontend/breadcrumb/items`) |
| P3 | Yoast: Website-Standardbild als `og:image` bei Objekten ohne Foto | behoben |
| P3 | Soft-404 bei `/immobilien/x-0/`, `?psl_property=abc` | behoben (404) |
| P3 | LCP-Bild der Übersicht lazy | behoben (erste Reihe eager) |

Nicht behoben (bewusst, kein Code-Fehler bzw. außerhalb des Plugins):

| Prio | Befund |
|---|---|
| P2 | CF7-Formularmeldungen englisch bzw. Du-Form → Konfiguration auf der Live-Site (Texte in leads.md) |
| P3 | Übersicht ohne Blätterfunktion (Phase 7) – `per` ausreichend hoch setzen |
| P3 | Slug enthält Zimmerzahl auch bei Gewerbe („4-5-zimmer-buero-…“); Änderung würde 301-Ketten erzeugen |
| P3 | Rank Math/Yoast-Organisation und `RealEstateAgent` des Plugins sind zwei Knoten für dasselbe Unternehmen |
| P3 | Release-Paket: `git archive` enthält `tests/`, `composer.*`, `phpunit.xml.dist` (harmlos, aber ein Build-/Export-Schritt fehlt) |

## Offene Punkte vor Staging/Produktion

1. Staging mit **Avada**, echtem SEO-Plugin und dessen Konfiguration, Cache-/Hosting-Setup der Live-Site; Abnahme Header/Footer, fixierter Header (`--psl-scroll-offset`), Lightbox-Konflikte, Typografie, Buttons, mobil auf echtem Gerät.
2. **CF7-Formular** auf Staging anlegen: Felder laut leads.md, deutsche Sie-Meldungen, Absender der eigenen Domain, Body `[_psl_propstack_block]`, Reply-To.
3. **Mailtransport** (SMTP/Transaktionsdienst) mit SPF/DKIM/DMARC.
4. **Propstack**: Postfachadresse, Automatisierung „Neue Portalanfrage“, Kontaktquelle → danach genau ein synthetischer E2E-Lead.
5. System-Cron statt WP-Cron; Webhook-Token setzen und Webhook durch Propstack-Admin registrieren (optional).
6. Einstellungen: öffentliche/verkaufte/reservierte Status-IDs fachlich setzen.
7. Update der Live-Site von 0.2.0: Backup, dann ZIP-Upload (Migration ist jetzt abgesichert); danach `wp psl status` / Einstellungsseite prüfen.
8. Übersichtsseite: `[propstack_list per="…" heading="h2"]`, Meta-Description über das SEO-Plugin.
9. PHP-Version des Hostings prüfen (getestet: 8.2, 8.3; 8.1 nicht).

## Empfehlung

- **Bereit für Phase 6 Tracking?** Ja, technisch. Der Lead-Ablauf (Lead-ID, `wpcf7mailsent`, Formular nur bei anfragbaren Objekten) ist stabil und Tracking setzt darauf auf. Die Messung lässt sich aber erst mit dem Propstack-E2E-Test und dem Consent-Setup der Live-Site endgültig abnehmen.
- **Staging-Test mit Picaflor geeignet?** Ja. Der Stand ist für einen Staging-Test geeignet. Er dient vor allem der Avada-Abnahme, der CF7-/Mail-Einrichtung und dem Propstack-E2E-Lead.
