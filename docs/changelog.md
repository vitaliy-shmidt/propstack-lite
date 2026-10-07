# Changelog (Entwicklungsfortschritt)

## 2026-10-07 – Phase 7: Immobiliensuche, Filter, Pagination, Sortierung (Version 0.6.0)

Dokumentation: [listing.md](listing.md).

**Wichtigste Änderungen**
- `[propstack_list]` wird zur Immobiliensuche: GET-Formular (ohne JS nutzbar) mit Kaufen/Mieten, Objektart-Gruppen (`FieldCatalog::TYPE_GROUPS`), Ort (aus öffentlichen Objekten), Preis von/bis (Kaufpreis bzw. Kaltmiete), Wohnfläche ab, Grundstücksfläche ab, Zimmer ab; Sortierung (Neueste, Preis ↑↓, Wohnfläche ↑↓, Zimmer, Zuletzt aktualisiert); Pagination (12/24/48, Parameter `seite`); Trefferanzahl, Leerzustand, „Filter zurücksetzen“; mobiles Einklappen per `<details>`.
- Neue Query-API: `Storage\PropertySearchCriteria`, `SearchQueryBuilder`, `PropertySearchResult`, `PropertyStore::search()`/`filterOptions()`; `queryPublic()`/`ListCriteria` bleiben als kompatible Hülle.
- Neue Frontend-Klassen: `ListingConfig`, `ListingRequest` (zentrale Parameter-Whitelist), `ListingService`, `ListingContext`, `Pagination`; Templates `parts/list-filters.php`, `parts/list-pagination.php`; überarbeitete `list.php`, `parts/card.php`, `psl-list.css`; neues `assets/js/psl-list.js`.
- SEO der Übersicht: `SeoService::forListing()`, `Seo\ListingSeoData`; Self-Canonical (auch Seite n), Filter/Sortierung/ungültige Seite `noindex, follow` + `X-Robots-Tag`, Seitenzusatz im Title; Core-/Yoast-/Rank-Math-Adapter, Konflikt-Sicherheitsnetz für Canonical. Das Plugin stuft Robots nur herab.
- Neue Shortcode-Attribute `show_filters`, `show_sort`, `pagination`, `sort`, `property_type`; Admin-Hilfe aktualisiert.

**Bugs behoben:** Karten zeigten bei Mietobjekten mit „Preis auf Anfrage“ die Kaltmiete (jetzt „Miete: auf Anfrage“ wie auf der Detailseite).

**DB-Migration:** Schema v2 – Spalte `search_price` (Backfill aus gespeichertem JSON), Index `price_idx` entfernt; läuft bei Aktivierung und beim Plugin-Start nach Update ([database.md](database.md#migrationen)).

**Breaking Changes:** keine API-Brüche. Verhaltensänderungen: bestehende `[propstack_list]`-Einbindungen zeigen nun Formular und Pagination (statisch mit `show_filters="0" show_sort="0" pagination="0"`); Shortcode-Preisgrenzen und Preissortierung nutzen `search_price` (Preis auf Anfrage/Warmmiete-only fallen bei Preisfiltern heraus); Kartentext CTA „Details ansehen“; Kartenbild 600 × 450.

**Abweichungen von der Vorgabe (begründet):** Seitenparameter `seite` statt `page` (WordPress leitet `?page=2` per 301 um); Standardsortierung `remote_created_at` statt `content_changed_at` (stabil, „Zuletzt aktualisiert“ als Option); Kartenbild ohne `srcset` (keine Propstack-Zwischengröße, `big` = 1920 px).

**Offene Punkte:** Avada-Darstellung des Formulars und der Pagination, Anpassung der Shortcodes auf der Live-Site, echte Geräte, Lighthouse mit echten Bildern.

## 2026-10-06 – Phase 6: Attribution und Conversion-Tracking (Version 0.5.0)

**Staging-Smoke-Test:** nicht durch Claude durchgeführt – für die DomainFactory-Instanz lagen weder URL noch Zugang vor. Laut Rückmeldung laufen dort Sync, Übersicht, Detailseite (Avada) und Formular. Checkliste in [avada.md](avada.md); keine Avada-spezifischen Fixes.

**Wichtigste Änderungen**
- Neue Schicht `src/Tracking/`: `TrackingIntegration`, `Touch`, `Attribution`, `AttributionStorage`, `LeadEvent`, `Consent\ConsentProviderInterface`, `Consent\NoConsentProvider` (Standard), `Consent\JsApiConsentProvider`, `Consent\ConsentProviders` (Filter `psl_consent_providers`).
- `assets/js/psl-tracking.js` (seitenweit, nur bei aktivem Tracking und Consent-Provider): First Touch + Last Non-Direct im First-Party-Cookie `psl_attr` (90 Tage, nur mit Marketing-Consent), API `PSLTracking.setConsent/hasConsent/attribution`.
- `assets/js/psl-lead-event.js` (nur Detailseiten mit Formular): Attribution ins Hidden Field `psl_attr` nur bei aktuellem Consent; `property_lead` in den `dataLayer` nur nach `wpcf7mailsent` mit serverseitig bestätigten Daten, einmal pro Lead-ID.
- Server: Filter `psl_lead_attribution` (Cf7Integration) → validierte Attribution für `client_cf_*`; `wpcf7_feedback_response` → `psl_lead` (Lead-ID, Objekt-ID, Vermarktungsart, Objektart, Ort) nur bei `mail_sent` des konfigurierten Formulars.
- Einstellungen „Tracking & Attribution“: Attribution, dataLayer-Event, Consent-Provider, Speicherdauer, Propstack-Custom-Fields (neue Schlüssel `first_utm_*`, `last_utm_*`, `utm_content`, `utm_term`, `gclid`, `gbraid`, `wbraid`, `landing_path`, `referrer_host`). Alle Standardwerte: aus/`none`.
- `LeadContext::withAttribution()`.

**Bugs behoben:** keine (während Phase 6 keine Fehler im Bestand gefunden).

**Neue Dateien:** `src/Tracking/*.php` (5), `src/Tracking/Consent/*.php` (4), `assets/js/psl-tracking.js`, `assets/js/psl-lead-event.js`, `tests/js/tracking.test.cjs`, `tests/Unit/TrackingTest.php`, `tests/Http/TrackingHttpTest.php`.

**DB-Migrationen:** keine (neue Einstellungsschlüssel mit sicheren Defaults).

**Breaking Changes:** keine. Propstack-Zuordnungen `utm_source/utm_medium/utm_campaign` (0.3–0.4) gelten als `last_utm_*`. Die Feldzuordnung ist im Admin vom Bereich „Immobilienanfragen“ nach „Tracking & Attribution“ umgezogen.

**Offene Punkte:** Consent-Tool der Website festlegen und per JS-API anbinden (nicht getestet), GTM-Konfiguration, Staging-Smoke-Test inkl. Avada, Propstack-E2E (BLOCKED).

## 2026-10-06 – Release-Candidate-Abnahme, Fixes (Version 0.4.1)

Abnahmebericht: [acceptance-rc-2026-10-06.md](acceptance-rc-2026-10-06.md). Keine Phase-6-Funktionalität.

**Bugs behoben**
- **P1 Upgrade aus 0.2.x:** Bei Update per ZIP-Upload/FTP lief die Settings-Migration nicht (nur Aktivierungs-Hook) → nach dem Update wäre kein Objekt öffentlich gewesen. Jetzt `Settings::maybeMigrateLegacy()` beim Plugin-Start (idempotent) + zeitnaher Voll-Sync.
- **P2 Full-Page-Cache:** Verkaufte/entfernte Objekte blieben bis zum Cache-Ablauf in Sitemap (nachgewiesen mit WP Super Cache) bzw. bei Cache-Plugins, die alle URLs cachen, auf Detailseiten mit Formular und `index`. Neu `Support\PageCachePurger` (nach Sync mit Bestandsänderungen und nach Einstellungsänderungen; Action `psl_purge_page_cache`).
- **P2 Layout:** Lange deutsche Komposita in Kurzfakten/Eckdaten (z. B. „Dachgeschosswohnung“) erzeugten horizontalen Scroll bei 1024 px (55 px) und 390 px (8 px).
- **P2 Schema:** BreadcrumbList enthielt einen Eintrag ohne URL (Ort) – Rich-Results-Fehler. Jetzt Startseite → Immobilien → Objekt, alle mit URL.
- **P2 Rank Math:** Mit aktivierten Rank-Math-Breadcrumbs bestand dessen BreadcrumbList nur aus „Home“ → Pfad über `rank_math/frontend/breadcrumb/items`.
- **P3 Yoast:** Objekte ohne Foto bekamen Yoasts Website-Standardbild als `og:image` → OG-Bilder werden in der Presentation exakt gesetzt.
- **P3 Soft-404:** `/immobilien/x-0/` und `?psl_property=abc` lieferten die Startseite mit 200 → jetzt 404.
- **P3 Performance:** Erste Kartenreihe der Übersicht nicht mehr `loading="lazy"` (LCP-Bild); Lighthouse mobil 91 → 93.

**Neue Dateien:** `src/Support/PageCachePurger.php`, `tests/Integration/LegacyMigrationTest.php`, `tests/Integration/PageCachePurgerTest.php`, `docs/acceptance-rc-2026-10-06.md`.

**DB-Migrationen:** keine Schemaänderung.

**Breaking Changes:** keine. Template `parts/card.php` erhält zusätzlich `index`; Theme-Overrides ohne diese Variable funktionieren unverändert (Bilder dann `lazy`).

## 2026-10-06 – Phase 5: SEO und Sitemaps für Detailseiten (Version 0.4.0)

**Propstack-E2E-Test (Anfragen):** geprüft, **nicht gesendet** – Propstack-Postfachadresse unbekannt, kein Lese-/Prüfzugriff auf Kontakte/Quellen/Automatisierungen (Key: 401), kein Mailtransport in der Testinstanz. Protokoll in [leads.md](leads.md).

**Wichtigste Änderungen**
- Neue SEO-Schicht `src/Seo/`: `SeoService` (zentrale Logik ohne WordPress), `SeoData`, `SeoContext`, `SeoPlugins`, `SeoIntegration`, Adapter `CoreAdapter`, `YoastAdapter`, `RankMathAdapter`; Sitemaps `Sitemap\SitemapSource`, `CoreSitemapProvider`, `YoastSitemapProvider`, `RankMathSitemapProvider`, `SitemapCache`.
- Title „{n}-Zimmer-{Objektart} {kaufen|mieten} in {Ort}-{Ortsteil} | {Marke}“ (max. 70 Zeichen, Kürzung ohne Wortschnitt), Meta Description (≤ 158 Zeichen, ganze Sätze), Canonical = UrlGenerator-URL, Robots je Zustand, Open Graph/Twitter mit erstem öffentlichen Nicht-Grundriss-Bild, JSON-LD `RealEstateListing` + `Offer` (nie `price: 0`) + `Apartment`/`House`/`Place` + `RealEstateAgent` (nur Website-Daten) + `BreadcrumbList`.
- Ausgabe: ohne SEO-Plugin selbst (je Tag genau einmal), mit Yoast SEO bzw. Rank Math ausschließlich über deren offizielle Filter; Priorität Yoast > Rank Math > Core; Admin-Hinweis und Status-Box „SEO“ bei mehreren SEO-Plugins; noindex-Sicherheitsnetz für das nicht integrierte Rank Math.
- XML-Sitemaps für WordPress-Core (`wp-sitemap-propstack-N.xml`), Yoast und Rank Math (`propstack-sitemap.xml`): nur indexierbare Objekte, URL = Canonical, `lastmod = content_changed_at`, paginiert; Cache-Invalidierung nach Sync (`psl_sync_finished`) und Einstellungsänderung.
- `DetailController` gibt keine Head-Tags mehr aus (Title, Canonical, Robots-Meta → SEO-Schicht); `X-Robots-Tag`, Statuscodes und 301 unverändert.
- Neue Hooks: Filter `psl_seo_brand`, `psl_seo_data`; Action `psl_sync_finished`.

**Bugs behoben / Befunde:** Yoast leitet `…-sitemap1.xml` um → Seite 1 ohne Nummer; Rank Math ohne Registrierung lädt kein Frontend → Plugin bleibt dann im Core-Modus; Rank-Math-Sitemap-Cache → Invalidierung; Konfliktmodus: Rank Math meldete auf noindex-Seiten „index“ → Sicherheitsnetz.

**Neue Dateien:** `src/Seo/*.php` (8), `src/Seo/Sitemap/*.php` (5), `tests/Unit/SeoServiceTest.php`, `tests/Http/SeoHttpTestCase.php`, `SeoCoreTest.php`, `SeoYoastTest.php`, `SeoRankMathTest.php`, `SeoConflictTest.php`, `docs/seo.md`; erweitert: `PropertyStore` (Sitemap-Abfragen), `SyncService` (Action), `Notices`, `SettingsPage`, `psl-test-hooks.php`.

**DB-Migrationen:** keine.

**Breaking Changes:** Der Dokumenttitel der Detailseiten ist jetzt der SEO-Title (inkl. Marke) statt „Objekttitel – Website“; die H1 bleibt der Propstack-Titel. Wer `document_title_parts` für Detailseiten genutzt hat, muss auf `psl_seo_data` umstellen.

**Offene Punkte:** Propstack-E2E-Test der Anfragen; Verifikation mit Live-Site (Avada) und deren SEO-Plugin-Konfiguration; Rich-Results-Test mit öffentlicher URL; andere SEO-Plugins (AIOSEO, SEOPress) nicht erkannt.

## 2026-10-05 – Phase 4: Immobilienanfragen über Contact Form 7 → Propstack

**Wichtigste Änderungen**
- Neue Lead-Schicht `src/Leads/`: `Cf7Integration`, `LeadContextFactory`, `LeadContext`, `LeadData`, `InquiryMailFormatter`, `RateLimiter`, `LeadSink` + `Cf7MailLeadSink`, `LeadSetupCheck`, `LeadException`.
- CF7-Formular auf anfragbaren Detailseiten (aktiv/reserviert) über den Hook `psl_property_contact`; nur geladen, wenn CF7 aktiv ist; nur das konfigurierte Formular wird verarbeitet.
- Serverseitige Property-Verifikation gegen den Store, Objektdaten nie aus dem Browser; Propstack-Mailblock `ps-kontaktanfrage` per Spezial-Mail-Tag `[_psl_propstack_block]`; Empfänger/HTML/BCC pro Anfrage im Speicher gesetzt.
- Lead-ID (UUID v4) mit serverseitiger Eindeutigkeit; optionale `client_cf_*`-Zuordnung (Standard: aus).
- Rate-Limit 5/10 min (HMAC-gehashte IP, Transient), Honeypot, neutrale Besuchermeldungen, Logging nur Lead-ID/Property-ID/Status/Code.
- Einstellungen: Formular, Propstack-Adresse, BCC, Feldzuordnung, Custom-Field-Zuordnung; Status-Box „Immobilienanfragen“; Admin-Hinweis bei fehlendem CF7.
- Kontaktbereich: Formular oder neutraler Hinweis; `--psl-scroll-offset` für fixe Header; Filter `psl_inquiry_form_available`, Action `psl_lead_sent`.

**Bugs behoben:** keine aus Vorphasen; während der Phase keine Plugin-Fehler gefunden (CF7-Spam bei Tests ohne User-Agent war erwartetes CF7-Verhalten).

**Neue Dateien:** `src/Leads/*.php` (10), `tests/Unit/LeadTest.php`, `tests/Http/InquiryTest.php`; erweitert: `tests/Support/mu-plugins/psl-test-hooks.php`.

**DB-Migrationen:** keine Schemaänderung; neue Einstellungsschlüssel in `propstack_lite_settings`, Transients `psl_rl_*`, `psl_lead_used_*` (werden von `uninstall.php` über das Präfix `psl_` entfernt).

**Breaking Changes:** keine. `property-contact.php` zeigt ohne verfügbares Formular jetzt einen neutralen Kontakthinweis.

**Offene Punkte:** echter Propstack-E2E-Test (nur nach Freigabe), Propstack-Konfiguration (Postfach, Automatisierung, Quelle, Custom Fields), Produktions-Mailtransport (SMTP/SPF/DKIM/DMARC), Avada-Verifikation.

## 2026-10-05 – Phase 3: Vollständige Immobilien-Detailseite

**Wichtigste Änderungen**
- Detailseite modular aus 15 überschreibbaren Teil-Templates: Breadcrumb, Kopf, Galerie, Kurzfakten mit CTA „Anfrage senden“ (Anker `#psl-contact`), Verkauft-Hinweis, Eckdaten (Preise & Kosten / Flächen & Räume / Objekt & Zustand), Objektbeschreibung, Ausstattung (Merkmalsliste + Text), Lage, Energie, Grundrisse, Sonstige Angaben/Provisionshinweis, Ansprechpartner, Kontaktbereich, Lightbox. Leere Bereiche erscheinen nicht.
- `PropertyViewModel` liefert präsentationsfertige Daten (Templates fast logikfrei); `Formatter` erweitert (Monatsbeträge, €/m², Zimmer, Etage, Datum, Energiekennwert, Ja/Nein, Enum-Übersetzung, „Preis auf Anfrage“ inkl. `price_on_inquiry`).
- Galerie: Hauptbild mit `srcset`/`sizes`/`fetchpriority="high"`, Vorschauleiste (lazy, feste Maße), neutraler Platzhalter ohne Bilder; Grundrisse getrennt. Lightbox `assets/js/psl-gallery.js` (Vanilla JS, `<dialog>`, Tastatur, Touch, Fokusführung), nur auf Detailseiten mit Bildern, `defer`; `preconnect` zum Bild-CDN nur dort.
- `FieldCatalog`: neue Whitelist-Felder `parking_space_types`, `pets_allowed`; deutsche `LABELS`, `FEATURE_LABELS`, `ENUM_LABELS` (`energy_efficiency_class`, `object_type`, `rs_category`); `contract_type` auf die Verbotsliste.
- Neuer Filter `psl_property_fallback_agent`; `psl_property_similar` wird jetzt auf allen 200-Detailseiten nach dem Artikel ausgelöst (nicht nur in der Verkauft-Phase).
- CSS `psl-detail.css` neu: Hero-Grid, Faktengruppen, Galerie, Lightbox, responsive (390/768/1024/1366 px geprüft); H1-Begrenzung nur unter 600 px.
- Dokumentation: neues `docs/frontend.md`.

**Bugs behoben (während der Phase gefunden)**
- Lightbox: Fokus verließ den Dialog per Tab; Wischen wurde durch Bild-Drag abgebrochen.
- Mobil: Wortumbruch langer Titel mitten im Wort; `sizes` des Hauptbilds an das Layout angepasst.

**Neue Dateien:** `templates/parts/{breadcrumb,property-gallery,property-summary,property-text,property-equipment,property-location,property-energy,property-floorplans,property-other,property-contact,lightbox}.php`, `assets/js/psl-gallery.js`, `tests/Unit/{FormatterTest,PropertyViewModelTest}.php`, `tests/Http/DetailPageContentTest.php`, `tests/fixtures/unit-full.json`, `tests/Support/mu-plugins/psl-test-hooks.php`, `docs/frontend.md`.
**Entfernt:** `templates/parts/property-description.php` (ersetzt durch das allgemeine `property-text.php`).

**DB-Migrationen:** keine. Neue Whitelist-Felder erscheinen nach dem nächsten Voll-Sync (`wp psl sync --full`).

**Breaking Changes:** Struktur des ViewModels geändert (`facts`/`image`/`description` → `factGroups`/`keyFacts`/`gallery`/`mainImage`/`texts`). Theme-Overrides aus Phase 2 für `parts/property-description.php` greifen nicht mehr; `parts/property-header.php` enthält kein Hauptbild mehr (jetzt `property-gallery.php`).

**Offene Punkte:** Avada-Verifikation (Sticky-Box, Ankersprung unter fixem Header, optionale Avada-Lightbox), zentraler Fallback-Ansprechpartner als Einstellung, ähnliche Immobilien (Inhalt), Propstack-Bildgröße zwischen 600 und 1920 px fehlt.

## 2026-10-05 – Phase 2: Routing und dynamische Detailseiten

**Wichtigste Änderungen**
- `Routing\Router`: Rewrite-Regeln `/immobilien/{slug}-{id}/` und Legacy `/immobilie/{slug}-{id}/`, Query-Vars `psl_property`/`psl_slug`/`psl_legacy`, Legacy `/immobilie/?ps_id=` über den `request`-Filter, kein Core-„Raten“ auf Legacy-Pfaden.
- Rewrite-Lifecycle repariert: Regeln vor dem Flush registrieren (Aktivierung), aus dem laufenden Request entfernen (Deaktivierung), einmaliger Flush bei geänderter `RULES_VERSION`; kein Flush bei normalen Requests.
- `Routing\RouteResolver` (rein): 200 aktiv/reserviert, 200 Verkauft-Phase (≤ 30 Tage ab `sold_at`), 410 entfernt/abgelaufen/nicht mehr öffentlich, 404 unbekannt.
- `Frontend\DetailController`: 301-Kanonisierung (Slug, Trailing Slash, Schreibweise, Kurzform, Legacy; Kampagnenparameter bleiben erhalten), 404 über Theme, 410-Seite, `noindex, follow` (Meta + `X-Robots-Tag`), Canonical-Link, Dokumenttitel, Body-Klassen; keine wp_posts-Abfrage der Hauptquery; **kein virtuelles WP_Post**.
- `Routing\UrlGenerator` (verschoben aus `Frontend\`): einzige URL-Quelle, absolut, Unterverzeichnis-fähig, Fallback ohne Pretty Permalinks.
- `Frontend\PropertyViewModel`, Basis-Templates `single-property.php`, `property-gone.php`, Teile `parts/property-*.php` mit Theme-Override; `TemplateLoader::header()/footer()` für klassische und Block-Themes; CSS `assets/css/psl-detail.css`.
- Hooks: `psl_before/after_property_content`, `psl_before/after_property`, `psl_property_contact`, `psl_property_similar`, Filter `psl_property_view_model`, `psl_property_template`, `psl_property_status_label`, `psl_detail_container_classes`, `psl_overview_url`.
- `Theme\AvadaAdapter` (optional, minimal, **nicht gegen reale Avada-Installation verifiziert**).
- Tests: Unit-Statusmatrix, HTTP-End-to-End-Suite (`tests/Http`) mit 22 Routing-Fällen, Datenschutz-/XSS-Prüfung im HTML und Request-Nachweis; Spy-mu-plugin für Testinstanzen.

**Bugs behoben**
- Legacy `?ps_id=` griff nicht, wenn die alte Seite `immobilie` nicht existiert (WordPress wertet den Pfad dann als Beitrag) → Erkennung über den angefragten Pfad.
- WordPress leitete `/immobilie/` ohne gültige ID per „Raten“ auf `/immobilien/` um → jetzt echtes 404.
- Detail-CSS: Theme-Einrückungen in Eckdaten, Wortumbruch langer Titel.
- Aus dem Audit: falsches Canonical (alle Objekte → `/immobilie/`), Soft-404, wirkungsloser Slug-Redirect, Rewrite-Flush ohne registrierte Regeln.

**Neue Dateien:** `src/Routing/{Router,UrlGenerator,RouteResolver,RouteDecision}.php`, `src/Frontend/{DetailController,PropertyViewModel}.php`, `src/Theme/AvadaAdapter.php`, `templates/single-property.php`, `templates/property-gone.php`, `templates/parts/property-{header,facts,description,agent,sold-notice}.php`, `assets/css/psl-detail.css`, `tests/Unit/RouteResolverTest.php`, `tests/Http/DetailRoutingTest.php`, `tests/Support/mu-plugins/psl-http-spy.php`.
**Entfernt:** `src/Frontend/UrlGenerator.php` (→ `Routing\UrlGenerator`). `picaflor.code-workspace` wird nicht mehr versioniert (lokale IDE-Datei).

**DB-Migrationen:** keine Schemaänderung. Neue Option `psl_rewrite_version`.

**Breaking Changes:** Klasse `Frontend\UrlGenerator` → `Routing\UrlGenerator` (intern). `TemplateLoader` hat neue Methoden `header()`/`footer()`.

**Offene Punkte:** reale Testumgebung mit Avada/SEO-Plugin/CF7; Yoast/Rank-Math-Abstimmung (Phase 5); ähnliche Immobilien und vollständige Objektseite (Phase 3); Kontaktformular (Phase 4).

## 2026-10-05 – Phase 1: API-Client, Whitelist-Mapper, PropertyStore, Sync (Plugin 0.3.0)

**Wichtigste Änderungen**
- Plugin neu strukturiert: Bootstrap + Namespace `PropstackLite\` in `src/`, eigener PSR-4-Autoloader (keine Composer-Laufzeitabhängigkeit).
- `Api\Client` (nur lesend, Header-Auth, Retry/Backoff, sichere Fehlermeldungen), `Api\UnitsEndpoint` (korrekte Pagination mit `per`, `sort_by=id`, `total_count`, Deduplizierung).
- Einheitliches Modell `Domain\Property` (+ `Address`, `Image`, `Agent`) aus einem Format (`expand=1`/`new=1`).
- `Mapping\PropertyMapper` mit Whitelist (`FieldCatalog`): Adressschutz, Bildfilter, nur öffentliche Maklerfelder, keine internen CRM-Felder.
- Tabelle `{prefix}psl_properties`, `Storage\PropertyStore` mit serverseitig erzwungener Status-Whitelist.
- `Sync\SyncService`: Full (mit Reconcile), Incremental (24 h Überlappung), Single; Lock; 30-Tage-Logik für verkaufte Objekte (Datenebene); Abbruch ohne Datenverlust bei API-Fehlern.
- WP-Cron (Intervall einstellbar, Voll-Sync alle 6 h), WP-CLI `wp psl sync|status|statuses|audit`.
- Einstellungsseite: Status-Auswahl aus Propstack („Öffentlich“, „Verkauft/Vermietet“, „Reserviert“), Sync-Intervall, Webhook-Token, Sync-Status, „Jetzt synchronisieren“. Admin-Hinweise.
- `[propstack_list]` liest nur noch aus dem Store; Templates mit Theme-Override; CSS als Datei; absolute Detail-URLs `/immobilien/{slug}-{id}/`.
- Webhook jetzt POST-only, Token im `permission_callback`, plant Sync statt Cache-Flush.
- `uninstall.php`.

**Bugs behoben (aus dem Audit)**
- Pagination/Sortierung wirkungslos (`limit`, `ordering`, fehlendes `meta.pages`), Duplikate/fehlende Objekte.
- Falsche Preise („Kaufpreis 0 €“, Miete als Kaufpreis).
- Relative Detail-URLs (Unterverzeichnis-Installationen).
- Leere Ergebnisse ungecacht / API-Request pro Seitenaufruf.
- `min_price`/`max_price` wirkungslos (jetzt Synonyme für `price_from`/`price_to`).
- Rewrite-Flush bei Aktivierung ohne registrierte Regeln (alte Regeln entfernt; neuer Router in Phase 2).

**Neue Dateien:** `src/**`, `templates/list.php`, `templates/parts/card.php`, `assets/css/psl-list.css`, `uninstall.php`, `composer.json`, `phpunit.xml.dist`, `tests/**`.

**DB-Migrationen:** Schema-Version 1 (Tabelle angelegt). Settings-Migration aus 0.2.x (`status=` aus `query_params` → „Öffentliche Status“; Default damit „Website Picaflor“).

**Breaking Changes**
- Einstellungen `endpoint`, `query_params`, `cache_minutes`, `detail_url_template`, `dev_no_cache` entfallen.
- Shortcode: beliebige API-Parameter werden nicht mehr durchgereicht (insb. kein `status`); `local_sort_by` entfällt; `ordering` entfällt (stattdessen `sort_by`/`order`).
- Detail-URLs `/immobilie/…` und `?ps_id=` funktionieren nicht mehr; neue Links `/immobilien/…` liefern bis Phase 2 **404**.
- Webhook nur noch per POST.
- Mindestanforderung PHP 8.1.

**Offene Punkte:** Router/Detailseiten (Phase 2), reale Testumgebung mit Avada/CF7/SEO-Plugin, Webhook-Registrierung in Propstack durch Admin, System-Cron für Produktion, fachliche Auswahl „Verkauft“/„Reserviert“ im Backend.

## 2026-10-05 – Phase 0: Repository- und Security-Basis

- Git-Repository angelegt; Basis-Commit des Originalstands (0.2.0) ohne Secret-Datei.
- `.gitignore` (Secrets, `vendor/`, Test-Cache), Root-`.htaccess`: `Propstack-API.txt` war per HTTP abrufbar (200) → jetzt 403; `docs/`, `.git/`, `tools/` → 404.
- Altcode bereinigt: unsicherer Detail-Bootstrap entfernt (ungefilterte CRM-Ausgabe/XSS, hartkodiertes Dev-Thumbnail mit undefinierter Variable, ungeprüfte `ps_id`, zwei ungecachte API-Calls pro Aufruf), toter Code entfernt (`fetch_single`, `get_unit`, `psl_detail_template`, `build_url`, `activate`, `templates/psl-detail.php` mit `print_r`).
- Liste: keine Straße/Hausnummer mehr, keine privaten Bilder/Grundrisse als Vorschaubild; Undefined-Variable-Warning behoben; Fehlerdetails nur für Admins.
- Dokumentation `docs/` und `CLAUDE.md` angelegt (zusammen mit Phase 1).
