# Routing und SEO

> **Status:** Nur die URL-Erzeugung ist implementiert (`Frontend\UrlGenerator`, `Support\Slugger`). Router, Detailseite und SEO-Ausgaben sind **Geplant (Phase 2 / Phase 5)**. Bis Phase 2 liefern Detail-Links **404**.

## URLs

| URL | Bedeutung | Status |
|---|---|---|
| `/immobilien/` | Übersicht – normale WordPress-Seite (in Avada gestaltet) mit `[propstack_list]` | Seite manuell anlegen; Shortcode implementiert |
| `/immobilien/{slug}-{id}/` | dynamische Detailseite, keine WordPress-Seite pro Objekt | **Geplant (Phase 2)**; Links werden bereits so erzeugt (absolut über `home_url()`) |
| `/immobilien/{id}/` | Kurzform | **Geplant** → 301 auf kanonische URL |
| `/immobilie/…` (alt, 0.2.x) | Legacy | **Geplant** → 301 auf kanonische URL, sofern eine ID ermittelbar ist. Alte Rewrite-Regeln wurden in Phase 1 entfernt |

## Slug-Regeln (implementiert)

`Slugger::forProperty()`: `{zimmer}-zimmer-{objektart}-{kaufen|mieten}-{ort}-{ortsteil}`, z. B. `2-zimmer-wohnung-kaufen-berlin-mitte`.
- Teile entfallen, wenn Daten fehlen; Ortsteil entfällt, wenn im Ort enthalten; Fallback `immobilie`.
- Umlaute deterministisch (ä → ae, ß → ss), unabhängig von der WordPress-Locale; max. 80 Zeichen.
- **Nicht** aus dem Propstack-Titel (Marketingtexte, interne Kürzel, häufige Änderungen).
- Der Slug ist dekorativ: **maßgeblich ist die ID**. Ändert sich der Slug, leitet die alte URL per 301 um – es entsteht kein Duplicate Content.

## Antwortverhalten (Geplant, Phase 2)

| Fall | Erkennung (Store) | Antwort |
|---|---|---|
| gültig und öffentlich | `active`, Status öffentlich | **200**, `index,follow` |
| reserviert | `active`, Status öffentlich + „Reserviert“ | **200**, indexierbar, Badge „Reserviert“, Formular aktiv |
| falscher/alter Slug | ID gültig, Slug ≠ aktuell | **301** → kanonische URL |
| verkauft/vermietet ≤ 30 Tage | `sold`, `sold_at` ≥ jetzt − 30 Tage | **200**, `noindex,follow`, Hinweis „Verkauft“/„Vermietet“, kein Formular (Link zu ähnlichen Objekten), ähnliche Objekte, nicht in Liste/Sitemap |
| verkauft/vermietet > 30 Tage | `sold`, älter | **410**, hilfreiche Seite (ähnliche Objekte, Link zur Übersicht, Kontakt) |
| entfernt (Status nicht öffentlich, archiviert, gelöscht) | `removed` | **410**, wie oben |
| aktiv gespeichert, Status inzwischen nicht mehr öffentlich (Einstellung geändert) | `active`, Status nicht öffentlich | **410** bis zum nächsten Sync (dann `removed`) |
| unbekannte ID | keine Zeile | **404** (Theme-404), kein API-Call |

Warum 410 statt Redirect auf die Übersicht: Massen-Redirects auf eine Übersicht wertet Google als Soft-404; 410 signalisiert dauerhafte Entfernung und wird schneller deindexiert.

## SEO-Konzept (Geplant, Phase 5)

| Element | Regel |
|---|---|
| `<title>` | `{Zimmer}-Zimmer-{Objektart} {kaufen|mieten} in {Ort/Ortsteil} | Picaflor Immobilien`, Fallbacks ohne Zimmer/Ort, ca. 60 Zeichen. Propstack-Titel nur als H1 (escaped) |
| Meta Description | aus Objektart, Vermarktung, Ort, Zimmer, Fläche, Preis bzw. „Preis auf Anfrage“, max. 2 Merkmale; ≤ 155 Zeichen |
| Canonical | absolute URL aus `UrlGenerator`, ohne Query-Parameter |
| Robots | aktiv: `index,follow`; verkauft-Phase und 410: `noindex`; gefilterte Übersicht: `noindex,follow`; Pagination: index mit Self-Canonical |
| Open Graph | `og:type=website`, `og:title`, `og:description`, `og:url`, `og:image` (erstes öffentliches Nicht-Grundriss-Bild), `og:locale=de_DE`, `twitter:card=summary_large_image` |
| JSON-LD | `RealEstateListing` (`url`, `name`, `datePosted`, `image`), `offers` → `Offer` (`price`, `priceCurrency=EUR`, Miete per `UnitPriceSpecification`), `about` → `Apartment`/`House`/`Place` mit `numberOfRooms`, `floorSize`, `address` → `PostalAddress` (bei verborgener Adresse nur PLZ/Ort), `geo` nur bei freigegebener Adresse. **Nur vorhandene Werte**, keine erfundenen Felder |
| Sitemap | eigener Provider mit `lastmod = content_changed_at`, nur öffentliche aktive Objekte; Adapter für WordPress-Core (`wp_sitemaps_add_provider`), Yoast, Rank Math |
| SEO-Plugins | `Seo\SeoService` + Adapter (Core/Yoast/Rank Math); nie doppelte Tags. Welches SEO-Plugin live aktiv ist, ist **nicht verifiziert** |
