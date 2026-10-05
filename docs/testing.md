# Tests

## Strategie

| Ebene | Werkzeug | Läuft ohne WordPress | Inhalt |
|---|---|---|---|
| Unit | PHPUnit 10 (`tests/Unit`) | ja | Mapper/Datenschutzregeln, Sanitizer, Slugger, Formatter, StateResolver, Client (Retry, Fehler, Secrets), Pagination |
| Integration | PHPUnit 10 (`tests/Integration`) + WordPress-Wegwerfinstanz | nein | Store (Sichtbarkeit, Filter, SQL-Whitelist), alle Sync-Übergänge mit simuliertem Propstack (`FakePropstack`), Shortcode-Ausgabe, keine HTTP-Requests im Frontend |
| Contract (manuell) | WP-CLI gegen echte API (nur lesend) | nein | `wp psl sync --full`, `wp psl audit` |
| Manuell/Staging | Checklisten unten | – | Admin, Frontend, später Avada/CF7/SEO/Tracking |

## Ausführen

```bash
cd propstack-lite
composer install
vendor/bin/phpunit --testsuite unit
PSL_WP_LOAD=/pfad/zur/wegwerf-instanz/wp-load.php vendor/bin/phpunit --testsuite integration
```

**Integrationstests leeren `{prefix}psl_properties` und ändern Plugin-Optionen (werden danach wiederhergestellt) – nie gegen Staging/Produktion.** Ohne `PSL_WP_LOAD` werden sie übersprungen.

Testinstanz Phase 1: WordPress 7.1.2 (de_DE), MariaDB 10.4 (XAMPP), PHP 8.3, Plugin per Junction eingebunden, API-Key zur Laufzeit aus `Propstack-API.txt` als `PSL_API_KEY` (Datei wird nicht kopiert). Instanz liegt außerhalb des Projekts.

## Fixtures

`tests/fixtures/*.json` – **anonymisiert/synthetisch**, bilden die beobachtete API-Struktur nach (Label/Value-Format, Bild-Flags, Broker mit internen und öffentlichen Feldern) und enthalten bewusst interne „Köder“-Felder (`note`, `token`, `relationships`, interne Maklerkontakte), deren Werte im Modell nicht auftauchen dürfen. Keine echten Kundendaten in Fixtures aufnehmen.

## Wichtige Testfälle (implementiert)

- Adresse verborgen → keine Straße/Hausnummer/Koordinaten im Modell und in der Ausgabe; fehlendes Flag = verborgen
- private, nicht freigegebene, uneindeutig markierte Bilder werden verworfen; Grundrisse getrennt; fremde Hosts/HTTP-URLs verworfen
- interne CRM-Felder und interne Maklerkontakte erscheinen nirgends im Modell
- `0` = nicht angegeben (außer Etage); Freitext-Kaution bleibt Text; Preiszeile nie „0 €“
- Client: Key nur im Header, nie in Fehlermeldungen; Retry/Backoff; 401 ohne Retry; `Retry-After`
- Pagination: `per`/`sort_by=id`, `total_count`, Deduplizierung, Unvollständigkeit erkannt
- StateResolver: 19 Zustandskombinationen
- Sync: nur öffentliche Objekte gespeichert; verkauft → 30 Tage → Daten entfernt; Statuswechsel/Löschung → removed; Reaktivierung; API-Fehler lässt Bestand unverändert; ohne öffentliche Status kein Request; Inkrement speichert nie Nicht-Öffentliches; Lock; Reconcile-Fallback
- Store/Shortcode: Status-Whitelist erzwungen, `status`-Attribut wirkungslos, Escaping, Filter/Sortierung/Paging, SQL-Injection-Versuche in Kriterien wirkungslos, **0 Propstack-Requests beim Rendern**

## Ergebnisse Phase 1 (2026-10-05)

- Unit: 65 Tests, 185 Assertions – grün. Integration: 16 Tests, 66 Assertions – grün.
- Echter Voll-Sync (nur lesend): Konfiguration „Website Picaflor“ → 1 öffentliches Objekt. Belastungstest mit drei öffentlichen Status → 51 Objekte in ≈ 2,7 s; `wp psl audit` ohne Verstöße; 11 Objekte mit verborgener Adresse, davon 0 mit gespeicherter Straße. Rückstellung auf einen Status → 50 Objekte korrekt `removed` (Reconcile gegen echte API), Daten entfernt.
- Frontend über HTTP (PHP-Built-in-Server, Mess-mu-plugin auf `pre_http_request`): 4 Seitenaufrufe, 0 ausgehende Requests; Propstack-Requests nur im Kontext `wp-cron.php`.
- `debug.log`: keine Warnings/Notices des Plugins.

## Manuelle Checkliste (je Release)

- [ ] Aktivierung ohne manuelles Speichern der Permalinks; Cron-Events `psl_sync_*` geplant
- [ ] Einstellungen: Statusliste lädt; Key-Feld leer lassen behält Key; Konstante wird erkannt
- [ ] `wp psl sync --full`, `wp psl status`, `wp psl audit` ohne Fehler/Verstöße
- [ ] Liste: keine Straße bei verborgener Adresse, keine privaten Bilder, kein „0 €“, Badges korrekt
- [ ] Fehlender Key / API-Fehler → Admin-Hinweis, Liste bleibt mit letztem Stand online
- [ ] Deaktivierung entfernt Cron-Events; Deinstallation entfernt Tabelle und Optionen

## Geplante Tests (spätere Phasen)

- **Routing (Phase 2):** 200/301/404/410-Matrix per `curl -I`, Unterverzeichnis-Installation, Legacy-URLs
- **Avada (Phase 2/3):** reale Testumgebung, Header/Footer/Container, Lightbox, mobil
- **CF7 (Phase 4):** Formatter-Unit-Tests, manipulierte `property_id`, Honeypot, Rate-Limit; Ende-zu-Ende-Test in Propstack nur nach Freigabe
- **SEO (Phase 5):** Head-Snapshots je Adapter, Schema-Validator, Sitemap-Inhalt
- **Tracking (Phase 6):** genau ein `property_lead` nach Erfolg, keiner bei Fehler; keine PII im dataLayer; kein Cookie ohne Consent
