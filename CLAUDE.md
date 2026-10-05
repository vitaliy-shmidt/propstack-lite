# CLAUDE.md – Einstieg für Claude-Sessions

**Propstack Listings Lite** ist ein WordPress-Plugin für Picaflor Immobilien: Es synchronisiert öffentliche Immobilien aus Propstack (führendes System) in eine lokale Tabelle und stellt sie auf der Website dar. Später kommen dynamische, SEO-fähige Detailseiten, Anfragen über Contact Form 7 und Kampagnen-Tracking dazu.

- Plugin: `propstack-lite/` (Einstieg `propstack-lite.php`, Code in `src/`, Namespace `PropstackLite\`)
- Dokumentation: `docs/` – **zuerst `docs/README.md` lesen** (Status, offene Punkte, Verweise); Detailseite/Templates/Galerie: `docs/frontend.md`
- Theme der Live-Seite: Avada (lokal nicht vorhanden, Integration nur über Adapter)

## Aktueller Stand

Siehe Statustabelle in `docs/README.md`. Phasen-Workflow: **Plan → Implementierung → Tests → Dokumentation → Zusammenfassung → STOP**, danach auf Freigabe warten. Keine Phase ungefragt beginnen.

## Architekturregeln (Details: `docs/architecture.md`, `docs/decisions/`)

- Besucher-Requests lösen **nie** Propstack-Requests aus. Daten kommen nur aus `{prefix}psl_properties`; Sync läuft in Cron, WP-CLI oder Admin-Aktionen.
- Ein internes Modell `Domain\Property` für Liste und Detail. Nur `Mapping\PropertyMapper` liest Propstack-Rohdaten (Whitelist in `Mapping\FieldCatalog`).
- Sichtbarkeit wird serverseitig über die Einstellung „Öffentliche Propstack-Status“ erzwungen (`PropertyStore::queryPublic`). Shortcode- oder GET-Parameter dürfen nie freischalten.
- Kein CPT pro Immobilie, kein Rewrite des Plugins, schrittweise Migration.
- Theme-, CF7- und SEO-Plugin-Integration nur über Adapter und offizielle Hooks.

## Sicherheitsregeln (Details: `docs/security.md`)

- `Propstack-API.txt` enthält Zugangsdaten: **nie ausgeben, kopieren, committen** (per `.gitignore` und `.htaccess` geschützt). Key in WordPress bevorzugt als Konstante `PSL_API_KEY`.
- Propstack nur **lesend** verwenden. POST/PUT/PATCH/DELETE gegen Propstack nur nach ausdrücklicher Freigabe. Auch Test-Anfragen an das echte Propstack-Postfach (erzeugen CRM-Kontakte) nur nach Freigabe.
- Leads: keine Anfrageinhalte speichern oder loggen (nur Lead-ID, Property-ID, Status); Property immer serverseitig gegen den Store prüfen ([docs/leads.md](docs/leads.md)).
- `hide_address` respektieren, private bzw. nicht freigegebene Bilder nie übernehmen, interne CRM-Felder nie speichern oder ausgeben.
- Alles escapen; Rich Text mit `wp_kses_post`; keine personenbezogenen Daten in Logs, dataLayer oder Doku-Beispielen.

## Nicht ungefragt ändern

- Propstack-Daten (keine schreibenden API-Calls)
- `Propstack-API.txt`, `.htaccess`, `.gitignore`
- DB-Schema ohne Migration und Doku (`docs/database.md`)
- Whitelist/Datenschutzlogik im Mapper ohne Tests und Doku (`docs/property-model.md`)
- Git-Historie (kein Force-Push, kein Rebase). Remote: `origin` = `https://github.com/vitaliy-shmidt/propstack-lite.git`, Branch `main`; nach abgeschlossener Phase Commit + `git push origin main` (vorher `git diff --cached` auf Secrets/Artefakte prüfen)
- Prozesse: niemals globale `taskkill`/`killall`; nur selbst gestartete Prozesse per dokumentierter PID beenden

## Befehle

```bash
cd propstack-lite
composer install                          # nur Dev-Abhängigkeiten (PHPUnit, WP-Stubs)
vendor/bin/phpunit --testsuite unit        # ohne WordPress
PSL_WP_LOAD=/pfad/zu/wp-load.php vendor/bin/phpunit --testsuite integration   # NUR Wegwerf-Instanz!
PSL_WP_LOAD=… PSL_TEST_BASE_URL=http://127.0.0.1:8099/Picaflor vendor/bin/phpunit --testsuite http   # laufender Testserver nötig
wp psl status | wp psl sync [--full|--id=N] | wp psl statuses | wp psl audit
```
