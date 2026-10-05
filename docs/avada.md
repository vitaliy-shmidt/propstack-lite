# Avada-Integration

> **Noch nicht gegen eine reale Avada-Installation verifiziert.** Lokal ist weder WordPress mit Avada noch die Live-Konfiguration vorhanden. Getestet wurde theme-neutral mit Twenty Twenty-One (klassisches Theme, wie Avada) und Twenty Twenty-Five (Block-Theme).

## Grundsatz

Das Plugin baut Avada nicht nach. Datenlogik (Sync, Store, Mapper) und Routing (Router, Resolver, Controller) kennen Avada nicht; ein Themewechsel betrifft nur die Darstellung.

## Implementiert – theme-neutral (verifiziert mit Twenty Twenty-One/-Five)

- Detailseite über `template_include` → `templates/single-property.php` bzw. `property-gone.php`; Header/Footer über `get_header()`/`get_footer()` (klassische Themes wie Avada) bzw. Template-Parts bei Block-Themes (`Frontend\TemplateLoader::header()/footer()`).
- Template-Override: `{theme}/propstack-lite/single-property.php`, `{theme}/propstack-lite/parts/*.php` (manuell getestet).
- Eigener Wrapper `div.psl-detail-wrap` (kein eigenes `<main>`, da klassische Themes wie Avada `<main>` in `header.php` öffnen). Hooks `psl_before_property_content`/`psl_after_property_content` für Theme-Wrapper.
- CSS `assets/css/psl-detail.css` und `psl-list.css`: nur Layout, alle Selektoren unter `.psl-…`, Typografie/Farben vom Theme; geladen nur auf Detailseiten bzw. bei Verwendung des Shortcodes.
- Keine virtuellen Posts (siehe [routing-seo.md](routing-seo.md)).

## Implementiert – Avada-Adapter (**noch nicht gegen reale Avada-Installation verifiziert**)

`Theme\AvadaAdapter`, registriert in `after_setup_theme`, nur wenn `get_template() === 'Avada'` (Groß-/Kleinschreibung egal) oder die Klasse `Avada` existiert.

| Funktion | Wirkung |
|---|---|
| Body-Klasse `psl-theme-avada` auf Immobilienseiten | eigene Klasse für Feinanpassungen im Theme-CSS |
| Filter `psl_detail_container_classes` → `psl-detail-wrap--theme-container` | Plugin-Wrapper ohne eigene Maximalbreite/Seitenabstände, weil Avada eigene Container bereitstellt (Annahme, zu prüfen) |

Bewusst **nicht** umgesetzt, weil ohne reale Installation nicht prüfbar: Avada-Container-Markup (`.fusion-row` o. Ä.), Avada-Hooks, Seitenoptionen (Title-Bar, Sidebar), Avada-Lightbox, Avada Layout Sections.

## Zu prüfen in der realen Testumgebung

1. Rendert Avadas `header.php`/`footer.php` auf der virtuellen Route ohne `$post` korrekt (Title-Bar, Sidebar, Container-Breite, Sticky-Header)? Avada liest Seitenoptionen typischerweise aus dem aktuellen Post.
2. Ist `psl-detail-wrap--theme-container` korrekt oder fehlen Seitenabstände?
3. Avada-Lightbox für die Galerie (Phase 3) nutzbar?
4. Wird der Wrapper-Hook für Avada-spezifisches Markup gebraucht?
5. Fallback, falls Avada ohne Post nicht sauber rendert: nicht indexierte Hostseite als Layout-Träger, Daten weiterhin aus dem Router (müsste begründet, gekapselt und getestet werden).

## Fallback ohne Avada

Neutrale Wrapper, eigene minimale Styles; alle Funktionen bleiben erhalten (getestet).
