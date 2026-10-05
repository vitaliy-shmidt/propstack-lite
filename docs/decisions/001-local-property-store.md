# Entscheidung 001: Lokaler Property-Store statt Live-API bzw. Transients

Status: angenommen (2026-10-05), umgesetzt in Phase 1.

## Kontext

Version 0.2.x fragte Propstack im Besucher-Request ab (Liste: Transient-Cache mit Miss → bis zu 25 sequenzielle Requests; Detail: 2 ungecachte Requests pro Aufruf). Geplant sind 410-Antworten für ehemals öffentliche Objekte, eine 30-Tage-Anzeige für verkaufte Objekte, Filter/Sortierung, Sitemap mit `lastmod` und viele Besucher.

## Entscheidung

Öffentliche Objekte werden per Sync (Cron/CLI/Admin) in eine eigene Tabelle `{prefix}psl_properties` geschrieben. Frontend liest ausschließlich daraus.

## Gründe

- Keine externe Latenz/Abhängigkeit im Besucher-Request; API-Ausfälle beeinträchtigen die Website nicht (Bestand bleibt).
- Historie für 410/30-Tage-Logik (Zeilen bleiben, Daten werden entfernt).
- Filter/Sortierung/Paging per SQL mit Indizes; Sitemap mit `content_changed_at`.
- Kompatibel mit Full-Page-Caching.

## Alternativen

- **Transients (Fresh/Stale):** keine Historie, keine SQL-Filter, Stampede-Risiko, Miss im Besucher-Request.
- **CPT:** siehe [002](002-no-cpt.md).
- **Live-API mit Object-Cache:** weiterhin Requests im Besucher-Request.

## Konsequenzen

- Daten sind bis zu einem Sync-Intervall alt (Default 15 min; Webhook beschleunigt).
- Schema-Migrationen nötig ([database.md](../database.md)).
- WP-Cron-Zuverlässigkeit relevant → System-Cron empfohlen.
