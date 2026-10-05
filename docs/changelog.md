# Changelog (Entwicklungsfortschritt)

## 2026-10-05 – Phase 1: API-Client, Whitelist-Mapper, PropertyStore, Sync (Plugin 0.3.0)

**Wichtigste Änderungen**
- Plugin neu strukturiert: Bootstrap + Namespace `PropstackLite\` in `src/`, eigener PSR-4-Autoloader (keine Composer-Laufzeitabhängigkeit).
- `Api\Client` (nur lesend, Header-Auth, Retry/Backoff, sichere Fehlermeldungen), `Api\UnitsEndpoint` (korrekte Pagination mit `per`, `sort_by=id`, `total_count`, Deduplizierung).
- Einheitliches Modell `Domain\Property` (+ `Address`, `Image`, `Agent`) aus einem Format (`expand=1`/`new=1`).
- `Mapping\PropertyMapper` mit Whitelist (`FieldCatalog`): Adressschutz, Bildfilter, nur öffentliche Maklerfelder, keine internen CRM-Felder.
- Tabelle `{prefix}psl_properties`, `Storage\PropertyStore` mit serverseitig erzwungener Status-Whitelist.
- `Sync\SyncService`: Full (mit Reconcile), Incremental (24 h Überlappung), Single; Lock; 30-Tage-Logik für verkaufte Objekte (Datenebene); Abbruch ohne Datenverlust bei API-Fehlern.
- WP-Cron (Intervall einstellbar, Voll-Sync alle 6 h), WP-CLI `wp psl sync|status|statuses|audit`.
- Einstellungsseite: Status-Auswahl aus Propstack („Öffentlich“, „Verkauft/Vermietet“, „Reserviert“), Sync-Intervall, Webhook-Token, Sync-Status, „Jetzt synchronisieren“. Admin-Hinweise.
- `[propstack_list]` liest nur noch aus dem Store; Templates mit Theme-Override; CSS als Datei; absolute Detail-URLs `/immobilien/{slug}-{id}/`.
- Webhook jetzt POST-only, Token im `permission_callback`, plant Sync statt Cache-Flush.
- `uninstall.php`.

**Bugs behoben (aus dem Audit)**
- Pagination/Sortierung wirkungslos (`limit`, `ordering`, fehlendes `meta.pages`), Duplikate/fehlende Objekte.
- Falsche Preise („Kaufpreis 0 €“, Miete als Kaufpreis).
- Relative Detail-URLs (Unterverzeichnis-Installationen).
- Leere Ergebnisse ungecacht / API-Request pro Seitenaufruf.
- `min_price`/`max_price` wirkungslos (jetzt Synonyme für `price_from`/`price_to`).
- Rewrite-Flush bei Aktivierung ohne registrierte Regeln (alte Regeln entfernt; neuer Router in Phase 2).

**Neue Dateien:** `src/**`, `templates/list.php`, `templates/parts/card.php`, `assets/css/psl-list.css`, `uninstall.php`, `composer.json`, `phpunit.xml.dist`, `tests/**`.

**DB-Migrationen:** Schema-Version 1 (Tabelle angelegt). Settings-Migration aus 0.2.x (`status=` aus `query_params` → „Öffentliche Status“; Default damit „Website Picaflor“).

**Breaking Changes**
- Einstellungen `endpoint`, `query_params`, `cache_minutes`, `detail_url_template`, `dev_no_cache` entfallen.
- Shortcode: beliebige API-Parameter werden nicht mehr durchgereicht (insb. kein `status`); `local_sort_by` entfällt; `ordering` entfällt (stattdessen `sort_by`/`order`).
- Detail-URLs `/immobilie/…` und `?ps_id=` funktionieren nicht mehr; neue Links `/immobilien/…` liefern bis Phase 2 **404**.
- Webhook nur noch per POST.
- Mindestanforderung PHP 8.1.

**Offene Punkte:** Router/Detailseiten (Phase 2), reale Testumgebung mit Avada/CF7/SEO-Plugin, Webhook-Registrierung in Propstack durch Admin, System-Cron für Produktion, fachliche Auswahl „Verkauft“/„Reserviert“ im Backend.

## 2026-10-05 – Phase 0: Repository- und Security-Basis

- Git-Repository angelegt; Basis-Commit des Originalstands (0.2.0) ohne Secret-Datei.
- `.gitignore` (Secrets, `vendor/`, Test-Cache), Root-`.htaccess`: `Propstack-API.txt` war per HTTP abrufbar (200) → jetzt 403; `docs/`, `.git/`, `tools/` → 404.
- Altcode bereinigt: unsicherer Detail-Bootstrap entfernt (ungefilterte CRM-Ausgabe/XSS, hartkodiertes Dev-Thumbnail mit undefinierter Variable, ungeprüfte `ps_id`, zwei ungecachte API-Calls pro Aufruf), toter Code entfernt (`fetch_single`, `get_unit`, `psl_detail_template`, `build_url`, `activate`, `templates/psl-detail.php` mit `print_r`).
- Liste: keine Straße/Hausnummer mehr, keine privaten Bilder/Grundrisse als Vorschaubild; Undefined-Variable-Warning behoben; Fehlerdetails nur für Admins.
- Dokumentation `docs/` und `CLAUDE.md` angelegt (zusammen mit Phase 1).
