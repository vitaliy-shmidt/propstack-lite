# Changelog (Entwicklungsfortschritt)

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
