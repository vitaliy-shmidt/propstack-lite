# Frontend: Detailseite, Templates, Galerie

Stand: Phase 3 (2026-10-05). Alle Daten stammen aus dem lokalen `PropertyStore` – **keine API-Requests im Besucher-Request** (per HTTP-Test nachgewiesen).

## Datenweg zur Ausgabe

```
PropertyStore::find() → RouteResolver (200/404/410) → PropertyViewModel::build() → Filter psl_property_view_model
   → single-property.php → parts/*.php (escapen alle Ausgaben)
```

- **`Frontend\Formatter`** – deutsche Formatierung (ohne WordPress testbar): `money()` („429.000 €“, „310,50 €“), `monthly()` („1.250 € / Monat“), `perSquareMeter()` („5.200 €/m²“), `area()` („82,5 m²“), `rooms()` („3 Zimmer“), `floor()` (0 → „Erdgeschoss“, n → „n. Obergeschoss“), `energyValue()` („98,4 kWh/(m²·a)“), `date()` (ISO → „15.03.2024“, deutsche Angaben/Freitext unverändert), `yesNo()`, `text()` (blendet Werte aus, die wie technische Enums aussehen, z. B. `FIRST_TIME_USE`), `enumLabel()` (Übersetzung über `FieldCatalog::ENUM_LABELS`), `displayPrice()` (Kauf-/Mietbegriffe nach `marketing_type`, sonst „Preis auf Anfrage“; `price_on_inquiry` hat Vorrang).
- **`Frontend\PropertyViewModel`** – präsentationsfertige Daten, unescaped; leere Werte/Gruppen sind entfernt. Wichtige Schlüssel: `breadcrumb`, `displayPrice`, `keyFacts`, `gallery`, `mainImage`, `floorplans`, `factGroups` (`costs`, `areas`, `building`), `features`, `texts` (`description`, `furnishing`, `location`, `other`), `energy`, `commission`, `agent`, `contact`, `statusBadge`, `location` (öffentliche Adresse), `has*Section`-Flags. Interne Modellfelder (`publicExposeUrl`, `unitId`, `statusId` …) sind nicht enthalten.

## Template-Hierarchie und Teile

`templates/single-property.php` (Override: `{theme}/propstack-lite/single-property.php`) ruft die Teile in dieser Reihenfolge auf. Jeder Teil ist einzeln überschreibbar unter `{theme}/propstack-lite/parts/{name}.php` und gibt **nichts** aus, wenn keine Daten vorhanden sind.

| Reihenfolge | Teil | Inhalt | Erscheint, wenn |
|---|---|---|---|
| 1 | `breadcrumb.php` | Immobilien › Ort › Objekt (`aria-current="page"`) | immer |
| 2 | `property-header.php` | Status-Badge (Text), Objektart, Kauf/Miete, H1, Ort | immer |
| 3 | `property-gallery.php` | Hauptbild + Vorschauleiste bzw. neutraler Platzhalter | immer (Platzhalter ohne Bilder) |
| 3 | `property-summary.php` | Preis, Zimmer, Fläche, Grundstück, CTA „Anfrage senden“ (Anker `#psl-contact`) bzw. „Aktuelle Immobilien ansehen“ | immer |
| 4 | `property-sold-notice.php` | Hinweis Verkauft/Vermietet | nur Verkauft-Phase |
| 5 | `property-facts.php` | Eckdaten: Preise & Kosten, Flächen & Räume, Objekt & Zustand | Gruppen mit Werten |
| 6 | `property-text.php` | Objektbeschreibung | `description_note` vorhanden |
| 7 | `property-equipment.php` | Merkmalsliste + Ausstattungstext | Merkmale oder `furnishing_note` |
| 8 | `property-location.php` | öffentliche Adresse + Lagetext (keine Karte) | Adresse oder `location_note` |
| 9 | `property-energy.php` | Energieangaben | mind. ein Energiewert |
| 10 | `property-floorplans.php` | Grundrisse (eigene Lightbox-Gruppe) | mind. ein öffentlicher Grundriss |
| 11 | `property-other.php` | Sonstige Angaben, Provisionshinweis | `other_note` oder `courtage_note` |
| 12 | `property-agent.php` | Ansprechpartner (Kontaktdaten nur bei verfügbaren Objekten) | öffentlicher Makler oder Fallback-Filter |
| 13 | `property-contact.php` | „Interesse an dieser Immobilie?“ + Hook `psl_property_contact` | aktiv/reserviert |
| – | Hook `psl_property_similar` | Einhängepunkt „Ähnliche Immobilien“ (keine sichtbare Überschrift ohne Inhalt) | immer, nach dem Artikel |
| – | `lightbox.php` | `<dialog>` der Lightbox | Bilder oder Grundrisse vorhanden |

Überschriften: genau eine `<h1>` (Objekttitel), Abschnitte `<h2>`, Eckdaten-Gruppen und Provisionshinweis `<h3>`.

## Eckdaten (nur befüllte Werte)

| Gruppe | Kauf | Miete |
|---|---|---|
| Preise & Kosten | Kaufpreis bzw. „Preis auf Anfrage“, Preis pro m², Hausgeld, Instandhaltungsrücklage, Stellplatz, Provision | Kaltmiete/Warmmiete bzw. „Preis auf Anfrage“, Nebenkosten (ggf. „inkl. Heizkosten“), Heizkosten, Kaution (Freitext), Stellplatzmiete, Provision |
| Flächen & Räume | Wohnfläche (sonst Fläche), Nutzfläche, Gesamtfläche, Grundstücksfläche, Balkon-/Terrassenfläche, Gartenfläche, Zimmer, Schlafzimmer, Badezimmer, Etage, Etagen im Gebäude, Stellplätze | wie Kauf |
| Objekt & Zustand | Objektkategorie (`rs_category` übersetzt), Wohnungs-/Haustyp, Baujahr, letzte Modernisierung, Zustand, Qualität der Ausstattung, verfügbar ab, „Vermietet: Ja“ (nur wenn vermietet) | wie Kauf |

`0`/`null`/leer erscheinen nicht (Ausnahme: Etage 0 = Erdgeschoss). Kaufbegriffe (Hausgeld …) erscheinen nie bei Miete und umgekehrt.

**Ausstattung:** nur positive Merkmale – Booleans mit `true` (Balkon/Terrasse, Keller, Aufzug, Einbauküche, Garten, Gäste-WC, Barrierefrei, Abstellraum, Loggia, Sauna, Kamin), „Stellplatz: …“, „Haustiere erlaubt/nach Vereinbarung“ (nie „Nein“), „Bad: …“, „Böden: …“.

**Energie:** Energieausweis, Art des Ausweises, Endenergiebedarf bzw. -verbrauch (je nach Ausweistyp; sonst „Energiekennwert“) in kWh/(m²·a), Effizienzklasse (`A_PLUS` → „A+“), wesentlicher Energieträger, Heizungsart, Baujahr Anlagentechnik, ausgestellt am, gültig bis, „Ausweis erstellt“ (z. B. „ab 1. Mai 2014“), Warmwasser im Kennwert enthalten. Es werden keine Pflichtangaben ergänzt oder erfunden.

**Texte:** `description_note`, `furnishing_note`, `location_note`, `other_note` – beim Speichern `wp_kses_post`, bei der Ausgabe `wp_kses_post( wpautop() )` (Zeilenumbrüche → Absätze; kein doppeltes `wpautop`, da gespeichert ohne `<p>`).

## Galerie und Bilder

**Filter (fail-closed, im Mapper):** nur Bilder mit `is_private` und `is_not_for_exposee` eindeutig nicht gesetzt (fehlendes Feld = nicht gesetzt; vorhandener uneindeutiger Wert → gesperrt); nur `https://*.propstack.de`. Grundrisse (`is_floorplan` oder `floorplans[]`) gehen in einen eigenen Bereich, nie in die Hauptgalerie. Zusätzlich verwirft das ViewModel jede Nicht-HTTPS-URL (Defense in Depth).

**Bildgrößen (beobachtet, nicht dokumentiert – Messung an 6 Stichproben):** `medium` skaliert in einen Rahmen 600 × 450 px, `big` in 1920 × 1440 px (Breite abhängig vom Seitenverhältnis), `url` = Original, `thumb` 280 × 280, `square` 750 × 750, `small_thumb` 100 × 100 (beschnitten). Daraus:
- Hauptbild: `src` = medium, `srcset` = „medium 600w, big 1920w“ (Rahmenbreiten als Näherung), `sizes` passend zum Layout, `fetchpriority="high"`, nicht lazy.
- Vorschaubilder: `thumb` 280 w / `square` 750 w, `width`/`height` 280 (exakt bekannt), `loading="lazy"`.
- Grundrisse: medium/big, `object-fit: contain`, lazy.
- Da echte Breiten bei medium/big nicht verlässlich bekannt sind, verhindern feste CSS-Seitenverhältnisse (4:3 bzw. 1:1) Layoutsprünge (gemessen CLS 0). Maße werden **nicht** zur Laufzeit per Request ermittelt.
- Einschränkung: Propstack bietet keine Größe zwischen 600 und 1920 px; auf hochauflösenden Smartphones lädt der Browser für das Hauptbild daher `big` (Lighthouse-Hinweis „Properly size images“, bewusst akzeptiert zugunsten der Bildschärfe).
- `preconnect` zum Bild-CDN nur auf Detailseiten mit Bildern.

**Alt-Texte:** 1. öffentlicher Bildtitel aus Propstack, 2. „{Objekttitel} – Bild n von N“ bzw. „Grundriss n von N“. Vorschaubilder haben `alt=""`, weil der umgebende Link ein beschreibendes `aria-label` trägt.

**Fallback ohne Bilder:** neutraler Platzhalter (Inline-SVG, Text), kein `<img>`, kein externer Dienst.

## Lightbox (`assets/js/psl-gallery.js`)

Vanilla JS, kein jQuery, ca. 6 KB unkomprimiert, `defer`, nur auf Detailseiten mit Bildern geladen. Natives `<dialog>` (`showModal`).
- Progressive Enhancement: ohne JS bzw. ohne `<dialog>` sind alle Bilder Links auf die große Version; „Alle N Bilder ansehen“ ist ohne funktionierende Lightbox ausgeblendet.
- Bedienung: Klick/Enter auf Bild, Buttons „Vorheriges/Nächstes Bild“, Pfeiltasten (mit Umlauf), ESC (nativ), Klick auf den Hintergrund, Wischgesten (Pointer Events, `touch-action: pan-y`), Zähler „Bild n von N“ (`aria-live="polite"`).
- Fokus: beim Öffnen auf „Schließen“, Tab/Shift+Tab bleiben im Dialog, beim Schließen zurück zum Auslöser.
- Gruppen: `data-psl-gallery="main"` und `"floorplans"`; Nachbarbilder werden vorgeladen.

## Ansprechpartner

Nur öffentliche Propstack-Felder (`name` inkl. akademischem Titel, `position`, `avatar_url`, `public_phone`, `public_cell`, `public_email`). Telefon/E-Mail nur bei verfügbaren Objekten (nicht in der Verkauft-Phase). Ohne öffentlichen Makler: Filter `psl_property_fallback_agent` (`null` → kein Abschnitt). **Geplant:** zentraler Picaflor-Kontakt als Plugin-Einstellung.

## Hooks

| Hook | Typ | Zweck |
|---|---|---|
| `psl_before_property_content` / `psl_after_property_content` | Action | Theme-Wrapper (z. B. Avada) |
| `psl_before_property` / `psl_after_property` | Action | innerhalb des Artikels |
| `psl_property_contact` | Action | Kontaktformular (Phase 4), im Abschnitt `#psl-contact` |
| `psl_property_similar` | Action | ähnliche Immobilien (alle 200-Zustände und 410-Seite) |
| `psl_property_view_model` | Filter | ViewModel anpassen |
| `psl_property_template` | Filter | Template-Pfad |
| `psl_property_status_label` | Filter | Badge-Text (wird escaped) |
| `psl_property_fallback_agent` | Filter | Ansprechpartner ohne öffentlichen Makler (`array` wie `agent` oder `null`) |
| `psl_detail_container_classes` | Filter | CSS-Klassen des Wrappers |
| `psl_template_vars` | Filter | Variablen beim Rendern von Teil-Templates |
| `psl_overview_url` | Filter | URL der Übersicht |

## CSS

`assets/css/psl-detail.css` (ca. 11 KB), nur auf Detailseiten. Ausschließlich `.psl-*`-Selektoren bzw. `.psl-detail-wrap …`, keine globalen Element-Regeln (einzige Ausnahme: `html.psl-lightbox-open` sperrt das Scrollen bei offener Lightbox). Layout: Hero-Grid (Galerie 2/3 + Kurzfakten 1/3 ab 1024 px, darunter gestapelt), Vorschauleiste mit Scroll-Snap (4 bzw. 6 sichtbar), Faktengruppen im Auto-Fit-Grid. Typografie, Grundfarben und Buttonstil kommen vom Theme. **Bewusste Ausnahme:** unter 600 px wird die H1-Größe auf `clamp(1.75rem, 8vw, 2.5rem)` begrenzt, weil sehr große Theme-H1 lange deutsche Wörter sonst mitten im Wort umbrechen.

## Theme-Overrides – Beispiel

```
wp-content/themes/mein-child-theme/propstack-lite/parts/property-facts.php
```
überschreibt nur die Eckdaten; alle übrigen Teile kommen weiter aus dem Plugin. Overrides erhalten dieselben Variablen (`$vars['view']`) und müssen selbst escapen.
