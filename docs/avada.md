# Avada-Integration

> **Status: Geplant (Phase 2/3) – noch nicht gegen eine reale Avada-Installation verifiziert.** Lokal ist weder WordPress mit Avada noch die Live-Konfiguration vorhanden.

## Grundsatz

Das Plugin baut Avada nicht nach. Datenlogik (Sync, Store, Mapper, Router) ist theme-unabhängig; ein Themewechsel darf nur die Darstellung betreffen.

## Implementiert (Phase 1, theme-neutral)

- Listen-Templates `templates/list.php`, `templates/parts/card.php`, überschreibbar unter `{theme}/propstack-lite/…` (`Frontend\TemplateLoader`, `locate_template`).
- Eigenes CSS `assets/css/psl-list.css`, nur Layout, alle Selektoren unter `.psl-list`; Farben/Typografie erbt das Theme; Laden nur bei Verwendung des Shortcodes.
- Filter `psl_template_vars` für Template-Variablen.

## Geplant

- `Theme\AvadaAdapter`, nur geladen wenn Avada aktiv (`class_exists( 'Avada' )` o. ä. – genaue Erkennung gegen reale Installation prüfen).
- Detailseite über `template_include` mit `templates/single-property.php` (`get_header()`/`get_footer()`), Wrapper-Hooks `psl_before_content`/`psl_after_content`; der Adapter setzt Avadas Container (`#content`, `.fusion-row` – zu verifizieren).
- Lightbox: optional Avada-Lightbox über Adapter; Fallback eigene, kleine `<dialog>`-Lightbox.
- Shortcodes je Detailabschnitt (`[psl_property section="…"]`), damit Layouts alternativ in Avada (Layout Sections) gebaut werden können.

## Bekannte Risiken

- Avada liest Seitenoptionen aus dem globalen `$post`; auf einer virtuellen Route ohne Post greifen Defaults (Title-Bar, Sidebar). Phase 2 beginnt deshalb mit einem Spike in der realen Testumgebung. Fallback: nicht indexierte Hostseite als Layout-Träger, Daten weiterhin aus dem Router.

## Fallback ohne Avada

Neutrale Wrapper, eigene minimale Styles; alle Funktionen bleiben erhalten.
