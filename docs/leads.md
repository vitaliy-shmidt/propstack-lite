# Leads (Immobilienanfragen)

Stand: Phase 4 (2026-10-05) – **implementiert**: Contact Form 7 → formatierte E-Mail → Propstack. Entscheidung: [ADR 003](decisions/003-cf7-propstack-leads.md). Keine echten Lead-Daten in diesem Dokument.

Getestet mit **Contact Form 7 6.1.7** (WordPress 7.1.2). Ein echter Ende-zu-Ende-Test mit dem Propstack-Postfach steht **noch aus** (erzeugt einen Kontakt im CRM → nur nach ausdrücklicher Freigabe, siehe unten).

## Ablauf

```
Detailseite (aktiv/reserviert)
  └─ Hook psl_property_contact → [contact-form-7 id="…"] (CF7 rendert, validiert, versendet)
       Hidden Fields: psl_property_id, psl_lead_id  ·  Honeypot: psl_hp_website
CF7-Submission (AJAX/REST oder klassisch)
  ├─ CF7-Validierung, Acceptance, CF7-Spamprüfung (+ Honeypot über wpcf7_spam)
  └─ wpcf7_before_send_mail (nur konfiguriertes Formular)
       1. Konfiguration vollständig?                       sonst abort (not_configured)
       2. Rate-Limit (HMAC der Client-IP, 5 / 10 min)       sonst abort (rate_limited)
       3. Property-ID → absint → PropertyStore → RouteResolver: anfragbar?   sonst abort
       4. Formularwerte normalisieren, Zustimmung prüfen     sonst abort
       5. LeadContext (Objektdaten nur aus dem Store) → Cf7MailLeadSink
          Mail 1: Empfänger = Propstack-Adresse, HTML, Body enthält [_psl_propstack_block]
  └─ CF7 versendet → wpcf7_mail_sent → Log „sent“, Action psl_lead_sent
Propstack-Postfach → Automatisierung „Neue Portalanfrage“ (Konfiguration in Propstack, siehe unten)
```

Klassen: `Leads\Cf7Integration` (Hooks), `Leads\LeadContextFactory` (Verifikation/Normalisierung), `Leads\LeadContext`/`LeadData` (unveränderlich), `Leads\InquiryMailFormatter` (HTML-Block), `Leads\RateLimiter`, `Leads\LeadSink` (Interface) mit `Leads\Cf7MailLeadSink`, `Leads\LeadSetupCheck` (Admin-Status), `Leads\LeadException` (Fehlercodes).

## Entscheidung: kleinster stabiler Eingriff in CF7

Geprüft wurden Mail-Properties, Mail-Tag-Replacement und Submission-Hooks (CF7-Quellcode 6.1.7):

- **Nur das konfigurierte Formular** wird angefasst; jeder Hook prüft zuerst die Formular-ID und kehrt sonst unverändert zurück (getestet mit einem zweiten Formular).
- **`wpcf7_before_send_mail`** (offiziell mit `$abort`): Prüfungen; bei Fehlern `$abort = true` + neutrale Meldung über `WPCF7_Submission::set_response()`.
- **Mail-Properties nur im Speicher** für diese Anfrage (`set_properties` vor dem Versand; CF7 liest `prop('mail')` erst danach): Empfänger = Propstack-Adresse aus den Einstellungen, `use_html = true`, optional BCC. Nichts wird an CF7 gespeichert; Mail 2 (Autoresponder), Absender und übrige Header bleiben CF7-Konfiguration.
- **Spezial-Mail-Tag `[_psl_propstack_block]`** (`wpcf7_special_mail_tags`): CF7 ersetzt Tags in einem Durchgang und durchsucht die Ersetzung nicht erneut → Benutzereingaben können keine CF7-Tags (z. B. `[_site_admin_email]`) auslösen. Fehlt der Tag im Mail-Body, stellt das Plugin ihn voran. Empfohlener Body des Formulars: nur `[_psl_propstack_block]`.
- Daten über `WPCF7_Submission::get_posted_data()`, `get_meta('remote_ip')`, `get_contact_form()` – keine privaten CF7-Properties, kein direktes `$_POST`.

## Benötigtes CF7-Formular

Feldnamen sind im Admin zuordenbar (**Feldzuordnung**); Standard:

| Zweck | Standard-Feldname | Beispiel-Tag | Pflicht |
|---|---|---|---|
| Anrede | – (leer = nicht genutzt) | `[select your-salutation "Herr" "Frau"]` | nein |
| Vorname | `your-first-name` | `[text* your-first-name]` | ja |
| Nachname | `your-last-name` | `[text* your-last-name]` | ja |
| E-Mail | `your-email` | `[email* your-email]` | ja |
| Telefon | `your-phone` | `[tel your-phone]` | Feld muss existieren (Wert optional, im Formular per `tel*` erzwingbar) |
| Nachricht | `your-message` | `[textarea your-message]` | Feld muss existieren |
| Zustimmung | `psl-consent` | `[acceptance psl-consent] Ich bin mit der Verarbeitung meiner Angaben zur Bearbeitung der Anfrage einverstanden. [/acceptance]` | ja, **muss `acceptance` sein** |

**Keine** Newsletter- oder Immobilien-Mailing-Checkboxen (nicht freigegeben). Mail-Einstellungen im CF7-Formular: Betreff frei, Absender = Adresse der eigenen Domain, Body `[_psl_propstack_block]`, empfohlen `Reply-To: [your-email]`. Der Empfänger im Formular wird durch die Plugin-Einstellung ersetzt. Besucher-Meldungen (Erfolg, Fehler) kommen aus den CF7-Formularmeldungen (dort auf Deutsch pflegen); bei abgelehnten Anfragen setzt das Plugin: „Die Anfrage konnte leider nicht versendet werden. Bitte versuchen Sie es später erneut.“

## Einstellungen (Einstellungen → Propstack Lite → Immobilienanfragen)

| Einstellung | Validierung |
|---|---|
| Contact-Form-7-Formular | Integer; Formular muss existieren (sonst 0 + Hinweis) |
| Propstack-Anfrage-E-Mail-Adresse | `sanitize_email` + `is_email`, nie hartkodiert, nie im Browser |
| Interne Kopie (BCC, optional) | wie oben |
| Feldzuordnung | CF7-Feldnamen `[a-zA-Z][0-9a-zA-Z:._-]*` |
| Propstack-Custom-Fields (optional) | je Schlüssel `lead_id`, `utm_*`, `gclid`, `gbraid`, `wbraid` ein Propstack-Feldname `[a-z0-9_]`; leer = wird nicht gesendet |

**Status-Box** „Immobilienanfragen – Status“: Contact Form 7 erkannt/fehlt, Formular gültig/fehlt, Zieladresse gesetzt/fehlt, Formularfelder vollständig/fehlend (inkl. Prüfung `acceptance`), Lead-Integration bereit/nicht vollständig. Ohne CF7 zusätzlich Admin-Hinweis „Contact Form 7 ist nicht aktiv. Immobilienanfragen sind derzeit deaktiviert.“ (nur Administratoren).

**Unvollständige Konfiguration / CF7 fehlt:** Die Detailseite zeigt im Kontaktbereich statt des Formulars den neutralen Hinweis „Bitte kontaktieren Sie uns telefonisch oder per E-Mail …“ (Ansprechpartner-Daten stehen darüber). Keine technischen Fehlertexte, keine PHP-Warnungen (getestet mit deaktiviertem CF7).

## Wann gibt es ein Formular?

| Objektzustand | Formular | Serverseitige Annahme |
|---|---|---|
| öffentlich | ja | ja |
| reserviert | ja | ja |
| verkauft/vermietet (30-Tage-Phase) | nein – Hinweis „Diese Immobilie ist bereits verkauft/vermietet.“ | **abgelehnt** |
| 410 / entfernt / Status nicht mehr öffentlich | nein | **abgelehnt** |
| unbekannte ID | – | **abgelehnt** |

## Propstack-Mailformat `ps-kontaktanfrage`

Erzeugt von `InquiryMailFormatter` (alle Werte HTML-escaped, Zeilenumbrüche der Nachricht als `<br>`):

```html
<div id="ps-kontaktanfrage">
<p>Anrede: <span id="client_salutation">mr</span></p>            <!-- nur wenn angegeben -->
<p>Vorname: <span id="client_first_name">Max</span></p>
<p>Nachname: <span id="client_last_name">Mustermann</span></p>
<p>E-Mail: <span id="client_email">max.mustermann@example.com</span></p>
<p>Telefon: <span id="client_phone">+49 30 1234567</span></p>    <!-- nur wenn angegeben -->
<p>Kontakterlaubnis: <span id="client_accept_contact">ja</span></p>  <!-- nur bei Zustimmung -->
<p>Sprache: <span id="client_locale">de</span></p>               <!-- aus WordPress-Locale (de/en/es) -->
<p>Objekt-ID: <span id="property_id">12345</span></p>             <!-- aus dem PropertyStore -->
<p>Projekt-ID: <span id="project_id">678</span></p>               <!-- nur wenn vorhanden -->
<p>website_lead_id: <span id="client_cf_website_lead_id">…</span></p>  <!-- nur mit Zuordnung -->
<p>Nachricht:</p>
<p><span id="body">…</span></p>
</div>
<p>Anfrage zur Immobilie: {Titel aus dem Store}<br><a href="{kanonische URL}">…</a></p>
```

- `body` enthält nur die Nachricht; Titel und URL stehen lesbar **außerhalb** des Containers. Die Zuordnung in Propstack erfolgt über `property_id`.
- `client_accept_contact` = „ja“ nur bei tatsächlich gesetzter Zustimmung (sonst wird die Anfrage abgelehnt).
- Nicht gesendet: `client_newsletter`, `client_property_mailing_wanted`, Adressfelder, Suchprofil-Felder.
- Hinweis: CF7 bettet den HTML-Body in ein HTML-Grundgerüst und normalisiert Leerraum; IDs und Inhalte bleiben unverändert (im Browser-Test geprüft).
- Das Propstack-Musterdokument `anfrage-muster.html` wurde nicht eingesehen; das Format folgt der Feldtabelle der Propstack-Doku.

## Property-Verifikation

Die Zuordnung beruht nie allein auf dem Hidden Field `psl_property_id`: Wert → nur Ziffern (`absint`-Logik) → `PropertyStore::find()` → `RouteResolver` muss „anfragbar“ (aktiv/reserviert) ergeben. Titel, URL, Objektart, Projekt usw. kommen aus dem Store; vom Browser gesendete Zusatzfelder werden ignoriert (getestet mit gefälschten Titel-/URL-Feldern). Die vom Browser gesendete Objekt-ID kann auf ein anderes, ebenfalls anfragbares Objekt zeigen – das ist zulässig, weil es eine echte, öffentliche Anfrage zu diesem Objekt ist.

## Lead-ID

- Jede Formularausgabe erhält `psl_lead_id` (UUID v4, `wp_generate_uuid4()`), keine personenbezogenen Daten.
- Server: nur gültiges UUID-v4-Format und noch nicht verwendet (Transient `psl_lead_used_{id}`, 1 Tag) wird übernommen, sonst neu erzeugt. Damit bleiben Lead-IDs eindeutig, auch bei mehrfachem Absenden derselben Seite oder bei **Full-Page-Caching** (dort hätten alle Besucher dieselbe vorgerenderte ID). Für Phase 6 ist eine clientseitige Erzeugung vorgesehen.
- Verwendung: Log-Korrelation, optional Propstack-Custom-Field (`client_cf_…` nur mit Zuordnung), später Analytics-Deduplizierung.

## Spam- und Missbrauchsschutz

- CF7-eigene Prüfungen bleiben vorrangig (User-Agent-Prüfung, Nonce für angemeldete Nutzer, Disallowed List, optional Akismet/Turnstile/reCAPTCHA über CF7).
- **Honeypot** `psl_hp_website` (per `wpcf7_form_elements` nur am Immobilienformular, visuell und für Screenreader verborgen); ausgefüllt → `spam`.
- **Rate-Limit:** max. 5 Anfragen je 10 Minuten pro Client. Schlüssel = HMAC-SHA256(Client-IP, `wp_salt('nonce')`), gespeichert nur als kurzlebiger Transient `psl_rl_{hash}`; keine Klartext-IP. IP-Quelle ist CF7 (`REMOTE_ADDR`, filterbar über `wpcf7_remote_ip_addr`) – Proxy-Header wie `X-Forwarded-For` werden nicht ungeprüft übernommen. Hinter einem Reverse Proxy/CDN muss der Server `REMOTE_ADDR` korrekt setzen (oder ein vertrauenswürdiger Filter).

## Sicherheit der Mail

- Empfänger/BCC ausschließlich aus validierten Einstellungen; Benutzereingaben nie in `To`/`Cc`/`Bcc`/`From`.
- Einzeilige Felder werden von Zeilenumbrüchen/Steuerzeichen befreit; E-Mail muss gültig sein (CF7 und Plugin). Eingeschleuste `Bcc:`-Zeilen erscheinen nicht in den Headern (getestet).
- HTML-Escaping aller Werte; `<script>`, Event-Handler und zusätzliche `ps-kontaktanfrage`-Container aus Eingaben werden neutralisiert (getestet).

## Fehlerverhalten und Logging

| Code (nur Log) | Ursache | Besucher sieht |
|---|---|---|
| `not_configured` | Formular/Adresse/Felder unvollständig | neutrale Fehlermeldung |
| `rate_limited` | Limit erreicht | neutrale Fehlermeldung |
| `property_missing` / `property_not_found` / `property_not_inquirable` | ID fehlt/unbekannt/nicht anfragbar | neutrale Fehlermeldung |
| `consent_missing` / `invalid_input` | Zustimmung/Pflichtangaben | neutrale Fehlermeldung (CF7 meldet Pflichtfelder/Acceptance meist schon vorher) |
| `mail_failed` | Mailversand fehlgeschlagen | CF7-Meldung „mail_sent_ng“ |

Log (nur mit `WP_DEBUG_LOG`): `[propstack-lite] INFO lead {"lead":"<uuid>","property":12345,"status":"sent"}` – Status `prepared`, `sent`, `aborted` (+ `code`), `mail_failed`, `spam` (Honeypot). **Nie** Namen, E-Mail, Telefon, Nachricht, IP, Marketing-IDs (getestet).

## Datenschutz

- Kein eigenes Lead-Archiv: Propstack Lite speichert keine Anfrageinhalte (getestet: keine Werte in `wp_options`, `wp_posts`, `wp_postmeta`). Gespeichert werden nur der Rate-Limit-Hash (10 min) und verwendete Lead-IDs (1 Tag).
- Flamingo oder andere CF7-Erweiterungen werden nicht verändert; ob dort Anfragen gespeichert werden, entscheidet deren Konfiguration.

## Erweiterungspunkte

- `LeadSink`-Interface: später `PropstackApiLeadSink` (POST /v1/contacts, /v1/activities) oder Hybrid – **nicht implementiert**, kein schreibender API-Zugriff.
- Action `psl_lead_sent` (`LeadContext`) nach erfolgreichem Versand (serverseitig).
- Filter `psl_inquiry_form_available`.
- **Phase 6 (Tracking):** Für Conversion-Events kann das CF7-DOM-Event `wpcf7mailsent` genutzt werden – es feuert nur bei AJAX-Submissions und nur bei Status `mail_sent`. Die Zustellung der Anfrage hängt **nie** von diesem Browser-Event ab; maßgeblich ist die serverseitige Verarbeitung. Lead-ID (`psl_lead_id`) und Property-ID stehen im Formular zur Verfügung. Noch kein dataLayer, kein GA4/Ads, keine UTM-Cookies.

## Mail-Zustellbarkeit (Produktion)

Das Plugin enthält keinen Mailtransport. Für den Produktivbetrieb erforderlich: SMTP bzw. Transaktionsmail-Dienst in WordPress/Server, korrekte SPF-, DKIM- und sinnvolle DMARC-Einträge für die Absenderdomain; Absender im CF7-Formular = Adresse dieser Domain.

## Außerhalb des Plugins einzurichten (Propstack) – **nicht verifiziert**

- [ ] Mit Propstack verbundenes Empfangspostfach (dessen Adresse in den Plugin-Einstellungen eintragen).
- [ ] Automatisierung „Neue Portalanfrage“ aktiv und für dieses Postfach wirksam.
- [ ] Kontaktquelle für Website-Anfragen und korrekte Absender-/Quellenzuordnung (Mechanismus in der Propstack-Doku nicht beschrieben – in Propstack klären).
- [ ] Optional: Custom Fields für `lead_id`/UTM/GCLID anlegen und im Plugin zuordnen.

## Ende-zu-Ende-Test (bereit, **nur nach ausdrücklicher Freigabe**)

Erzeugt einen Kontakt/eine Anfrage im Propstack-CRM.

1. Propstack-Konfiguration oben erledigt; Testobjekt öffentlich.
2. Staging mit funktionierendem Mailversand; Plugin-Einstellungen: Formular, Propstack-Adresse; Status-Box „bereit“.
3. Auf der Detailseite eine Anfrage mit eindeutig erkennbaren **Testdaten** senden (z. B. Vorname „E2E-Test“, eigene Test-Mailadresse), Lead-ID im Formular notieren.
4. Prüfen in Propstack: Kontakt angelegt/zugeordnet, Objekt verknüpft, Nachricht übernommen, Quelle korrekt, Zustimmung gesetzt, ggf. Custom Field mit Lead-ID.
5. Testkontakt anschließend in Propstack entfernen.

Vorgaben der Freigabe (2026-10-06): genau **ein** synthetischer Testkontakt („PSL Integrationstest“, Nachricht mit „TEST – Propstack Listings Lite“), nur die Kontaktzustimmung, keine Marketing-Opt-ins; Testkontakt nicht per API löschen oder ändern; bei Fehlschlag keine weiteren Versuche. In Git werden nur „synthetischer Testkontakt“ und keine echten Adressen dokumentiert.

### Protokoll

| Datum | Ergebnis | Workflow | Zuordnung | Quelle | Besonderheiten |
|---|---|---|---|---|---|
| 2026-10-06 | **Nicht durchgeführt** – keine Anfrage gesendet | nicht verifizierbar | nicht verifizierbar | nicht verifizierbar | Voraussetzungen nicht prüfbar, siehe unten |

Gründe (Prüfung vor dem Senden, nur lesend):

- **Empfangsadresse unbekannt:** In der Testinstanz ist nur eine Platzhalter-Adresse (`…@example.test`) hinterlegt; die echte, mit Propstack verbundene Postfachadresse liegt nicht vor.
- **Kein Prüfzugriff auf das CRM:** Der vorhandene API-Key ist auf Objekte/Status/Projekte beschränkt (`contacts`, `contact_sources`, `activity_types`, `hooks`, `brokers` → 401). Postfachverbindung, Automatisierung „Neue Portalanfrage“, Kontaktquelle und das Ergebnis (Kontakt/Zuordnung) wären nicht überprüfbar.
- **Kein Mailtransport** in der lokalen Testinstanz (PHP `mail()` ohne SMTP).
- Erfüllt: Formular und Plugin-Einstellungen „bereit“, Testobjekt öffentlich und anfragbar.

Nachholen, sobald Propstack-Postfachadresse bekannt, Staging mit SMTP vorhanden und eine Prüfung in Propstack (UI oder Key mit Lese-Rechten auf Kontakte) möglich ist.
