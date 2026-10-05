# Sicherheitsregeln

Verbindlich für alle Phasen. „Umgesetzt“ = im Code vorhanden und getestet (Stand Phase 1).

## Secrets

| Regel | Umsetzung |
|---|---|
| API-Key nur serverseitig, nie im Browser, nie in URLs | Header `X-API-KEY` (`Api\Client`); Admin-Feld ist Passwortfeld, wird nie vorbefüllt – **umgesetzt** |
| Key bevorzugt außerhalb der Datenbank | Konstante `PSL_API_KEY` in wp-config.php hat Vorrang – **umgesetzt** |
| Key nie in Logs/Fehlermeldungen | `ApiException`-Texte ohne Key; Logger verwirft Kontextschlüssel wie `key`, `token`, `secret` – **umgesetzt, getestet** |
| Secrets nie im Repository | `.gitignore` (`Propstack-API.txt`, `*.secret`, `.env*`) – **umgesetzt** |
| Lokale Secret-Datei nicht per HTTP erreichbar | Root-`.htaccess` sperrt `Propstack-API.txt` (vorher HTTP 200, jetzt 403), `docs/`, `.git/`, `tools/`, Plugin-`vendor/`/`tests/` – **umgesetzt, getestet** |
| Webhook-Token | nur `[A-Za-z0-9_-]`, Vergleich mit `hash_equals` im `permission_callback`; leer = deaktiviert – **umgesetzt** |

## Propstack-Zugriff

- Nur lesende Requests. Schreibende Requests (POST/PUT/PATCH/DELETE) nur nach ausdrücklicher Freigabe.
- Feste Basis-URL `https://api.propstack.de/v1` (kein konfigurierbarer Endpoint mehr → kein SSRF über Einstellungen) – **umgesetzt**.
- Pfadsegmente nur aus Integer-IDs – **umgesetzt**.

## Daten (Whitelist und Datenschutz)

- Nur gewhitelistete Felder gelangen in den Store; interne CRM-Felder nie ([property-model.md](property-model.md)) – **umgesetzt, getestet, `wp psl audit`**.
- `hide_address`: Straße, Hausnummer, Koordinaten werden bei verborgener Adresse nicht gespeichert; fehlendes Flag = verborgen – **umgesetzt, getestet**.
- Bilder mit `is_private`/`is_not_for_exposee` werden nie übernommen; nur HTTPS-URLs auf `*.propstack.de` – **umgesetzt, getestet**.
- Makler: nur `public_*`-Kontaktfelder – **umgesetzt, getestet**.
- Nie öffentliche Objekte werden nie gespeichert; entfernte Objekte verlieren ihre Daten sofort, verkaufte nach 30 Tagen – **umgesetzt, getestet**.

## Ausgabe

- Alle Template-Ausgaben mit `esc_html`/`esc_attr`/`esc_url`/`tag_escape` – **umgesetzt**.
- Rich Text: beim Speichern `wp_kses_post`, bei der Ausgabe erneut escapen bzw. `wp_kses_post` (Detailseite, Phase 3).
- Fehlerdetails nie öffentlich: Besucher sehen generische Meldungen; Details nur im Admin (Sync-Status) und im Debug-Log – **umgesetzt**.

## Eingaben, Berechtigungen, Nonces

- Einstellungen über Settings API mit `sanitize_callback`, Capability `manage_options` – **umgesetzt**.
- Admin-Aktionen (Sync, Statusliste) über `admin-post.php` mit `check_admin_referer` + `current_user_can( 'manage_options' )` – **umgesetzt**.
- Shortcode-Attribute werden validiert/gewhitelistet; sie können nur einschränken, nie nicht-öffentliche Objekte freischalten (kein `status`-Attribut) – **umgesetzt, getestet**.
- SQL ausschließlich über `$wpdb->prepare` bzw. `$wpdb->insert/update`; Sortierung nur aus fester Whitelist – **umgesetzt, getestet**.
- REST: Prüfung im `permission_callback`, nur POST – **umgesetzt**.
- Direkter Dateiaufruf: Templates/Bootstrap mit `ABSPATH`-Guard; Klassen-Dateien enthalten nur Deklarationen.

## Formulare (Geplant, Phase 4)

Nonce/CF7-Mechanismen, serverseitige Validierung, Objektdaten nur aus dem Store, Honeypot, Rate-Limit pro gehashter IP, keine PII in Logs.

## Tracking (Geplant, Phase 6)

Keine PII im dataLayer/Analytics/Logs; Cookies nur nach Consent; Plugin lädt kein GTM.

## Logging

Nur bei `WP_DEBUG_LOG`; Inhalte: Sync-Zusammenfassungen, Fehlerkategorien, Objekt-IDs. Keine Keys, Tokens, Namen, E-Mails, Telefonnummern, Adressen, Nachrichten.

## Entwicklungsumgebung

- Projekt liegt im XAMPP-Webroot → `.htaccess`-Sperren beachten, wenn neue Dateien hinzukommen.
- `vendor/` (PHPUnit) wird nie ausgeliefert; Deployment-Paket ohne `vendor/`, `tests/`, `composer.*`, `phpunit.xml.dist`.
- Integrationstests nur gegen Wegwerf-Instanzen (sie leeren die Tabelle).
