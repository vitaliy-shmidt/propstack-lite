# Architektur

Stand: nach Phase 1 (Plugin-Version 0.3.0). Geplante Teile sind als **Geplant** markiert.

## Grundprinzipien

1. **Propstack ist führend.** WordPress hält nur eine lokale, gefilterte Kopie der öffentlichen Objekte.
2. **Keine API-Requests im Besucher-Request.** Sync läuft in WP-Cron (`wp-cron.php`), WP-CLI oder Admin-Aktionen. Frontend liest ausschließlich aus `{prefix}psl_properties` (nachgewiesen, siehe [testing.md](testing.md)).
3. **Whitelist statt Blacklist.** Nur `PropertyMapper` liest Propstack-Rohdaten und übernimmt nur definierte Felder ([property-model.md](property-model.md)).
4. **Sichtbarkeit serverseitig.** Öffentlich ist nur `state = active` UND `status_id ∈ Einstellung „Öffentliche Propstack-Status“` – zur Laufzeit in jeder Abfrage geprüft.
5. **Theme-neutral.** Templates mit Theme-Override; Avada nur über Adapter (geplant).

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
Besucher ─► Shortcode [propstack_list] ─► Frontend\ListShortcode ─► TemplateLoader ─► templates/*.php
            (später: Router → Detailseite, SEO, Leads, Tracking)
```

## Komponenten und Klassen

| Klasse | Verantwortung |
|---|---|
| `propstack-lite.php` | Plugin-Header, Konstanten (`PSL_VERSION`, `PSL_DIR`, `PSL_URL`), PSR-4-Autoloader, Aktivierung/Deaktivierung |
| `Plugin` | Verdrahtung, Hook-Registrierung, lazy erzeugte Dienste |
| `Settings` | Option `propstack_lite_settings`, API-Key (Konstante `PSL_API_KEY` hat Vorrang), Sanitizing, Migration aus 0.2.x |
| `Api\Client` | einziger HTTP-Zugang; Header-Auth; Retry bei Netzwerk/429/5xx; `ApiException` mit Kategorie |
| `Api\QueryString` | Rails-kompatible Array-Parameter `key[]=` |
| `Api\UnitsEndpoint` | `listAll()` (alle Seiten, dedupliziert, Vollständigkeitsflag), `get()`, `statuses()` |
| `Domain\Property`, `Address`, `Image`, `Agent` | unveränderliches internes Modell, `toArray()`/`fromArray()` |
| `Mapping\PropertyMapper` | Rohdaten → `Property`; Adressschutz, Bildfilter, öffentliche Maklerfelder |
| `Mapping\FieldCatalog` | Whitelist für Eckdaten, Energie, Ausstattung, Texte; Liste verbotener Felder |
| `Mapping\Sanitizer` | Typ-Normalisierung, Text/HTML-Bereinigung, URL-Host-Whitelist |
| `Storage\Schema` | Tabellendefinition, `dbDelta`, Versionsoption |
| `Storage\PropertyStore` | Upsert, Statusübergänge, öffentliche Abfrage mit Whitelist, Audit |
| `Storage\ListCriteria`, `StoredProperty` | Abfragekriterien (nur einschränkend), Zeilenobjekt |
| `Sync\SyncService` | Full/Incremental/Single, Reconcile, Bereinigung, Fehlerbehandlung |
| `Sync\StateResolver` | reine Zustandslogik (testbar ohne WordPress) |
| `Sync\SyncLock`, `SyncState`, `SyncResult`, `Scheduler` | Lock, Status-Option, Ergebnis, Cron-Planung |
| `Admin\SettingsPage`, `Admin\Notices` | Einstellungen, Sync-Status, „Jetzt synchronisieren“, Hinweise |
| `Rest\WebhookController` | `POST /wp-json/propstack/v1/webhook` → plant Sync (kein Sync im Request) |
| `Cli\Command` | `wp psl sync|status|statuses|audit` |
| `Frontend\ListShortcode`, `TemplateLoader`, `Formatter`, `UrlGenerator` | Listenausgabe aus dem Store |
| `Support\Slugger`, `Logger`, `Clock` | Slugs, Logging ohne PII, testbare Zeit |

## Designentscheidungen

| Entscheidung | Begründung | ADR |
|---|---|---|
| Lokale Tabelle statt Transients | 410-Logik braucht Historie (welche IDs waren öffentlich), Filter/Sortierung per SQL, Sitemap mit `lastmod`, keine Requests im Besucher-Request | [001](decisions/001-local-property-store.md) |
| Kein CPT | Propstack ist führend; CPTs würden Redaktionsmasken, Revisionen und SEO-Plugin-Verhalten erzeugen, die Doppelpflege und Konflikte verursachen | [002](decisions/002-no-cpt.md) |
| CF7 → Propstack-Mail für Leads | offizieller Propstack-Website-Workflow, Key ohne CRM-Schreibrechte, geringeres Risiko | [003](decisions/003-cf7-propstack-leads.md) |
| Eigener Router | Detailseiten ohne WordPress-Seite pro Objekt, stabile URLs über die ID | [004](decisions/004-dynamic-routing.md) |
| Whitelist-Mapper | Detail-API enthält interne CRM-Daten; nur explizit erlaubte Felder dürfen in WordPress landen | [005](decisions/005-whitelist-mapper.md) |
| Strukturierter Slug | Propstack-Titel sind Marketing-Texte bzw. enthalten interne Kürzel; der Slug ist dekorativ, maßgeblich ist die ID | [004](decisions/004-dynamic-routing.md) |

## Abhängigkeiten

- Laufzeit: WordPress ≥ 6.0, PHP ≥ 8.1. **Keine** Composer-Laufzeitabhängigkeiten (eigener Autoloader).
- Dev: PHPUnit 10, `php-stubs/wordpress-stubs` (siehe `composer.json`). `vendor/` wird nicht ausgeliefert.

## Lead-Architektur – **Geplant (Phase 4)**

Contact Form 7 → serverseitige Anreicherung aus dem Store → HTML-Mail im Propstack-Format `ps-kontaktanfrage` → Propstack-Automatisierung „Neue Portalanfrage“. Abstraktion über `LeadSink` (`Cf7MailLeadSink`, später optional `PropstackApiLeadSink`). Details: [leads.md](leads.md).

## SEO-Architektur – **Geplant (Phase 2/5)**

`Seo\SeoService` liefert Title, Description, Canonical, Robots, OG und JSON-LD; Adapter für WordPress-Core, Yoast, Rank Math; eigener Sitemap-Provider. Details: [routing-seo.md](routing-seo.md).

## Tracking-Architektur – **Geplant (Phase 6)**

Attribution clientseitig (First Touch + Last Non-Direct Touch) hinter `ConsentProviderInterface`; Übergabe an CF7-Hidden-Fields; `dataLayer.push({event: 'property_lead', …})` nur nach `wpcf7mailsent`. Details: [tracking.md](tracking.md).

## Theme-Integration – **Geplant (Phase 2/3)**

`Theme\AvadaAdapter`, nur geladen wenn Avada aktiv. Details: [avada.md](avada.md).
