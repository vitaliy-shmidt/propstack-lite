# Propstack Listings Lite – Dokumentation

| Phase | Status |
|---|---|
| Phase 0 – Security | abgeschlossen (2026-10-05) |
| Phase 1 – API + Store + Sync | abgeschlossen (2026-10-05) |
| Phase 2 – Routing | abgeschlossen (2026-10-05) |
| Phase 3 – Detailseite | abgeschlossen (2026-10-05) |
| Phase 4 – Leads | abgeschlossen (2026-10-05) |
| Phase 5 – SEO | abgeschlossen (2026-10-06) |
| Release-Candidate-Abnahme | abgeschlossen (2026-10-06), **PASS WITH OPEN ITEMS** – [acceptance-rc-2026-10-06.md](acceptance-rc-2026-10-06.md) |
| Phase 6 – Tracking | abgeschlossen (2026-10-06), Standard aus; Staging-Smoke-Test und reales Consent-Tool offen |
| Phase 7 – Filter/UX | geplant |
| Phase 8 – Hardening | geplant |

## Projektziel

Vollständige Immobilienintegration zwischen **Propstack** (führendes System) und der WordPress-Website von Picaflor Immobilien (Theme Avada):

- öffentliche Immobilien aus Propstack laden und in einer Übersicht `/immobilien/` darstellen
- dynamische, SEO-fähige Detailseiten `/immobilien/{slug}-{id}/` ohne manuell angelegte WordPress-Seiten
- vollständige Objektinformationen, Galerie, Ansprechpartner
- Interessentenanfragen über Contact Form 7 mit eindeutiger Objektzuordnung in Propstack
- Kampagnen-Leads messbar machen (UTM/GCLID, dataLayer-Event ohne personenbezogene Daten)

## Architektur in Kürze

```
Propstack API ──(Cron / WP-CLI / Admin)──► SyncService ──► PropertyMapper (Whitelist) ──► PropertyStore ({prefix}psl_properties)
                                                                                                │
Besucher ──► Router (/immobilien/{slug}-{id}/) ──► RouteResolver ──► DetailController ──► Templates ◄┤
Besucher ──► [propstack_list] ──────────────────────────────────────────────────────────► Templates ◄┘
```

Besucher-Requests erzeugen keine Propstack-Requests (nachgewiesen). Details: [architecture.md](architecture.md).

## Aktueller Entwicklungsstand (nach Phase 6)

**Implementiert:**
- API-Client (nur lesend), Whitelist-Mapper, einheitliches `Property`-Modell, Tabelle `{prefix}psl_properties`
- Voll-, inkrementeller und Einzel-Sync mit Lock; Einstellungsseite mit Status-Auswahl; WP-CLI `wp psl …`; Shortcode `[propstack_list]`
- Dynamische Detailseiten `/immobilien/{slug}-{id}/` mit 200/301/404/410, Legacy-Weiterleitungen, Verkauft/Vermietet-Phase (30 Tage, noindex), Reserviert-Badge, Canonical und Robots-Grundlagen
- **Vollständige Detailseite:** Breadcrumb, Hero mit Galerie und Kurzfakten, Eckdaten (Kosten, Flächen, Zustand), Beschreibung, Ausstattung mit Merkmalsliste, Lage, Energie, Grundrisse, Sonstiges/Provision, Ansprechpartner, Kontaktbereich (Einhängepunkt), Hook für ähnliche Immobilien; responsive Bildergalerie mit Lightbox (Vanilla JS, `<dialog>`); alles modular und im Theme überschreibbar ([frontend.md](frontend.md))
- Optionaler Avada-Adapter (nicht gegen reales Avada verifiziert)

- **Immobilienanfragen:** Contact-Form-7-Formular auf anfragbaren Detailseiten, serverseitige Property-Verifikation, Propstack-Mailblock `ps-kontaktanfrage` an die konfigurierte Propstack-Adresse, Rate-Limit, Honeypot, Lead-ID, Admin-Status ([leads.md](leads.md)). Echter Propstack-E2E-Test am 2026-10-06 **nicht durchgeführt** – Voraussetzungen (Propstack-Postfach, CRM-Prüfzugriff, Mailtransport) nicht verifizierbar (Protokoll in leads.md).
- **SEO:** zentraler `SeoService` (Title, Description, Canonical, Robots, Open Graph/Twitter, JSON-LD `RealEstateListing`/`Offer`/`BreadcrumbList`), Ausgabe ohne SEO-Plugin selbst oder über Yoast SEO bzw. Rank Math (Priorität Yoast > Rank Math > Core, Admin-Hinweis bei mehreren), XML-Sitemap für Core/Yoast/Rank Math ([seo.md](seo.md)).

- **Attribution & Conversion (Phase 6):** First Touch + Last Non-Direct im First-Party-Cookie `psl_attr` (90 Tage, nur mit Marketing-Consent), `ConsentProviderInterface` (Standard: kein Consent), `property_lead` im dataLayer nur nach erfolgreichem CF7-Versand, dedupliziert über die Lead-ID, optionale Propstack-`client_cf_*`-Zuordnung; lädt kein GTM/GA4/Pixel ([tracking.md](tracking.md)).

**Noch nicht implementiert:** ähnliche Immobilien, Filter-UI/Pagination (Phase 7).

## Dokumente

| Dokument | Inhalt |
|---|---|
| [architecture.md](architecture.md) | Komponenten, Klassen, Datenfluss, Designentscheidungen |
| [propstack-api.md](propstack-api.md) | API-Erkenntnisse, Rechte des Keys, Parameter, Besonderheiten |
| [property-model.md](property-model.md) | internes Property-Modell, Whitelist, verbotene Felder |
| [database.md](database.md) | Tabelle `{prefix}psl_properties`, Optionen, Migrationen |
| [sync.md](sync.md) | Sync-Arten, Statuslogik, Cron, CLI, Diagnose |
| [routing-seo.md](routing-seo.md) | URLs, Rewrite-Regeln, Statusmatrix, Kanonisierung |
| [seo.md](seo.md) | SEO: Title/Description/Robots/OG/JSON-LD, Core-/Yoast-/Rank-Math-Modus, Sitemaps |
| [frontend.md](frontend.md) | Detailseite: Template-Teile, ViewModel, Formatter, Galerie/Lightbox, Bildfilter, Energie, Ansprechpartner, Hooks, Overrides |
| [leads.md](leads.md) | Immobilienanfragen: CF7 → Propstack-Mail, Feldzuordnung, Mailformat, Sicherheit, E2E-Anleitung |
| [tracking.md](tracking.md) | Attribution (First/Last Non-Direct), Cookie, Consent-Provider, `property_lead`, Propstack-Zuordnung, Staging-Konfiguration |
| [avada.md](avada.md) | Theme-Integration (Adapter nicht gegen reales Avada verifiziert) |
| [security.md](security.md) | verbindliche Sicherheitsregeln |
| [testing.md](testing.md) | Teststrategie, Testumgebung, Ergebnisse, Checklisten |
| [changelog.md](changelog.md) | Entwicklungsfortschritt je Phase |
| [acceptance-rc-2026-10-06.md](acceptance-rc-2026-10-06.md) | Release-Candidate-Abnahme: Ergebnisse, Bugs, offene Punkte vor Staging |
| [decisions/](decisions/) | Architekturentscheidungen (ADRs) |

## Bekannte offene Punkte

Vollständige Liste vor Staging/Produktion: [acceptance-rc-2026-10-06.md](acceptance-rc-2026-10-06.md).

- **Staging (DomainFactory, PHP 8.2, Avada):** laut Rückmeldung laufen Sync, Übersicht, Detailseite und Formular; ein eigener Smoke-Test durch Claude war mangels Zugang nicht möglich – Avada-Checkliste in [avada.md](avada.md).
- **Tracking:** Consent-Tool der Live-Site noch nicht festgelegt/angebunden; bis dahin bleibt Tracking wirkungslos (Provider `none`).
- **Avada nicht verifiziert:** Getestet gegen Wegwerf-Instanzen (WordPress 7.1.2 in `/Picaflor/` und frisch in `/rc-site/`, Twenty Twenty-One/-Five, CF7 6.1.7, Yoast 28.6, Rank Math 1.0.279, WP Super Cache 3.1.4, PHP 8.3 und 8.2). Avada und die tatsächliche SEO-/Cache-Konfiguration der Live-Site gelten erst mit Staging als verifiziert.
- Übersicht ohne Blätterfunktion (Phase 7): Objekte jenseits von `per` erscheinen nur über die Sitemap; `per` auf der Live-Seite ausreichend hoch setzen.
- SEO-Integration mit Yoast SEO 28.6 und Rank Math 1.0.279 nur in der Testinstanz geprüft; andere SEO-Plugins (AIOSEO, SEOPress …) werden nicht erkannt ([seo.md](seo.md)).
- Propstack-E2E-Test der Anfragen steht weiter aus ([leads.md](leads.md)).
- Der API-Key hat nur Leserechte auf Objekte, Status und Projekte. Webhooks (`POST /v1/hooks`) muss ein Propstack-Admin registrieren.
- WP-Cron hängt von Seitenaufrufen ab; für verlässliche Intervalle einen System-Cron einrichten ([sync.md](sync.md)).
- Einstellungen „Status verkauft/vermietet“ und „Reserviert“ müssen im Backend fachlich gesetzt werden (Default: leer).
- Zentraler Fallback-Ansprechpartner als Einstellung ist noch nicht umgesetzt (bisher nur Filter `psl_property_fallback_agent`).
- Propstack liefert keine Bildgröße zwischen 600 und 1920 px; mobile Detailseiten laden daher das große Hauptbild (siehe [frontend.md](frontend.md)).
