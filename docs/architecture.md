# Architektur

Stand: nach Phase 4 (Plugin-Version 0.3.0). Geplante Teile sind als **Geplant** markiert.

## Grundprinzipien

1. **Propstack ist führend.** WordPress hält nur eine lokale, gefilterte Kopie der öffentlichen Objekte.
2. **Keine API-Requests im Besucher-Request.** Sync läuft in WP-Cron (`wp-cron.php`), WP-CLI oder Admin-Aktionen. Liste und Detailseiten lesen ausschließlich aus `{prefix}psl_properties` (per HTTP-Test nachgewiesen, siehe [testing.md](testing.md)).
3. **Whitelist statt Blacklist.** Nur `PropertyMapper` liest Propstack-Rohdaten und übernimmt nur definierte Felder ([property-model.md](property-model.md)).
4. **Sichtbarkeit serverseitig.** Öffentlich ist nur `state = active` UND `status_id ∈ Einstellung „Öffentliche Propstack-Status“` – zur Laufzeit in jeder Abfrage bzw. Routing-Entscheidung geprüft.
5. **Theme-neutral.** Templates mit Theme-Override; Avada nur über einen optionalen Adapter (nicht gegen reales Avada verifiziert).
6. **ID ist maßgeblich.** Detail-URLs werden per 301 auf die kanonische URL `/immobilien/{slug}-{id}/` gebracht; 200/404/410 entscheidet allein der gespeicherte Zustand.

## Datenfluss

```
                   ┌──────────── Cron (psl_sync_incremental / psl_sync_full) ────────────┐
                   │             WP-CLI (wp psl sync) · Admin-Button · Webhook → Cron    │
                   ▼                                                                      │
Propstack API ◄── Api\Client ◄── Api\UnitsEndpoint ◄── Sync\SyncService ──► Sync\StateResolver
 (GET, X-API-KEY)  (Retry,        (per/page, sort_by=id,     │   (Lock, Status,          (upsert/sold/keep/
                    Backoff)       expand=1, total_count)    │    Fehlerabbruch)          remove/ignore)
                                                             ▼
                                              Mapping\PropertyMapper (Whitelist, Datenschutz)
                                                             ▼
                                                     Domain\Property
                                                             ▼
                                         Storage\PropertyStore → {prefix}psl_properties
                                                             ▲
Besucher ─► [propstack_list] ─► Frontend\ListShortcode ─► TemplateLoader ─► templates/list.php
Besucher ─► /immobilien/{slug}-{id}/ ─► Routing\Router (Rewrite, Query-Vars, Legacy)
            ─► Frontend\DetailController ─► PropertyStore::find() ─► Routing\RouteResolver (200/404/410)
            ─► 301-Kanonisierung (Routing\UrlGenerator) ─► Frontend\PropertyViewModel
            ─► TemplateLoader ─► single-property.php | property-gone.php | Theme-404
            ─► Seo\SeoContext ─► Seo\SeoService ─► CoreAdapter | YoastAdapter | RankMathAdapter (Head)
Suchmaschine ─► Sitemap (Core/Yoast/Rank Math) ─► Seo\Sitemap\SitemapSource ─► PropertyStore::sitemapRows()
Besucher ─► psl-tracking.js (Consent, Attribution-Cookie) ─► psl-lead-event.js (Formularfeld psl_attr, dataLayer)
            CF7 ─► Cf7Integration ─► Filter psl_lead_attribution ─► Tracking\TrackingIntegration (Propstack client_cf_*)
```

## Komponenten und Klassen

| Klasse | Verantwortung |
|---|---|
| `propstack-lite.php` | Plugin-Header, Konstanten (`PSL_VERSION`, `PSL_DIR`, `PSL_URL`), PSR-4-Autoloader, Aktivierung/Deaktivierung |
| `Plugin` | Verdrahtung, Hook-Registrierung, lazy erzeugte Dienste, Rewrite-Lifecycle bei (De-)Aktivierung |
| `Settings` | Option `propstack_lite_settings`, API-Key (Konstante `PSL_API_KEY` hat Vorrang), Sanitizing, Migration aus 0.2.x |
| `Api\Client` | einziger HTTP-Zugang; Header-Auth; Retry bei Netzwerk/429/5xx; `ApiException` mit Kategorie |
| `Api\QueryString` | Rails-kompatible Array-Parameter `key[]=` |
| `Api\UnitsEndpoint` | `listAll()` (alle Seiten, dedupliziert, Vollständigkeitsflag), `get()`, `statuses()` |
| `Domain\Property`, `Address`, `Image`, `Agent` | unveränderliches internes Modell, `toArray()`/`fromArray()` |
| `Mapping\PropertyMapper` | Rohdaten → `Property`; Adressschutz, Bildfilter, öffentliche Maklerfelder |
| `Mapping\FieldCatalog` | Whitelist für Eckdaten, Energie, Ausstattung, Texte; Liste verbotener Felder |
| `Mapping\Sanitizer` | Typ-Normalisierung, Text/HTML-Bereinigung, URL-Host-Whitelist |
| `Storage\Schema` | Tabellendefinition, `dbDelta`, Versionsoption |
| `Storage\PropertyStore` | Upsert, Statusübergänge, öffentliche Abfrage mit Whitelist, `find()`, Audit |
| `Storage\ListCriteria`, `StoredProperty` | Abfragekriterien (nur einschränkend), Zeilenobjekt |
| `Sync\SyncService` | Full/Incremental/Single, Reconcile, Bereinigung, Fehlerbehandlung |
| `Sync\StateResolver` | reine Sync-Zustandslogik (testbar ohne WordPress) |
| `Sync\SyncLock`, `SyncState`, `SyncResult`, `Scheduler` | Lock, Status-Option, Ergebnis, Cron-Planung |
| `Routing\Router` | Rewrite-Regeln, Query-Vars, Legacy-Mapping (`?ps_id=`), Rewrite-Version |
| `Routing\UrlGenerator` | einzige Quelle für Detail-, kanonische, Legacy- und Übersichts-URLs (absolut, Unterverzeichnis-fähig) |
| `Routing\RouteResolver`, `RouteDecision` | reine Statusentscheidung 200/404/410 aus gespeichertem Zustand und Zeit |
| `Frontend\DetailController` | WordPress-Request-Steuerung der Detailseite: Query, 404/410, 301, Header `X-Robots-Tag`, Body-Klassen, Template (Head-Tags seit Phase 5 in `Seo\*`) |
| `Seo\SeoService`, `SeoData` | zentrale SEO-Logik ohne WordPress: Title, Description, Robots, OG/Twitter, JSON-LD ([seo.md](seo.md)) |
| `Seo\SeoContext`, `SeoPlugins`, `SeoIntegration` | SEO-Daten je Request; Erkennung Yoast > Rank Math > Core; Registrierung genau eines Adapters, Sitemaps, Cache-Invalidierung |
| `Seo\CoreAdapter`, `YoastAdapter`, `RankMathAdapter` | Ausgabe ohne SEO-Plugin bzw. Werteübergabe über offizielle Yoast-/Rank-Math-Filter |
| `Seo\Sitemap\*` | `SitemapSource` (indexierbare Objekte, URL = Canonical), Provider für Core/Yoast/Rank Math, `SitemapCache` |
| `Frontend\PropertyViewModel` | präsentationsfertige Template-Daten aus dem gewhitelisteten Modell: Breadcrumb, Preis, Kurzfakten, Eckdaten-Gruppen, Merkmale, Energie, Galerie/Grundrisse (nur HTTPS, `srcset`), Ansprechpartner, Status-Badges ([frontend.md](frontend.md)) |
| `Frontend\Formatter` | deutsche Formatierung (Geld, Monatsbeträge, €/m², Flächen, Zimmer, Etage, Datum, Energiekennwert) und Enum-Übersetzung |
| `assets/js/psl-gallery.js` | Lightbox (Vanilla JS, `<dialog>`, Tastatur/Touch), nur auf Detailseiten mit Bildern |
| `Frontend\TemplateLoader` | Templates mit Theme-Override (`{theme}/propstack-lite/`), Header/Footer für klassische und Block-Themes |
| `Frontend\ListShortcode` | Listenausgabe aus dem Store |
| `Theme\AvadaAdapter` | optional, nur bei aktivem Avada; markiert Seite/Wrapper (**nicht verifiziert**) |
| `Admin\SettingsPage`, `Admin\Notices` | Einstellungen, Sync-/Lead-/SEO-Status, „Jetzt synchronisieren“, Hinweise (u. a. „Mehrere SEO-Plugins aktiv“) |
| `Rest\WebhookController` | `POST /wp-json/propstack/v1/webhook` → plant Sync (kein Sync im Request) |
| `Cli\Command` | `wp psl sync|status|statuses|audit` |
| `Support\Slugger`, `Logger`, `Clock` | Slugs, Logging ohne PII, testbare Zeit |
| `Tracking\TrackingIntegration`, `Attribution`, `Touch`, `AttributionStorage`, `LeadEvent`, `Consent\*` | Phase 6: Skripte, Hidden Field, Attribution für Propstack, Event-Daten; Consent-Provider-Registry ([tracking.md](tracking.md)) |
| `assets/js/psl-tracking.js`, `psl-lead-event.js` | Consent/Attribution seitenweit; Formular-Übergabe und `property_lead` nur auf Detailseiten mit Formular |
| `Support\PageCachePurger` | leert Full-Page-Caches (WP Super Cache, W3TC, WP Rocket, LiteSpeed, WP Fastest Cache, SiteGround; Action `psl_purge_page_cache`) nach Sync mit Bestandsänderungen und nach Einstellungsänderungen |

## Designentscheidungen

| Entscheidung | Begründung | ADR |
|---|---|---|
| Lokale Tabelle statt Transients | 410-Logik braucht Historie (welche IDs waren öffentlich), Filter/Sortierung per SQL, Sitemap mit `lastmod`, keine Requests im Besucher-Request | [001](decisions/001-local-property-store.md) |
| Kein CPT | Propstack ist führend; CPTs würden Redaktionsmasken, Revisionen und SEO-Plugin-Verhalten erzeugen, die Doppelpflege und Konflikte verursachen | [002](decisions/002-no-cpt.md) |
| CF7 → Propstack-Mail für Leads | offizieller Propstack-Website-Workflow, Key ohne CRM-Schreibrechte, geringeres Risiko | [003](decisions/003-cf7-propstack-leads.md) |
| Eigener Router | Detailseiten ohne WordPress-Seite pro Objekt, stabile URLs über die ID | [004](decisions/004-dynamic-routing.md) |
| Whitelist-Mapper | Detail-API enthält interne CRM-Daten; nur explizit erlaubte Felder dürfen in WordPress landen | [005](decisions/005-whitelist-mapper.md) |
| Strukturierter Slug | Propstack-Titel sind Marketing-Texte bzw. enthalten interne Kürzel; der Slug ist dekorativ, maßgeblich ist die ID | [004](decisions/004-dynamic-routing.md) |
| Kein virtuelles `WP_Post` | Fake-Posts würden von Themes/SEO-Plugins als echter Inhalt behandelt; die Route ist für WordPress weder Seite noch Beitrag | [004](decisions/004-dynamic-routing.md), [routing-seo.md](routing-seo.md) |

## Abhängigkeiten

- Laufzeit: WordPress ≥ 6.0, PHP ≥ 8.1. **Keine** Composer-Laufzeitabhängigkeiten (eigener Autoloader).
- Dev: PHPUnit 10, `php-stubs/wordpress-stubs` (siehe `composer.json`). `vendor/` wird nicht ausgeliefert.

## Lead-Architektur – implementiert (Phase 4)

Contact Form 7 (nur wenn aktiv) rendert, validiert und versendet; Propstack Lite ergänzt nur für das konfigurierte Formular: Hidden Fields (`psl_property_id`, `psl_lead_id`), Honeypot, in `wpcf7_before_send_mail` Rate-Limit, Verifikation der Property gegen den Store (`LeadContextFactory` + `RouteResolver`), dann `Cf7MailLeadSink` (Empfänger = Propstack-Adresse aus den Einstellungen, HTML, Spezial-Mail-Tag `[_psl_propstack_block]` mit dem Block aus `InquiryMailFormatter`). Abstraktion `LeadSink` für eine spätere API-Variante. Keine Propstack-API-Schreibzugriffe, kein eigenes Lead-Archiv. Details: [leads.md](leads.md).

| Klasse | Verantwortung |
|---|---|
| `Leads\Cf7Integration` | CF7-Hooks (früher Formular-ID-Check), Rendering über `psl_property_contact`, Status-Logging, Action `psl_lead_sent` |
| `Leads\LeadContextFactory` | Property-ID normalisieren, im Store verifizieren (anfragbar?), Formularwerte normalisieren, Zustimmung prüfen |
| `Leads\LeadContext`, `LeadData` | unveränderlicher Anfragekontext / normalisierte Formulardaten |
| `Leads\InquiryMailFormatter` | HTML-Block `ps-kontaktanfrage` (escaped, unit-testbar) |
| `Leads\LeadSink`, `Cf7MailLeadSink` | Ausgabekanal; V1: CF7-Mail-Properties pro Anfrage im Speicher |
| `Leads\RateLimiter` | 5 Anfragen / 10 min je HMAC-gehashter Client-IP (Transient) |
| `Leads\LeadSetupCheck` | Konfigurationsprüfung für die Einstellungsseite |

## SEO-Architektur – implementiert (Phase 5)

Eine Quelle für alle Werte: `Seo\SeoService` berechnet aus `Property`, `RouteDecision`, kanonischer URL und Site-Daten ein `SeoData`-Objekt. `Seo\SeoIntegration` registriert genau einen Ausgabe-Adapter (Yoast > Rank Math > Core); die Adapter enthalten keine Fachlogik. Sitemaps lesen über `SitemapSource` dieselben Store-Kriterien wie die Detailseite (aktiv + öffentlicher Status) und dieselbe URL wie der Canonical. Statuscodes, 301 und `X-Robots-Tag` bleiben im `DetailController`. Details: [seo.md](seo.md).

## Tracking-Architektur – implementiert (Phase 6)

Attribution ausschließlich clientseitig im First-Party-Cookie (`psl-tracking.js`, seitenweit, nur mit Consent); das Formular-Skript überträgt sie nur bei aktuellem Consent im Hidden Field `psl_attr`, der Server (`Tracking\AttributionStorage`) validiert mit denselben Regeln. Consent über `Tracking\Consent\ConsentProviderInterface` (Standard `none`). Conversion-Event: Server hängt nach `mail_sent` öffentliche Event-Daten an die CF7-Antwort (`Tracking\LeadEvent`), `psl-lead-event.js` pusht `property_lead` einmal pro Lead-ID. Keine eigene Tabelle, keine externen Requests, kein GTM. Details: [tracking.md](tracking.md).

## Theme-Integration

Theme-neutrale Templates mit Override und Hooks (implementiert, getestet mit Twenty Twenty-One und Twenty Twenty-Five). `Theme\AvadaAdapter` (implementiert, **noch nicht gegen reale Avada-Installation verifiziert**), nur geladen wenn Avada aktiv. Details: [avada.md](avada.md), [routing-seo.md](routing-seo.md).
