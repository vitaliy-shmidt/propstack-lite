# Entscheidung 003: Leads über Contact Form 7 → Propstack-Mailintegration

Status: angenommen (2026-10-05), Umsetzung Phase 4.

## Kontext

Anfragen sollen eindeutig dem Objekt zugeordnet in Propstack landen. Propstack dokumentiert zwei Wege: E-Mail im Format `ps-kontaktanfrage` (bzw. OpenImmo-XML) an eine verbundene Adresse, verarbeitet von der Automatisierung „Neue Portalanfrage“, sowie API-Endpunkte (`POST /v1/contacts`, `/v1/activities`, `/v1/client_properties`). Der vorhandene API-Key hat keine Rechte auf Kontakte/Aktivitäten/Deals (401).

## Entscheidung

Version 1: **Contact Form 7** rendert das Formular und versendet die Mail; das Plugin ergänzt serverseitig die Objektdaten aus dem Store und erzeugt den `ps-kontaktanfrage`-Block. Abstraktion über `LeadSink`, damit später `PropstackApiLeadSink` oder ein Hybrid möglich ist. Keine direkten POSTs an Propstack.

## Gründe

- Von Propstack für Website-Anfragen empfohlen; gleiche Automatisierung wie Portalanfragen.
- Kein Key mit CRM-Schreibrechten in WordPress nötig → geringeres Risiko für CRM-Daten.
- Bei der API ist nicht dokumentiert, ob Automatisierungen ausgelöst werden und welche Kontaktfelder ein Upsert überschreibt.
- CF7 ist vorhanden; Integration über offizielle Hooks, kein Fork.

## Alternativen

- **Variante A – direkte API:** strukturierter, aber benötigt Schreibrechte, Risiko für bestehende Kontakte, Verhalten der Automatisierungen unklar.
- **Hybrid:** Mail + zusätzliche Aktivität per API – später möglich über `LeadSink`.

## Konsequenzen

- Abhängigkeit von zuverlässigem Mailversand (SMTP, SPF/DKIM).
- Empfangsadresse, Quelle und Custom Fields müssen in Propstack konfiguriert werden; die Quellenzuordnung ist in der Doku nicht beschrieben (zu klären).
- Objektdaten in der Mail stammen immer aus dem Store, nie aus Browserfeldern.
