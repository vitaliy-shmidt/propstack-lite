# Immobilienübersicht: Suche, Filter, Sortierung, Pagination

Stand: Phase 7 (2026-10-07, Plugin-Version 0.6.0). Alle Daten kommen aus `{prefix}psl_properties` – **kein Besucher-Request löst einen Propstack-Request aus** (per Integrations- und HTTP-Test nachgewiesen).

## Überblick

```
GET /immobilien/?marketing_type=buy&city=Berlin&seite=2
  └─ WordPress-Seite mit [propstack_list]
       ├─ Frontend\ListingConfig   Shortcode-Attribute → feste Einschränkungen, Standards, Schalter
       ├─ Frontend\ListingRequest  GET-Parameter → Whitelist/Validierung → PropertySearchCriteria
       ├─ Frontend\ListingService  Request-Cache, Settings (öffentliche Status)
       │    └─ Storage\PropertyStore::search() / filterOptions()
       │         └─ Storage\SearchQueryBuilder (WHERE/ORDER BY, nur Platzhalter + feste Spalten)
       ├─ Frontend\Pagination      Seitenzahl, Vor/Zurück, Seitenliste
       └─ templates/list.php + parts/list-filters.php, parts/card.php, parts/list-pagination.php

<head> (vor dem Shortcode):
  Frontend\ListingContext (erkennt die Listenseite) → Seo\SeoContext::listing() → Seo\SeoService::forListing()
  → Core-/Yoast-/Rank-Math-Adapter (Canonical, Robots, Seitenzusatz im Title) + Header X-Robots-Tag
```

Kein JavaScript nötig: Das Formular ist ein normales `<form method="get">`, Pagination sind Links. Teilbare URLs, Zurück/Vor im Browser funktioniert, kein Zustand in POST, Cookies oder LocalStorage.

## URL-Parameter (Whitelist)

Zentral definiert in `Frontend\ListingRequest::PARAMS`. Alle anderen Parameter (z. B. `utm_*`, `gclid`, `fbclid`, `status`, `orderby`) werden **ignoriert**; ungültige Werte gelten als „nicht gesetzt“ (keine Fehlermeldung, kein Warning).

| Parameter | Werte | Wirkung | Validierung |
|---|---|---|---|
| `marketing_type` | `buy`, `rent` | Kaufen / Mieten (`BUY`/`RENT` im Modell; im Frontend nur „Kaufen“/„Mieten“) | Whitelist, Groß-/Kleinschreibung egal |
| `property_type` | `apartment`, `house`, `plot`, `commercial`, `investment`, `parking` | Objektart-Gruppe (siehe unten) | Whitelist aus `FieldCatalog::TYPE_GROUPS` |
| `city` | Ortsname | Ort (exakt, Groß-/Kleinschreibung egal) | Buchstaben/Ziffern/Leerzeichen/`.,'’()/-`, max. 100 Zeichen, Leerraum normalisiert |
| `price_min`, `price_max` | ganze Zahl ≥ 1 | Kaufpreis bzw. **Kaltmiete** (`search_price`) | nur Ziffern, ≤ 9.999.999.999; vertauschte Grenzen werden getauscht |
| `living_space_min` | ganze Zahl ≥ 1 | Hauptfläche (Wohnfläche, sonst Fläche) | nur Ziffern, ≤ 999.999 |
| `plot_area_min` | ganze Zahl ≥ 1 | Grundstücksfläche | wie oben; Feld erscheint nur, wenn ein öffentliches Objekt eine Grundstücksfläche hat |
| `rooms_min` | `1`–`5` | Zimmer ab (2,5 Zimmer zählt zu „2+“) | Whitelist |
| `sort` | siehe Sortierung | Sortierung | Whitelist `ListingRequest::SORT_LABELS` |
| `per` | `12`, `24`, `48` | Treffer pro Seite | Whitelist (der Shortcode darf 1–100 als Standard setzen) |
| `seite` | `1`–`1000` | Seitennummer | nur Ziffern; Seite 1 erscheint nie in der URL |

Arrays (`city[]=…`, `seite[]=1`), Objekte, negative Zahlen, Dezimal-/Exponentenschreibweise, überlange Werte, SQL- oder HTML-Fragmente werden verworfen.

### Warum `seite` statt `page`

`page` ist eine öffentliche WordPress-Query-Variable (Paginierung von Seiteninhalten mit `<!--nextpage-->`). Nachgewiesen in der Testinstanz: `/immobilien/?page=2` wird von WordPress per **301 auf `/immobilien/`** umgeleitet (Pagination ginge verloren), und die WordPress-eigene Form `/immobilien/2/` gehört dem Detail-Router (→ 404). Ein eigener, nicht reservierter Parameter ist robust gegenüber WordPress, Yoast, Rank Math und Page-Buildern. `paged` scheidet aus denselben Gründen aus.

## Filter

### Kaufen / Mieten
Optionen nur für tatsächlich vorhandene Vermarktungsarten; das Feld entfällt, wenn nur eine existiert (außer sie ist gewählt).

### Objektart (`FieldCatalog::TYPE_GROUPS`)

| URL-Wert | Bezeichnung | `rs_type` |
|---|---|---|
| `apartment` | Wohnung | `APARTMENT` |
| `house` | Haus | `HOUSE` |
| `plot` | Grundstück | `TRADE_SITE` |
| `commercial` | Gewerbe | `OFFICE`, `STORE`, `GASTRONOMY`, `INDUSTRY`, `SPECIAL_PURPOSE` |
| `investment` | Anlageobjekt | `INVESTMENT` |
| `parking` | Stellplatz/Garage | `GARAGE` |

Angezeigt werden nur Gruppen mit mindestens einem öffentlichen Objekt. Seltene/technische Arten (Ferienwohnung `SHORT_TERM_ACCOMODATION`, WG-Zimmer) haben keine eigene Option, erscheinen aber in der ungefilterten Liste.

### Ort
Optionen per `SELECT … GROUP BY` über die Spalte `city` der **öffentlichen** Objekte (inkl. fester Shortcode-Einschränkungen): getrimmt, Leerraum normalisiert, leere Werte entfallen, Schreibvarianten („berlin“/„Berlin“) zusammengefasst, alphabetisch (Umlaute wie Grundbuchstaben). Straße/Hausnummer sind nie Teil der Spalte. Keine Freitextsuche. Ein per Link übergebener Ort ohne aktuelle Objekte bleibt im Formular sichtbar (Leerzustand statt stillem Ignorieren).

### Preis
Filter und Preissortierung nutzen die Spalte **`search_price`** (Schema v2), berechnet beim Sync aus `Property::searchPrice()`:

| Vermarktung | `search_price` |
|---|---|
| Kauf | Kaufpreis (`price`) |
| Miete | **Kaltmiete (`base_rent`)** – Warmmiete (`total_rent`) zählt bewusst nicht |
| „Preis auf Anfrage“ (`price_on_inquiry`) | `NULL` |
| kein/0-Preis | `NULL` |

Folgen: Bei aktivem Preisfilter fallen Objekte ohne `search_price` heraus (nie als 0 € behandelt; ein intern hinterlegter, aber verborgener Preis ist über Filter nicht erratbar). In Preissortierungen stehen sie am Ende. Mietobjekte mit nur einer Warmmiete sind nicht preisfilterbar. Beschriftung: „Kaufpreis von/bis“, „Kaltmiete von/bis“ bzw. ohne gewählte Vermarktung „Preis von/bis“ mit Hinweis „Kaufpreis bzw. Kaltmiete pro Monat“ (ohne Vermarktungsart gilt der Preisfilter für beide Basen gleichzeitig). `price_min=0` schränkt nicht ein.

### Fläche, Zimmer
Numerische Vergleiche auf `decimal`-Spalten (`living_space >= %f`, `rooms >= %f`), keine String-Vergleiche. `living_space` ist die Hauptfläche (Wohnfläche, bei Gewerbe die allgemeine Fläche).

### Nicht umgesetzt (bewusst)
Stadtteil-Filter (Datenqualität `district` uneinheitlich), Option „Reservierte ausblenden“ (reservierte Objekte erscheinen mit Badge „Reserviert“), Freitextsuche.

## Sortierung

Whitelist `PropertySearchCriteria::SORTS` (Spalten sind feste Literale, nie aus der URL). Jede Sortierung endet mit dem eindeutigen Tie-Breaker `propstack_id` in Sortierrichtung, NULL-Werte stehen immer am Ende → stabile Pagination.

| `sort` | Bezeichnung | ORDER BY |
|---|---|---|
| `newest` (Standard) | Neueste zuerst | `remote_created_at IS NULL, remote_created_at DESC, propstack_id DESC` |
| `price_asc` / `price_desc` | Preis auf-/absteigend | `search_price IS NULL, search_price ASC/DESC, propstack_id ASC/DESC` |
| `area_asc` / `area_desc` | Wohnfläche auf-/absteigend | `living_space …` |
| `rooms_desc` | Zimmer absteigend | `rooms …` |
| `updated` | Zuletzt aktualisiert | `content_changed_at DESC, propstack_id DESC` |
| nur per Shortcode: `oldest`, `rooms_asc`, `city_asc` | | |

**Standard „Neueste zuerst“ = `remote_created_at`** (bisher dokumentierte Default-Sortierung). Bewusst nicht `content_changed_at`: Dieser Zeitstempel ändert sich bei jeder sichtbaren Änderung (Foto, Preis) und würde die Standardreihenfolge nach jedem Sync umwürfeln; er steht als „Zuletzt aktualisiert“ zur Wahl. `remote_updated_at` wird nie verwendet (interne CRM-Änderungen). Gemischte Liste (Kauf + Miete) nach Preis: Mieten (Kaltmiete) stehen vor Kaufpreisen.

## Pagination

- Standard 12 pro Seite (Shortcode `per`, 1–100), Besucher wählen nur 12/24/48 (`per`).
- Gesamtzahl über eine `COUNT(*)`-Abfrage mit derselben WHERE-Bedingung; geladen und dekodiert werden nur die Zeilen der Seite (`LIMIT/OFFSET`). Liegt der Offset hinter der Trefferzahl, entfällt die Seitenabfrage.
- Links behalten alle aktiven Filter, Sortierung und Seitengröße in kanonischer Reihenfolge (`?marketing_type=buy&city=Berlin&seite=2`); Tracking-Parameter werden nicht weitergetragen (die Attribution steckt seit Phase 6 im Cookie).
- Navigation: `<nav aria-label="Seitennavigation Immobilien">`, „Zurück“/„Weiter“ (`rel="prev/next"`), erste/letzte Seite, Nachbarn der aktuellen Seite, Auslassungen „…“, `aria-current="page"`.
- Seite hinter der letzten Seite (alter Link): HTTP 200, Hinweis „Diese Ergebnisseite ist nicht (mehr) vorhanden.“ + Link zur ersten Ergebnisseite, `noindex, follow`.
- Neue Suche (Formular) beginnt immer auf Seite 1.

## Formular und UX

- `<form method="get" role="search" aria-label="Immobiliensuche">` mit `action` = Permalink der Seite (ohne Pretty Permalinks werden `page_id` & Co. als Hidden Fields übernommen).
- Jedes Feld mit echtem `<label for>` und eindeutiger ID je Instanz (`psl-list-{n}-{param}`); Zahlenfelder `type="number" inputmode="numeric" min="1"`, Einheit (€/m²) visuell, Hinweis per `aria-describedby`.
- Felder erscheinen nur, wenn es sinnvolle Optionen gibt (mind. zwei, außer ein Wert ist gesetzt) und die Eigenschaft nicht fest per Shortcode vorgegeben ist.
- Buttons „Immobilien anzeigen“ (Filter) und „Sortieren“ (Sortierung/Seitengröße), Link „Filter zurücksetzen“ → Seite ohne Query-Parameter (erscheint bei abweichender Ansicht und im Leerzustand).
- Nach Reload/Zurück zeigt das Formular die validierten Werte aus der URL.
- Trefferanzahl: „24 Immobilien gefunden“ / „1 Immobilie gefunden“, mit Filtern „24 Immobilien entsprechen Ihren Filtern.“; bei mehreren Seiten zusätzlich „Seite 2 von 3“.
- Leerzustand: „Für diese Filter wurden keine Immobilien gefunden.“ + „Filter zurücksetzen“. Ganz ohne öffentliche Objekte (und ohne Filter) wie bisher nur „Derzeit sind keine passenden Immobilien verfügbar.“ Kein API-Fallback.
- **Mobil:** Die Filter liegen in `<details open>` mit `<summary>Filter (n aktiv)</summary>` – nativ per Tastatur/Screenreader bedienbar. Ohne JavaScript immer geöffnet; ein Inline-Skript direkt nach dem Panel klappt es unter 768 px ohne aktive Filter **vor dem ersten Rendern** ein (seit 0.9.0; vorher in `psl-list.js` per `defer` → Layout-Sprung, Lighthouse mobil CLS 0,284; jetzt CLS 0). Ausgabe über `wp_print_inline_script_tag()` (CSP-Nonce-Filter von WordPress greifen).
- **`assets/js/psl-list.js`** (Progressive Enhancement, 2,0 KB / 1,1 KB gzip, `defer`, nur mit Formular geladen): sendet leere und Standardwerte nicht mit (kurze URLs; ohne JS enthält die URL leere Parameter, die der Server ignoriert). **Kein Auto-Submit** bei Auswahländerung (WCAG 3.2.2 „Bei Eingabe“), keine Requests, keine Speicherung.

## Karten

`templates/parts/card.php`: Bild, Badges Objektart / Kauf bzw. Miete / „Reserviert“, Titel (Heading-Level per Shortcode), Ort, Preis, Wohnfläche (bzw. Fläche), Zimmer, CTA „Details ansehen“ (`aria-label` mit Objekttitel). Keine Duplikation der Detailseite.

- **Adressschutz:** `Address::publicLabel()` – bei `hide_address` nur PLZ, Ort, Stadtteil; Straße/Hausnummer/Koordinaten sind dann gar nicht gespeichert.
- **Bild:** erstes öffentliches Nicht-Grundriss-Bild (`Property::mainImage()`; private/gesperrte Bilder hat der Mapper verworfen), nur HTTPS. Ohne Bild neutraler Inline-SVG-Platzhalter „Kein Bild verfügbar“ (kein externer Dienst).
- **Performance:** Größe `medium` (Rahmen 600 × 450), `width="600" height="450"` + CSS `aspect-ratio: 4/3` (gemessen CLS 0), erste drei Karten `eager`, erstes Bild `fetchpriority="high"`, übrige `loading="lazy"`, `decoding="async"`. **Bewusst ohne `srcset`:** Propstack liefert zwischen `medium` (600 px) und `big` (1920 px) keine Größe; ein `srcset` mit `big` ließe HiDPI-Smartphones für jede Karte das ~3-fach breite Bild laden. Es wird nur ein Bild je Objekt geladen.
- Preiszeile: nie „0 €“; „Preis auf Anfrage“ hat Vorrang (seit 0.6.0 auch bei Mietobjekten).

## Shortcode `[propstack_list]`

| Attribut | Standard | Bedeutung |
|---|---|---|
| `per` (Alias `limit`) | `12` | Treffer pro Seite (1–100) |
| `heading` | `h3` | Überschriftenebene der Kartentitel (`h2`–`h4`; `h1` wird nie erzeugt). Steht die Liste direkt unter der Seiten-H1, `heading="h2"` setzen. |
| `show_filters` | `1` | Filterformular anzeigen und Filter aus der URL lesen |
| `show_sort` | `1` | Sortierung/Seitengröße anzeigen und aus der URL lesen |
| `pagination` | `1` | Seitennavigation und `seite` aus der URL |
| `sort` | `newest` | Standardsortierung (alle Schlüssel der Whitelist) |
| `marketing_type` | – | **fest**: `BUY`/`RENT` |
| `property_type` bzw. `rs_type` | – | **fest**: Gruppe bzw. einzelnes `rs_type` |
| `city`, `zip_code` | – | **fest** |
| `price_from`/`price_to` (Alias `min_price`/`max_price`) | – | **feste** Preisgrenzen (auf `search_price`) |
| `page`, `sort_by`, `order` | – | Rückwärtskompatibel (vor Phase 7); `page` wirkt nur bei `pagination="0"` |

- Feste Einschränkungen können Besucher weder aufheben noch überschreiben; das zugehörige Feld entfällt. Besucher können feste Preisgrenzen nur enger machen.
- `show_filters="0" show_sort="0" pagination="0"` = statische Liste wie vor Phase 7 (z. B. Teaser auf der Startseite); sie liest **keine** URL-Parameter.
- Ausgeblendete Teile werden nicht aus der URL übernommen (z. B. `show_filters="0"` → `?city=` wirkungslos).
- Es gibt kein `status`-Attribut und keinen Parameter, der nicht-öffentliche, verkaufte oder entfernte Objekte freischaltet. Unsichere Query-Passthroughs der 0.2.x-Zeit bleiben entfernt.
- Mehrere interaktive Listen auf einer Seite teilen sich dieselben URL-Parameter – pro Seite nur eine interaktive Liste einsetzen.
- **Hinweis Live-Site:** Bestehende Einbindungen werden ab 0.6.0 automatisch interaktiv – Anpassungstabelle in [release-1.0.md](release-1.0.md#sichtbare-änderung-seit-060--shortcode-anpassung-beim-live-update). Teaser-Einbindungen außerhalb von `/immobilien/` auf `show_filters="0" show_sort="0" pagination="0"` umstellen; auf `/immobilien/` ein sehr hohes `per` (Behelf vor Phase 7) wieder auf 12 setzen.

## SEO-Verhalten

Die Übersicht ist eine normale WordPress-Seite: Title, Description und Open Graph bleiben bei WordPress bzw. dem SEO-Plugin (nicht pro Filterkombination aufgebläht). Das Plugin ergänzt nur – berechnet in `SeoService::forListing()`, ausgegeben vom aktiven Adapter (Yoast > Rank Math > Core):

| Ansicht | Robots | Canonical | Title |
|---|---|---|---|
| `/immobilien/` (ungefiltert) | unverändert (index, follow) | `/immobilien/` | unverändert |
| `/immobilien/?seite=2` (nur Pagination) | unverändert (index, follow) | **selbstreferenzierend** `/immobilien/?seite=2` (nicht Seite 1) | „… – Seite 2“ (Core: Title-Teil `page`) |
| Filter aktiv | `noindex, follow` + Header `X-Robots-Tag: noindex, follow` | selbstreferenzierend, normalisiert | wie oben |
| abweichende Sortierung oder Seitengröße | `noindex, follow` + Header | selbstreferenzierend | wie oben |
| Seite hinter der letzten | `noindex, follow` + Header | selbstreferenzierend | wie oben |
| mit `utm_*`/`gclid`/`fbclid` | wie ohne | **ohne** Tracking-Parameter | wie ohne |

- **Normalisierte URL:** nur validierte Parameter, feste Reihenfolge (`ListingRequest::PARAMS`), ohne Standardwerte und ohne Seite 1. Ungültige Parameter erzeugen daher den Canonical der bereinigten Ansicht.
- Das Plugin **stuft nur herab**: Eine redaktionelle noindex-Einstellung der Seite oder „Suchmaschinen davon abhalten“ wird nie auf index gehoben.
- **Yoast** gibt auf noindex-Seiten grundsätzlich keinen Canonical aus (Yoast-Verhalten) – bei Filterseiten erscheint dort also nur `noindex, follow`.
- **Konflikt (Yoast + Rank Math):** Werte gehen an Yoast; Rank Math erhält per Sicherheitsnetz denselben Canonical und noindex.
- Header `X-Robots-Tag` wirkt auch mit nicht unterstützten SEO-Plugins.
- Filterseiten erscheinen nicht in der Sitemap (Sitemap enthält weiterhin nur Detailseiten; die Übersicht selbst listet das SEO-Plugin bzw. WordPress als Seite).
- Filter `psl_listing_seo_data` (ListingSeoData, ListingRequest) erlaubt Anpassungen, z. B. künftig ausgewählte Filterseiten indexierbar zu machen – **nicht** ohne Freigabe aktivieren.

Erkennung der Listenseite im `<head>` (`Frontend\ListingContext`): Einzelseite/-beitrag, deren Inhalt `[propstack_list]` enthält (auch verschachtelt in Builder-Shortcodes), oder deren Permalink der Übersichts-URL (`psl_overview_url`) entspricht. Für Builder, die Shortcodes kodiert speichern (z. B. Base64-Code-Blöcke), liefert der Filter `psl_listing_page_atts` (`array|null $atts, WP_Post $post`) die Attribute. Statische Listen gelten nicht als Suchseite.

## Security

- **Whitelist** aller Parameter und Werte (`ListingRequest`), zusätzliche Bereichsprüfung im Konstruktor von `PropertySearchCriteria` (Defense in Depth).
- **SQL:** alle Werte über `$wpdb->prepare` (`%s/%d/%f`); `ORDER BY` ausschließlich aus `PropertySearchCriteria::SORTS` (Spaltenliterale); keine Spalte aus der URL. Unit-Test stellt sicher, dass kein Wert im SQL-Text landet.
- **Sichtbarkeit:** `state = 'active' AND data IS NOT NULL AND status_id IN (öffentliche Status)` ist Teil jeder Such-, Count- und Optionsabfrage; ohne öffentliche Status keine Abfrage.
- **XSS:** alle Ausgaben escaped (`esc_html`, `esc_attr`, `esc_url`); GET-Werte erscheinen nur validiert (Ort nach Zeichen-Whitelist) und escaped im Formular.
- Ungültige Typen (Arrays, Objekte) erzeugen keine Warnings (`debug.log` im HTTP-Test geprüft).
- Keine Speicherung von Suchanfragen, keine Logs mit Filterwerten, keine Cookies.

## Query-Architektur und Indizes

`PropertyStore::search(publicStatusIds, PropertySearchCriteria): PropertySearchResult` (Items der Seite, Gesamtzahl, Kriterien; `pages()`, `isOutOfRange()`), `PropertyStore::filterOptions(publicStatusIds, baseCriteria)` (eine `GROUP BY marketing_type, rs_type, TRIM(city)`-Abfrage, kein JSON-Dekodieren). `queryPublic(ListCriteria)` bleibt als rückwärtskompatible Hülle (übersetzt in `PropertySearchCriteria`). `ListingService` merkt sich Ergebnisse pro Request, sodass Head (SEO) und Shortcode dieselbe Abfrage teilen.

**Lasttest (2026-10-07, finales Schema v2, MariaDB 10.4, PHP 8.3, 500 synthetische Objekte, davon 449 öffentlich; Median aus 25 Läufen):**

| Abfrage | Treffer | COUNT | Seite (SELECT) | `search()` inkl. Hydration | Zugriff (EXPLAIN) |
|---|---|---|---|---|---|
| Standard, Neueste, Seite 1 | 449 | 1,93 ms | 2,43 ms | 7,64 ms | `ref` state_status |
| Kauf, Preis aufsteigend | 274 | 1,84 ms | 1,42 ms | 3,68 ms | `ref` type_idx |
| Kauf + Wohnung + Berlin + ≤ 500.000 € + 3 Zi. | 1 | 0,49 ms | 0,62 ms | 1,20 ms | `ref` city_idx |
| Miete + ≥ 80 m², Fläche absteigend, Seite 3 | 143 | 1,00 ms | 1,38 ms | 3,16 ms | `ref` type_idx |
| Gewerbe (IN-Liste) | 42 | 2,09 ms | 2,17 ms | 5,06 ms | `ref` state_status |
| Seite 30, Zuletzt aktualisiert | 449 | 1,32 ms | 5,64 ms | 8,27 ms | `ref` state_status |
| Kein Treffer | 0 | 0,20 ms | 0,33 ms | 0,25 ms | `ref` city_idx |
| Filteroptionen (GROUP BY) | – | – | 3,63 ms | – | `ref` state_status |

Werte schwanken zwischen Läufen um ±1–2 ms (lokaler XAMPP-Server); der erste Messlauf mit `price_idx` lag in derselben Größenordnung. Keine Full Table Scans (alle Muster `type=ref`). Sortierung per Filesort über die gefilterte Menge (≤ 449 Zeilen). **Indexentscheidung:** keine neuen Indizes. Die bestehenden `state_status`, `type_idx (marketing_type, rs_type)` und `city_idx` decken die Muster ab; ein Index kann bei Abfragen, die ~90 % der Zeilen treffen, nichts einsparen, und Composite-Indizes für Sortierungen scheitern an „NULL zuletzt“ und wechselnden Filterkombinationen. Getestete Alternative „Deferred Join“ (erst IDs sortieren, dann Daten laden) war in MariaDB 10.4 durchweg langsamer (+3–4 ms durch Materialisierung) und wurde verworfen. Entfernt: `price_idx (price)` (seit `search_price` ungenutzt). Bei deutlich größerem Bestand (> 5.000 Objekte) neu messen.

Messskript (nur Testumgebung, nicht im Repository): 500 Objekte mit festem Zufalls-Seed (60 % Kauf, 8 Objektarten, 15 Orte, 5 % Preis auf Anfrage, 5 % reserviert, 5 % nicht öffentlich, verkauft/entfernt), danach Tabelle geleert.

## Hooks

| Hook | Typ | Zweck |
|---|---|---|
| `psl_listing_page_atts` | Filter | Shortcode-Attribute der Listenseite für die SEO-Erkennung (`array|null`, `WP_Post`) |
| `psl_listing_seo_data` | Filter | `ListingSeoData` anpassen |
| `psl_template_vars` | Filter | Template-Variablen (auch `list.php`, `parts/list-filters.php`, `parts/list-pagination.php`) |
| `psl_overview_url` | Filter | Übersichts-URL (auch für die Erkennung) |

## Templates (Theme-Override unter `{theme}/propstack-lite/`)

`list.php`, `parts/list-filters.php`, `parts/card.php`, `parts/list-pagination.php`. Feldnamen im Formular müssen den Parametern der Whitelist entsprechen. CSS `assets/css/psl-list.css` nur mit `.psl-list …`-Selektoren; Typografie, Farben, Formularfelder und Buttons erbt das Theme (Avada-Klassen werden nicht vorausgesetzt; Buttons sind native `<button>` mit Klasse `psl-button`). Touch-Ziele ≥ 44 px.
