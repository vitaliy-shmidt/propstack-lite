# Entscheidung 004: Dynamisches Routing `/immobilien/{slug}-{id}/`

Status: angenommen (2026-10-05). URL-Erzeugung umgesetzt in Phase 1, Router folgt in Phase 2.

## Kontext

0.2.x hing an einer manuell angelegten WordPress-Seite `immobilie` (`is_page('immobilie')`), lieferte für alle Objekte dasselbe Canonical, Soft-404 bei unbekannten IDs und einen wirkungslosen Slug-Redirect (Query-Var nicht registriert).

## Entscheidung

- Eigener Router mit Rewrite-Regel für `/immobilien/{slug}-{id}/`; keine WordPress-Seite pro Objekt, keine Hostseite als Pflicht.
- **Die ID ist maßgeblich**, der Slug dekorativ. Falscher Slug → 301 auf die kanonische URL.
- Slug strukturiert aus Zimmer, Objektart, Vermarktungsart, Ort, Ortsteil (nicht aus dem Propstack-Titel).
- Antworten: 200 / 301 / 404 (unbekannt) / 410 (entfernt bzw. verkauft > 30 Tage), Details in [routing-seo.md](../routing-seo.md).
- `/immobilien/` bleibt eine normale WordPress-Seite (Übersicht).
- Legacy `/immobilie/…` → 301, sofern eine ID ermittelbar ist.

## Gründe

Stabile, sprechende URLs ohne Duplicate Content; Titeländerungen in Propstack erzeugen keine neuen indexierten Versionen; echte Statuscodes statt Soft-404.

## Alternativen

- Titelbasierter Slug (wie 0.2.x): instabil, enthält Marketing-/interne Texte.
- Nur ID (`/immobilien/12345/`): stabil, aber weniger sprechend.
- Hostseite mit Query-Parametern: Canonical-/SEO-Plugin-Konflikte.

## Konsequenzen

- Rewrite-Regeln müssen bei Aktivierung **nach** Registrierung geflusht werden.
- Unterseiten von `/immobilien/`, deren Slug auf `-{Zahl}` endet, würden vom Router überdeckt.
- Avada-Integration auf virtueller Route muss real geprüft werden ([avada.md](../avada.md)).
