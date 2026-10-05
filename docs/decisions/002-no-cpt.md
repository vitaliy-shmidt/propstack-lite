# Entscheidung 002: Kein Custom Post Type pro Immobilie

Status: angenommen (2026-10-05).

## Kontext

Eine naheliegende Alternative zur eigenen Tabelle wäre ein CPT `immobilie` mit Post-Meta, synchronisiert aus Propstack.

## Entscheidung

Kein CPT. Immobilien existieren in WordPress nur als Zeilen in `{prefix}psl_properties`; Detailseiten entstehen dynamisch über einen eigenen Router ([004](004-dynamic-routing.md)).

## Gründe

- Propstack ist führend; Redaktionsmasken, Revisionen, Papierkorb und Autosaves eines CPT würden Doppelpflege und Inkonsistenzen erzeugen.
- SEO-Plugins (Yoast/Rank Math) und Avada behandeln CPTs automatisch (eigene Metaboxen, Sitemaps, Seitenoptionen) – das würde mit den aus Propstack generierten SEO-Daten konkurrieren.
- Post-Meta ist für Filter/Sortierung (Preis, Fläche, Zimmer) ineffizient.
- Interne CRM-Daten könnten über Meta-Felder, REST-API oder Exporte ungewollt sichtbar werden.

## Alternativen

- CPT mit `show_ui = false`, `public = true`: weniger Konflikte, aber weiterhin Post-Meta-Abfragen und SEO-Plugin-Automatismen.

## Konsequenzen

- SEO-Ausgaben, Sitemap und Breadcrumbs müssen über eigene Adapter integriert werden (Phase 5).
- Avada-Layouts für „Single Post“ greifen nicht automatisch → Adapter/Templates ([avada.md](../avada.md)).
