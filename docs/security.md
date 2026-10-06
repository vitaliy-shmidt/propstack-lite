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

- Alle Template-Ausgaben mit `esc_html`/`esc_attr`/`esc_url`/`tag_escape` – **umgesetzt** (Liste und Detailseite).
- Rich Text: beim Speichern `wp_kses_post`, bei der Ausgabe erneut `wp_kses_post( wpautop() )` – **umgesetzt (Detailseite)**.
- Templates erhalten nur das ViewModel aus dem gewhitelisteten Modell; `publicExposeUrl`, `unitId` u. Ä. sind nicht enthalten – **umgesetzt, getestet**.
- XSS-Tests mit `<script>alert(1)</script>` in Titel, Beschreibung, Maklername, Bildtitel – über den Mapper **und** direkt im Store (am Mapper vorbei) – erzeugen keinen ausführbaren Code; `javascript:`-URLs werden von `esc_url` verworfen – **getestet (HTTP-Suite)**.
- Detailseiten: unbekannte IDs → 404 ohne API-Request; Weiterleitungen nur auf eigene URLs (`wp_safe_redirect`), Query-Parameter werden kodiert übernommen, interne Parameter entfernt – **umgesetzt**.
- Kontaktdaten des Maklers werden in der Verkauft-Phase nicht angezeigt.
- Phase 3: Bei `hide_address` stehen Straße, Hausnummer und Koordinaten weder im sichtbaren HTML noch in `data-*`-Attributen, JSON oder Skripten (das ViewModel enthält sie gar nicht) – **getestet** (Unit: ViewModel-JSON; HTTP: komplette Seite; Browser: DOM).
- Galerie-/Lightbox-Daten in `data-*`-Attributen enthalten nur öffentliche Bild-URLs und -Titel (escaped); das ViewModel verwirft zusätzlich alle Nicht-HTTPS-URLs (Defense in Depth gegen `javascript:` in `srcset`) – **getestet**.
- XSS-Tests Phase 3: Payloads in Beschreibung, Ausstattung, Lage, Sonstiges, Provisionshinweis, Maklername/-position, Bildtiteln (inkl. Attribut-Ausbruch `"` und `'`) sowie im Status-Label (über Filter) erzeugen keinen ausführbaren Code – **getestet (HTTP)**.
- Interne Felder: `contract_type` (Maklerauftrag) zusätzlich auf der Verbotsliste; interne Broker-Felder, CRM-IDs, PriceHubble-Zugang, Notizen, Exposé-Link und Objektnummer erscheinen nicht im HTML – **getestet**.
- Fehlerdetails nie öffentlich: Besucher sehen generische Meldungen; Details nur im Admin (Sync-Status) und im Debug-Log – **umgesetzt**.
- SEO-Head (Phase 5): Title `esc_html`, Meta-Inhalte `esc_attr`, Canonical/`og:url`/Bilder `esc_url`; JSON-LD per `wp_json_encode` mit `JSON_HEX_TAG|AMP|APOS|QUOT` (kein `</script>`-Ausbruch). Alle SEO-Textwerte zusätzlich markup-frei (`SeoService::plain()`), Bild-URLs nur gültiges HTTPS ohne Anführungszeichen/Klammern/Leerraum – auch für Werte, die an Yoast/Rank Math gehen. XSS-Payloads direkt im Store in Core-, Yoast- und Rank-Math-Modus nicht ausführbar – **getestet (HTTP)**.
- SEO-Datenschutz: bei `hide_address` keine Straße/Koordinaten in Title, Description, OG oder JSON-LD; keine privaten/Exposé-ausgeschlossenen Bilder, keine Grundrisse als `og:image`; `offeredBy` nur aus Website-Daten, kein Makler – **getestet**.

- RC-Abnahme 2026-10-06: Admin-Aktionen (Einstellungsseite, `admin-post` Sync/Status, `options.php`) anonym → Login/400, als Abonnent → 403; Webhook ohne/falscher Token 401, GET 404, korrekt 202 ohne API-Request im Request; ungültige IDs → 404 (kein Soft-404) – **getestet**.

## Eingaben, Berechtigungen, Nonces

- Einstellungen über Settings API mit `sanitize_callback`, Capability `manage_options` – **umgesetzt**.
- Admin-Aktionen (Sync, Statusliste) über `admin-post.php` mit `check_admin_referer` + `current_user_can( 'manage_options' )` – **umgesetzt**.
- Shortcode-Attribute werden validiert/gewhitelistet; sie können nur einschränken, nie nicht-öffentliche Objekte freischalten (kein `status`-Attribut) – **umgesetzt, getestet**.
- SQL ausschließlich über `$wpdb->prepare` bzw. `$wpdb->insert/update`; Sortierung nur aus fester Whitelist – **umgesetzt, getestet**.
- REST: Prüfung im `permission_callback`, nur POST – **umgesetzt**.
- Direkter Dateiaufruf: Templates/Bootstrap mit `ABSPATH`-Guard; Klassen-Dateien enthalten nur Deklarationen.

## Formulare / Leads (Phase 4, umgesetzt)

- Nur das konfigurierte CF7-Formular wird verarbeitet; andere Formulare bleiben unverändert – **getestet**.
- Property-Zuordnung nie allein über das Hidden Field: Ziffern-Prüfung → `PropertyStore` → `RouteResolver` (nur aktiv/reserviert); Titel/URL/Objektdaten nur aus dem Store – **getestet** (manipulierte IDs: verkauft, entfernt, nicht öffentlich, unbekannt, nicht numerisch).
- Header-Injection: Empfänger/BCC nur aus validierten Einstellungen; Zeilenumbrüche in einzeiligen Feldern entfernt; E-Mail-Validierung durch CF7 und Plugin – **getestet**.
- XSS: alle Werte im Mailblock HTML-escaped; CF7-Spezialtags in Eingaben werden nicht ausgewertet – **getestet**.
- Spam: CF7-Prüfungen + Honeypot `psl_hp_website`; Rate-Limit 5/10 min je HMAC(IP, `wp_salt`) als kurzlebiger Transient, keine Klartext-IP, keine ungeprüften Proxy-Header – **getestet**.
- Fehler: Besucher sehen nur eine neutrale Meldung; Logs nur Lead-ID, Property-ID, Status, Code – **getestet** (keine Namen/E-Mail/Telefon/Nachricht/IP).
- Kein Lead-Archiv im Plugin – **getestet** (keine Anfrageinhalte in Optionen/Posts/Postmeta).
- Propstack-Empfangsadresse nie im Frontend/JavaScript.

## Tracking (Geplant, Phase 6)

Keine PII im dataLayer/Analytics/Logs; Cookies nur nach Consent; Plugin lädt kein GTM.

## Logging

Nur bei `WP_DEBUG_LOG`; Inhalte: Sync-Zusammenfassungen, Fehlerkategorien, Objekt-IDs. Keine Keys, Tokens, Namen, E-Mails, Telefonnummern, Adressen, Nachrichten.

## Entwicklungsumgebung

- Projekt liegt im XAMPP-Webroot → `.htaccess`-Sperren beachten, wenn neue Dateien hinzukommen.
- `vendor/` (PHPUnit) wird nie ausgeliefert; Deployment-Paket ohne `vendor/`, `tests/`, `composer.*`, `phpunit.xml.dist`.
- Integrationstests nur gegen Wegwerf-Instanzen (sie leeren die Tabelle).
