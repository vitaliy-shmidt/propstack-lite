# Propstack Listings Lite – Dokumentation

| Phase | Status |
|---|---|
| Phase 0 – Security | abgeschlossen (2026-10-05) |
| Phase 1 – API + Store + Sync | abgeschlossen (2026-10-05) |
| Phase 2 – Routing | geplant |
| Phase 3 – Detailseite | geplant |
| Phase 4 – Leads | geplant |
| Phase 5 – SEO | geplant |
| Phase 6 – Tracking | geplant |
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
Besucher ──► [propstack_list] (später Router/Detailseite) ──► Templates ◄────────────────────────┘
```

Besucher-Requests erzeugen keine Propstack-Requests. Details: [architecture.md](architecture.md).

## Aktueller Entwicklungsstand (nach Phase 1)

**Implementiert:**
- API-Client (nur lesend, Retry/Backoff, keine Secrets in Fehlermeldungen)
- Whitelist-Mapper mit einheitlichem `Property`-Modell
- lokale Tabelle `{prefix}psl_properties`
- Voll-, inkrementeller und Einzel-Sync mit Lock
- 30-Tage-Logik für verkaufte Objekte (Datenebene)
- Einstellungsseite mit Status-Auswahl aus Propstack, Admin-Hinweise
- WP-CLI `wp psl …`
- Shortcode `[propstack_list]` liest nur aus dem Store
- Uninstall

**Noch nicht implementiert:**
- Router/Detailseiten: Detail-Links `/immobilien/…` liefern bis Phase 2 **404**
- Galerie, Leads, SEO, Tracking, Filter-UI

## Dokumente

| Dokument | Inhalt |
|---|---|
| [architecture.md](architecture.md) | Komponenten, Klassen, Datenfluss, Designentscheidungen |
| [propstack-api.md](propstack-api.md) | API-Erkenntnisse, Rechte des Keys, Parameter, Besonderheiten |
| [property-model.md](property-model.md) | internes Property-Modell, Whitelist, verbotene Felder |
| [database.md](database.md) | Tabelle `{prefix}psl_properties`, Optionen, Migrationen |
| [sync.md](sync.md) | Sync-Arten, Statuslogik, Cron, CLI, Diagnose |
| [routing-seo.md](routing-seo.md) | URLs, 200/301/404/410, SEO-Konzept (geplant) |
| [leads.md](leads.md) | CF7 → Propstack-Mailintegration (geplant) |
| [tracking.md](tracking.md) | UTM/GCLID, Consent, dataLayer (geplant) |
| [avada.md](avada.md) | Theme-Integration (geplant, nicht verifiziert) |
| [security.md](security.md) | verbindliche Sicherheitsregeln |
| [testing.md](testing.md) | Teststrategie, Testumgebung, Checklisten |
| [changelog.md](changelog.md) | Entwicklungsfortschritt je Phase |
| [decisions/](decisions/) | Architekturentscheidungen (ADRs) |

## Bekannte offene Punkte

- **Keine reale WordPress-Testumgebung** mit Avada, CF7 und SEO-Plugin. Getestet wurde gegen eine Wegwerf-Instanz (WordPress 7.1.2, ohne Avada). Avada-, CF7- und SEO-Integration gelten erst mit realer Testumgebung als verifiziert.
- Der API-Key hat nur Leserechte auf Objekte, Status und Projekte. Webhooks (`POST /v1/hooks`) muss ein Propstack-Admin registrieren.
- WP-Cron hängt von Seitenaufrufen ab; für verlässliche Intervalle einen System-Cron einrichten ([sync.md](sync.md)).
- Einstellungen „Status verkauft/vermietet“ und „Reserviert“ müssen im Backend noch fachlich gesetzt werden (Default: leer).
- Zentraler Fallback-Ansprechpartner (Einstellung) ist für Phase 3 geplant.
- `picaflor.code-workspace` verweist auf einen nicht existierenden Ordner `wp-plugin` (kosmetisch).
