# Entscheidung 005: Whitelist-Mapper mit einheitlichem Property-Modell

Status: angenommen (2026-10-05), umgesetzt in Phase 1.

## Kontext

Die Propstack-Detail-/Expand-Antwort enthält neben öffentlichen Exposé-Daten interne CRM-Daten (Notizen, Provisionen, Beziehungen zu Kontakten, Tokens, interne Maklerkontakte, Ordner „Sensible Daten“) und liefert Straße/Hausnummer auch bei `hide_address: true`. 0.2.x nutzte zwei unterschiedliche Formate (flache Liste, `new=1`-Detail) ohne gemeinsames Mapping.

## Entscheidung

- Ein einziges Rohformat (`expand=1` in der Liste = `new=1` im Detail) und ein einziges internes Modell `Domain\Property`.
- `Mapping\PropertyMapper` ist die einzige Stelle, die Rohdaten liest; nur Felder aus `Mapping\FieldCatalog` bzw. explizit gemappte Kernfelder werden übernommen.
- Datenschutz im Mapper: Adresse nur bei explizitem `hide_address: false`; private/nicht freigegebene Bilder verworfen; nur `public_*`-Maklerkontakte; nur HTTPS-URLs auf `*.propstack.de`.
- Verbotene Felder zusätzlich als Liste gepflegt und per Tests und `wp psl audit` geprüft.

## Gründe

Privacy by Design: Was nicht gespeichert wird, kann weder durch Template-Fehler noch durch Exporte oder Logs nach außen gelangen.

## Alternativen

- Blacklist (Rohdaten speichern, interne Felder entfernen): neue Propstack-Felder wären automatisch öffentlich – abgelehnt.
- Rohdaten speichern und erst beim Rendern filtern: interne Daten lägen in der WordPress-Datenbank – abgelehnt.

## Konsequenzen

- Neue Felder müssen bewusst in `FieldCatalog` aufgenommen, getestet und in [property-model.md](../property-model.md) dokumentiert werden.
- Nach Whitelist-Erweiterungen ist ein Voll-Sync nötig, damit bestehende Zeilen die neuen Felder erhalten.
