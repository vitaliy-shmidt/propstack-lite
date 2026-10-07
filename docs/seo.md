# SEO für Immobilien-Detailseiten

> **Immobilienübersicht (Phase 7):** Canonical, Robots und Seitenzusatz der Seite mit `[propstack_list]` (Pagination, Filter, Sortierung) – berechnet in `SeoService::forListing()`, ausgegeben über dieselben Adapter – beschreibt [listing.md](listing.md#seo-verhalten).

Stand: Phase 5 (2026-10-06), **implementiert**; RC-Abnahme 2026-10-06 (0.4.1) mit voll konfiguriertem Yoast und Rank Math. Getestet in einer Wegwerf-Instanz (WordPress 7.1.2 unter `/Picaflor/`) mit **Yoast SEO 28.6** und **Rank Math 1.0.279** – jeweils einzeln, gemeinsam und ohne SEO-Plugin. Nicht gegen die Live-Site (Avada) verifiziert.

Grundsatz: Alle SEO-Werte entstehen **an einer Stelle** (`Seo\SeoService`) aus dem lokalen Store. Ausgegeben werden sie je nach Umgebung von genau einem Adapter. Kein Besucher-Request – auch kein Sitemap-Abruf – löst einen Propstack-Request aus.

## Architektur

```
Seo\SeoService        reine Logik (ohne WordPress testbar): Title, Description, Robots, OG, Twitter, JSON-LD
Seo\SeoData           unveränderliches Ergebnis (Rohwerte, unescaped)
Seo\SeoContext        SeoData des laufenden Requests (einmal berechnet), Filter psl_seo_data
Seo\SeoPlugins        Erkennung aktiver SEO-Plugins, Priorität Yoast > Rank Math > Core
Seo\SeoIntegration    registriert genau einen Adapter + Sitemap-Provider + Cache-Invalidierung
Seo\CoreAdapter       ohne SEO-Plugin: gibt alle Tags selbst aus
Seo\YoastAdapter      Werte über Yoast-Filter, Yoast gibt aus
Seo\RankMathAdapter   Werte über Rank-Math-Filter, Rank Math gibt aus
Seo\Sitemap\SitemapSource            indexierbare Objekte aus dem Store (URL = Canonical)
Seo\Sitemap\CoreSitemapProvider      WP_Sitemaps_Provider „propstack“
Seo\Sitemap\YoastSitemapProvider     WPSEO_Sitemap_Provider (nur geladen, wenn Yoast aktiv)
Seo\Sitemap\RankMathSitemapProvider  RankMath\Sitemap\Providers\Provider (nur geladen, wenn Rank Math aktiv)
Seo\Sitemap\SitemapCache             invalidiert Yoast-/Rank-Math-Sitemap-Caches nach Sync/Einstellungsänderung
```

Der `DetailController` gibt seit Phase 5 keine Head-Tags mehr aus (Title, Canonical, Robots wurden in die SEO-Schicht verschoben). Er setzt weiterhin Statuscodes, 301-Kanonisierung und den HTTP-Header `X-Robots-Tag: noindex, follow` (Verkauft-Phase, 410) – der Header wirkt unabhängig von jedem SEO-Plugin.

## Werte

### Title

`{n}-Zimmer-{Objektart} {kaufen|mieten} in {Ort}-{Ortsteil} | {Marke}`, z. B. „3-Zimmer-Wohnung kaufen in Berlin-Charlottenburg | Picaflor Immobilien“.

- Objektart übersetzt (`Formatter::typeLabel`, Fallback „Immobilie“); Vermarktungsart `BUY` → „kaufen“, `RENT` → „mieten“, sonst ohne Verb.
- Zimmer nur bei Wohnimmobilien (Wohnung, Haus, Ferienwohnung) und wenn vorhanden („2,5-Zimmer-…“).
- Ort/Ortsteil aus dem Address-Modell; Ortsteil entfällt, wenn im Ort enthalten. **Nie Straße/Hausnummer** – bei `hide_address` speichert der Mapper sie gar nicht erst.
- Marke = WordPress-Website-Titel (`get_bloginfo('name')`), Filter `psl_seo_brand`.
- Länge max. 70 Zeichen inkl. Marke (das Beispiel der Vorgabe passt genau). Kürzung schrittweise ohne Wortschnitt: erst Ortsteil, dann Zimmer; nur wenn auch das nicht reicht, wortweise gekürzt. Keine Keyword-Wiederholungen.
- **H1 bleibt der Propstack-Titel** (Template), der SEO-Title wird nur im `<title>`/OG/Twitter verwendet.
- 410: „Immobilie nicht mehr verfügbar | {Marke}“.

### Meta Description

„Wohnung zum Kauf in Berlin-Mitte: 2 Zimmer, ca. 61,1 m² Wohnfläche, Kaufpreis 354.200 €, mit Balkon/Terrasse. Jetzt weitere Informationen anfragen.“

- Bausteine: Objektart, Kauf/Miete, Ort, Zimmer, Fläche (bzw. Grundstück), Preis (nie bei „Preis auf Anfrage“, nie „0 €“; Miete als „Kaltmiete 1.200 €/Monat“), 1–2 Merkmale, Handlungsaufforderung.
- Max. 158 Zeichen (UTF-8 über `mb_*`). Statt mitten im Satz abzuschneiden, werden ganze Bausteine weggelassen (zweites Merkmal → Handlungsaufforderung → erstes Merkmal → Grundstück → Zimmer → Fläche → Preis). Ergebnis endet immer mit einem vollständigen Satz.
- Kein HTML: alle Werte laufen durch `SeoService::plain()` (Tags und `<`/`>` entfernt, Leerraum normalisiert) – zusätzlich zur Bereinigung im Mapper.

### Canonical

Exakt `UrlGenerator::canonicalUrl()` (absolut, Unterverzeichnis-fähig, ohne Query-Parameter). UTM-, `gclid`-, `fbclid`-Parameter ändern Canonical und `og:url` nie (getestet). 404 und 410: kein Canonical.

### Robots

| Zustand | Robots | Zusätzlich |
|---|---|---|
| aktiv, reserviert (200) | `index, follow` | – |
| verkauft/vermietet ≤ 30 Tage (200) | `noindex, follow` | Header `X-Robots-Tag: noindex, follow` |
| 410 | `noindex, follow` | Header `X-Robots-Tag`; keine Weiterleitung |
| 404 | normales WordPress-/SEO-Plugin-Verhalten | – |

### Open Graph / Twitter

`og:type=website`, `og:title`, `og:description`, `og:url` (= Canonical), `og:locale=de_DE`, `og:site_name`, `og:image` + `og:image:alt`; `twitter:card=summary_large_image` mit Bild, sonst `summary`.

**Bild:** erstes Galeriebild mit gültiger HTTPS-URL. Private und `is_not_for_exposee`-Bilder verwirft bereits der Mapper; Grundrisse werden zusätzlich ausgeschlossen. URLs mit Anführungszeichen, spitzen Klammern, Leerraum oder Backslash werden verworfen. **Ohne Bild kein `og:image`.**

### JSON-LD

Ein Graph (`@context https://schema.org`) mit:

| Knoten | Inhalt |
|---|---|
| `RealEstateListing` (`{canonical}#listing`) | `url`, `name` (= H1), `description`, `datePosted`, `image` (max. 5, nur öffentliche Nicht-Grundrisse), `offers`, `about` → Objekt, `offeredBy` → Agentur, `breadcrumb` |
| `Apartment` / `House` / `Place` (`#property`) | Typ nur bei eindeutiger Objektart, sonst `Place`; `address`, `numberOfRooms` (Wohnimmobilien), `floorSize` (m², nicht bei `Place`), `geo` nur bei öffentlicher Adresse |
| `RealEstateAgent` (`{home}#psl-realestateagent`) | nur `name` (Website-Titel) und `url` (Startseite) – keine erfundenen Daten; der Makler ist **nie** `offeredBy` |
| `BreadcrumbList` (`#breadcrumb`) | Startseite → Immobilien → Objekt, **jeder Eintrag mit URL** (Google verlangt `item` außer beim letzten; der Ort hat keine eigene Seite und steht nur in der sichtbaren Breadcrumb). Nur, wenn das SEO-Plugin keine eigene liefert |

- **Offer:** Kauf `price` + `priceCurrency EUR` + `businessFunction Sell`; Miete `priceSpecification` (`UnitPriceSpecification`, `unitCode MON`, Kalt- bzw. Warmmiete) + `businessFunction LeaseOut`. **Ohne bekannten Preis kein Offer – nie `price: 0`.**
- **Verborgene Adresse:** `PostalAddress` nur mit `postalCode`, `addressLocality`, `addressRegion`, `addressCountry`; keine Straße, keine Geo-Koordinaten (getestet).
- Serialisierung im Core-Modus mit `wp_json_encode` und `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` – `</script>` kann den Block nicht beenden. In Yoast/Rank Math serialisieren die Plugins selbst; die Werte sind vorher per `plain()` markup-frei.

## Modi und Integration

**Priorität (dokumentiert, nicht konfigurierbar): Yoast SEO > Rank Math > Core.** Es wird immer genau ein Adapter registriert.

| Modus | Erkennung | Wer gibt aus? | Mechanismus |
|---|---|---|---|
| Core | kein unterstütztes SEO-Plugin aktiv | Plugin | `pre_get_document_title` (escaped), `wp_robots`, `wp_head` (Description, Canonical, OG, Twitter, JSON-LD) |
| Yoast | `WPSEO_VERSION` definiert | Yoast | `wpseo_frontend_presentation` (canonical/permalink und OG-Bilder der Presentation), `wpseo_title`, `wpseo_metadesc`, `wpseo_canonical`, `wpseo_robots_array`, `wpseo_opengraph_title/desc/url/type`, `wpseo_og_locale`, `wpseo_twitter_card_type/title/description/image`, `wpseo_schema_graph` |
| Rank Math | `RANK_MATH_VERSION` **und** Registrierung gültig/übersprungen | Rank Math | `rank_math/frontend/title|description|robots|canonical`, `rank_math/opengraph/facebook/og_title|og_description|og_locale`, `rank_math/opengraph/url|type`, Actions `rank_math/opengraph/{facebook,twitter}/add_images`, `rank_math/opengraph/twitter/card_type|twitter_title|twitter_description`, `rank_math/json_ld`, `rank_math/frontend/breadcrumb/items` |

Hinweise aus den Tests:

- **Yoast** behandelt die Route als Seitentyp „Fallback“ (kein WebPage-/BreadcrumbList-Knoten) → das Plugin ergänzt seine BreadcrumbList; gibt Yoast eine aus, wird darauf verwiesen statt dupliziert. Bei `noindex` gibt Yoast grundsätzlich **keinen Canonical** aus (Yoast-Verhalten, akzeptiert). OG-Bilder werden direkt in der Presentation gesetzt: Objektbild – oder ohne öffentliches Objektbild **keines**; Yoasts Website-Standardbild wird auf Detailseiten nicht verwendet (getestet mit gesetztem Standardbild).
- **Rank Math** lädt sein Frontend nur mit gültiger oder übersprungener Registrierung. Ohne sie gibt Rank Math nichts aus – das Plugin bleibt dann im **Core-Modus** (getestet), sonst stünden die Seiten ohne SEO-Tags da. `mainEntityOfPage` verweist auf Rank Maths WebPage. Mit aktivierten Rank-Math-Breadcrumbs lieferte Rank Math für die Route nur „Home“ – der Pfad wird deshalb über `rank_math/frontend/breadcrumb/items` übergeben (gilt für Rank Maths BreadcrumbList und dessen sichtbare Breadcrumbs). Rank Maths Standard-OG-Bild erscheint nicht zusätzlich.
- **Mehrere SEO-Plugins:** Admin-Hinweis (Dashboard, Plugins, Einstellungsseite) „Mehrere SEO-Plugins aktiv. Für Propstack-Detailseiten wird nur Yoast SEO integriert.“; die Einstellungsseite zeigt den Modus in der Box „SEO“. Das nicht integrierte Rank Math erhält keine Werte – mit einer Ausnahme als **Sicherheitsnetz**: Auf noindex-Seiten (Verkauft-Phase, 410) wird `noindex` auch an Rank Math gemeldet, damit es nie „index“ ausgibt. Doppelte Tags, die die beiden Plugins untereinander erzeugen, liegen außerhalb des Plugins (Website-Fehlkonfiguration).

Keine Dubletten (getestet je Modus): genau ein `<title>`, eine Description, ein Canonical, ein Robots-Meta, ein OG-Set (`og:title`, `og:url`, `og:image`, `og:locale`), ein `twitter:card`, ein JSON-LD-Block mit genau einem `RealEstateListing`, eindeutigen `@id`s und aufgelösten Referenzen.

## Sitemap

| Modus | Index | Objekt-Sitemap |
|---|---|---|
| Core | `/wp-sitemap.xml` | `/wp-sitemap-propstack-1.xml`, `-2.xml` … (Seitengröße `wp_sitemaps_max_urls`, Standard 2000) |
| Yoast | `/sitemap_index.xml` | `/propstack-sitemap.xml`, `/propstack-sitemap2.xml` … (Yoast-Konvention: Seite 1 ohne Nummer) |
| Rank Math | `/sitemap_index.xml` | `/propstack-sitemap.xml` bzw. bei mehreren Seiten `/propstack-sitemap1.xml`, `2.xml` … |

- Registrierung: `wp_register_sitemap_provider()` auf `wp_sitemaps_init`; Yoast über `wpseo_sitemaps_providers`; Rank Math über `rank_math/sitemap/providers`. Yoast und Rank Math schalten die Core-Sitemaps selbst ab.
- **Inhalt:** nur Objekte mit `state = active`, öffentlichem Status und gespeicherten Daten – genau die Seiten mit 200 + `index`. Nicht enthalten: Verkauft-Phase, entfernt, 410, nicht öffentliche Status, unbekannte IDs.
- `loc` = `UrlGenerator::detailUrlFor()` = Canonical (inkl. `/Picaflor/`, ohne Query-Parameter); sortiert nach Propstack-ID, paginiert per `LIMIT/OFFSET`.
- **lastmod** = `content_changed_at` (UTC; ändert sich nur, wenn sich der gespeicherte, sichtbare Inhalt ändert). Bewusst nicht `remote_updated_at`, weil Propstack diesen Zeitstempel auch bei internen CRM-Änderungen setzt (Fallback, falls leer). Core: W3C-Format; Yoast/Rank Math formatieren selbst.
- **Caches:** Rank Math cacht Sitemaps standardmäßig, Yoast optional. Nach jedem Sync mit relevanten Änderungen (Action `psl_sync_finished`) und nach dem Speichern der Plugin-Einstellungen werden die Caches invalidiert (`Seo\Sitemap\SitemapCache`). Zusätzlich leert `Support\PageCachePurger` Full-Page-Caches (WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed, WP Fastest Cache, SiteGround; Action `psl_purge_page_cache` für Server-/CDN-Caches) – sonst blieben verkaufte Objekte bis zum Cache-Ablauf in Übersicht und Sitemap (im RC-Test mit WP Super Cache nachgewiesen und behoben).

## Erweiterungspunkte

| Hook | Zweck |
|---|---|
| `psl_seo_brand` (Filter) | Marke im Title/OG/Agentur (Standard: Website-Titel) |
| `psl_seo_data` (Filter, `SeoData $data, RouteDecision $decision`) | gesamte SEO-Daten ersetzen (muss `SeoData` zurückgeben) |
| `psl_sync_finished` (Action, `SyncResult`) | nach erfolgreichem Sync (genutzt für Sitemap- und Seiten-Caches) |
| `psl_purge_page_cache` (Action, Liste geleerter Caches) | eigene Seiten-/Server-/CDN-Caches leeren, wenn sich der Bestand ändert |

## Sicherheit und Datenschutz

- Head-Ausgabe im Core-Modus: `esc_html` (Title), `esc_attr` (Meta-Inhalte), `esc_url` (Canonical, `og:url`, Bilder), JSON mit Hex-Escapes. Getestet mit `<script>`, Attribut-Ausbruch (`"><script>`), `onerror`-Bild-URL und Anführungszeichen direkt im Store (am Mapper vorbei) – in allen drei Modi nicht ausführbar, JSON-LD stets gültig.
- Keine internen CRM-Felder, keine Straße/Koordinaten bei `hide_address`, keine privaten/Exposé-ausgeschlossenen Bilder, keine Grundrisse als Teilbild.
- 0 Propstack-Requests bei Detail-, 410- und Sitemap-Aufrufen (HTTP-Spy, alle Modi).

## Bekannte Einschränkungen / nicht verifiziert

- Nicht gegen die Live-Site mit Avada und deren tatsächliche SEO-Plugin-Konfiguration getestet (z. B. Yoast-Premium, Rank-Math-Pro, abweichende Titel-/Schema-Einstellungen).
- Andere SEO-Plugins (AIOSEO, SEOPress, The SEO Framework) werden nicht erkannt; mit ihnen gibt das Plugin im Core-Modus zusätzlich eigene Tags aus → mögliche Dubletten.
- Yoast-/Rank-Math-Titelvorlagen und -Separatoren wirken nicht auf Detailseiten – die Werte des Plugins ersetzen sie vollständig.
- Die Übersichtsseite `/immobilien/` (normale WordPress-Seite) wird nicht vom Plugin optimiert; Robots für gefilterte Übersichten/Pagination folgen mit Phase 7.
- Google Rich Results Test / Schema.org-Validator wurden nicht online ausgeführt (keine öffentliche URL); validiert wurde strukturell per Test (Pflichtfelder, Typen, Referenzen, kein `price: 0`).
