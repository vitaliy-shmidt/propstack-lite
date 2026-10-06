# Tracking: Attribution und Conversion-Event

Stand: Phase 6 (2026-10-06, Version 0.5.0), **implementiert**. Standard: **aus**. Getestet lokal (WordPress 7.1.2, CF7 6.1.7, Edge); **nicht** auf der DomainFactory-Staging-Instanz und nicht mit einem realen Consent-Tool.

**Grundregeln**
- Keine personenbezogenen Daten in Attribution, dataLayer oder Logs (kein Name, keine E-Mail, kein Telefon, keine Nachricht, keine Adresse, keine IP, kein User-Agent, kein Fingerprinting).
- Ohne Marketing-Consent: keine Attribution, kein Cookie, kein Event – Formular und Anfrage funktionieren unverändert.
- Das Plugin lädt **kein** GTM, GA4, Google-Ads-Skript oder Meta Pixel und sendet nichts an Dritte. Es stellt nur Daten im `dataLayer` bereit.
- Tracking ist nie Voraussetzung für die Anfrage; das Lead-Handling bleibt serverseitig.

## Architektur

```
Besucher (jede Seite) ──► assets/js/psl-tracking.js          Consent-Prüfung, Attribution → Cookie psl_attr
Detailseite mit Formular ─► assets/js/psl-lead-event.js       Hidden Field psl_attr (bei Consent), Event property_lead
CF7-Absenden ──► Cf7Integration (Phase 4) ──► Filter psl_lead_attribution ──► Tracking\TrackingIntegration
                  │                                              (validiert psl_attr → Propstack client_cf_*)
                  └─ wpcf7_mail_sent → psl_lead_sent ──► wpcf7_feedback_response: psl_lead (öffentliche Daten)
                                                          └─► psl-lead-event.js → dataLayer.push(property_lead)
```

| Klasse / Datei | Aufgabe |
|---|---|
| `Tracking\TrackingIntegration` | Skripte einbinden, Hidden Field, Attribution für die Mail, Event-Daten in der CF7-Antwort |
| `Tracking\Touch` | ein Marketingkontakt, Bereinigung aller Werte (identische Regeln wie im JS) |
| `Tracking\Attribution` | First Touch + Last Non-Direct, Abbildung auf Propstack-Felder |
| `Tracking\AttributionStorage` | Format, Version, TTL, Validierung (Cookie bzw. Hidden Field) |
| `Tracking\Consent\ConsentProviderInterface` | Anbindung eines Consent-Systems; `NoConsentProvider` (Standard), `JsApiConsentProvider`; Registry `ConsentProviders` (Filter `psl_consent_providers`) |
| `Tracking\LeadEvent` | serverseitig bestätigte Event-Daten (nur öffentliche Objektdaten + Lead-ID) |
| `assets/js/psl-tracking.js` | seitenweit, ~8 KB roh / 3,2 KB gzip, Vanilla JS, keine Abhängigkeiten, keine Requests |
| `assets/js/psl-lead-event.js` | nur Detailseiten mit Anfrageformular, ~3,9 KB / 1,7 KB gzip |

**Warum seitenweit?** Kampagnen landen oft auf der Startseite oder Landingpages ohne Immobilie. `psl-tracking.js` enthält deshalb nur Consent- und Attributionslogik (keine Objekt-Logik) und wird nur geladen, wenn Attribution oder dataLayer-Event aktiviert **und** ein Consent-Provider mit Marketing-Fähigkeit gewählt ist. `defer`, im Footer, Konfiguration als Inline-JSON (`window.pslTrackingConfig`, cache-kompatibel).

## Erfasste Daten

| Wert | Quelle | Regel |
|---|---|---|
| `utm_source`, `utm_medium` | URL | bereinigt, kleingeschrieben, max. 100 Zeichen |
| `utm_campaign`, `utm_content`, `utm_term` | URL | bereinigt (Steuerzeichen, `<>"'\`\\` entfernt), max. 100 Zeichen |
| `gclid`, `gbraid`, `wbraid` | URL | nur `[A-Za-z0-9_.-]`, max. 255 Zeichen, sonst verworfen |
| Landingpage | `location.pathname` | **nur Pfad**, kein Querystring |
| Referrer | `document.referrer` | **nur Domain** (ohne `www.`), eigene Domain = interne Navigation |
| Kanal | abgeleitet | `paid_search`, `organic_search`, `paid_social`, `organic_social`, `referral`, `other` (direkt = kein Touch) |
| Zeitstempel | Browser | Unix-Sekunden |

**Klassifikation (einfach, UTM hat Vorrang):** `utm_medium` cpc/ppc/paid/sea/cpm → `paid_search` (bei Social-Quelle `paid_social`); paid_social/paidsocial → `paid_social`; social/sm → `organic_social`; organic → `organic_search`; referral → `referral`; sonst `other`. Nur Klick-ID ohne UTM → `google`/`cpc`/`paid_search`. Ohne UTM: Referrer Suchmaschine → `organic_search`, Social-Netzwerk → `organic_social`, andere Domain → `referral`.

## Attributionsmodell

- **First Touch:** erster erkannter nicht-direkter Kontakt; bleibt bestehen, bis er älter als die TTL ist.
- **Last Non-Direct Touch:** letzter nicht-direkter Kontakt; ein späterer Direktaufruf überschreibt ihn **nicht**.
- Beispiel (Browser-Test): Google Ads → später Facebook → später Direct → Lead ⇒ First = google/cpc/paid_search, Last = facebook/paid_social.

## Speicherung

- First-Party-Cookie **`psl_attr`**, ausschließlich clientseitig gesetzt (kein `Set-Cookie` aus PHP, Full-Page-Cache-kompatibel), `SameSite=Lax`, `Secure` bei HTTPS, Pfad = WordPress-Startseite (z. B. `/Picaflor/`), nicht `HttpOnly` (wird vom Skript gelesen).
- Inhalt: URL-kodiertes JSON `{"v":1,"f":{…},"l":{…}}` mit Kurzschlüsseln (`s`, `m`, `c`, `ct`, `t`, `g`, `gb`, `wb`, `ch`, `lp`, `rh`, `ts`); typ. 300–500 Byte, Obergrenze 2 KB.
- **TTL:** zentral `AttributionStorage::DEFAULT_TTL_DAYS = 90`, im Admin 1–365 Tage. Cookie-Laufzeit = TTL ab letztem Schreiben; jeder Touch verfällt TTL nach seinem Zeitstempel (Client und Server prüfen).
- Keine eigene Datenbanktabelle, keine serverseitige Speicherung der Attribution.

## Consent

`ConsentProviderInterface` (PHP) beschreibt das Consent-System; die eigentliche Prüfung erfolgt im Browser.

| Provider | Verhalten | Status |
|---|---|---|
| `none` – **Standard** | nie Marketing-Consent; keine Skripte, kein Cookie, kein Event, Server ignoriert `psl_attr` | getestet |
| `js_api` | Consent-Tool meldet die Marketing-Einwilligung auf **jeder** Seite | API getestet |

JavaScript-API (`js_api`):

```js
window.pslConsent = { marketing: true };                       // vor dem Plugin-Skript gesetzt, oder
window.PSLTracking.setConsent( true );                         // jederzeit, z. B. im Callback des Consent-Tools
document.dispatchEvent( new CustomEvent( 'psl:consent', { detail: { marketing: true } } ) );
window.PSLTracking.setConsent( false );                        // Widerruf
```

- Ohne Meldung gilt: **kein Consent**. Das Plugin speichert den Consent-Zustand nicht.
- Erteilung → der aktuelle Seitenaufruf wird ausgewertet (Landingpage), Cookie geschrieben.
- **Widerruf** (`setConsent(false)`) → Cookie wird sofort gelöscht, das Hidden Field geleert, keine Events. Meldet das Consent-Tool einen Widerruf nicht aktiv, bleibt der Cookie bis zum TTL-Ablauf bestehen, wird aber ohne erneute Consent-Meldung **weder gelesen noch übertragen** (Formular-Skript und Event prüfen den Consent zum Zeitpunkt der Verwendung).
- Consent-Provider-Wechsel auf `none` im Admin: Skripte werden nicht mehr geladen, der Server ignoriert Attributionsdaten; vorhandene Cookies verfallen nach TTL.

**Anbindungsbeispiele (nicht gegen reale Installationen getestet – auf Staging prüfen):**

```js
// Cookiebot
window.addEventListener( 'CookiebotOnConsentReady', function () { PSLTracking.setConsent( !! Cookiebot.consent.marketing ); } );
// Complianz
document.addEventListener( 'cmplz_status_change', function () { PSLTracking.setConsent( cmplz_has_consent( 'marketing' ) ); } );
// Borlabs Cookie 3 (Service-ID je nach Konfiguration)
// Real Cookie Banner: über die Service-Opt-in-/Opt-out-Skripte eines eigenen Services „Propstack Attribution“
// GTM (Consent Mode): Custom-HTML-Tag mit Trigger auf Consent-Update → PSLTracking.setConsent( true/false )
```

Konkrete Adapter (PHP-Klasse je Tool) sind vorbereitet über den Filter `psl_consent_providers`, aber **keiner ist als fertig markiert**.

## Conversion-Event `property_lead`

Auslöser: ausschließlich CF7-DOM-Event `wpcf7mailsent` des **konfigurierten** Immobilienformulars **und** serverseitig bestätigte Event-Daten in der CF7-Antwort (`apiResponse.psl_lead`, nur bei Status `mail_sent`) **und** Marketing-Consent **und** Option „dataLayer-Event“.

```js
dataLayer.push( {
  event: 'property_lead',
  lead_id: '52b4b72a-…',            // dieselbe Lead-ID wie im Formular, in der Mail und optional im Propstack-Custom-Field
  property_id: 4583559,
  marketing_type: 'BUY',            // BUY | RENT
  property_type: 'APARTMENT',       // Propstack rs_type
  property_city: 'Berlin',          // öffentlicher Ort (auch bei verborgener Adresse sichtbar)
  lead_type: 'property_inquiry',
  attribution: {                    // nur mit aktivierter Attribution und vorhandenen Daten
    first_source: 'google', first_medium: 'cpc', first_campaign: 'wohnung_berlin', first_channel: 'paid_search',
    last_source: 'facebook', last_medium: 'paid_social', last_campaign: 'retargeting', last_channel: 'paid_social'
  }
} );
```

**Nicht im dataLayer** (auch nicht gehasht): Vor-/Nachname, E-Mail, Telefon, Nachricht, Straße, Hausnummer, Koordinaten, Makler-Kontakt, `gclid`/`gbraid`/`wbraid`. Keine Enhanced Conversions.

**Kein Event bei:** Klick, Validierungsfehler, Spam/Honeypot, Rate-Limit, Mailfehler, verkauftem/ungültigem Objekt (Server bricht ab), anderem CF7-Formular, fehlendem Consent, deaktivierter Option (alle getestet).

**Deduplizierung:** pro Lead-ID höchstens einmal – `sessionStorage` (`psl_lead_events`, letzte 20 IDs) plus Speicher; mehrfach ausgelöste DOM-Events und Reload erzeugen kein zweites Event. Die Lead-ID ist serverseitig eindeutig (Phase 4); wird eine Seite erneut abgesendet (oder per Full-Page-Cache geteilt), vergibt der Server eine neue ID und das Event nutzt diese.

**GTM-Empfehlung (Website-Konfiguration):** Trigger „Benutzerdefiniertes Ereignis `property_lead`“ → GA4 `generate_lead`; Google-Ads-Conversion mit `transaction_id = lead_id`.

## Propstack-Übertragung (`client_cf_*`)

Einstellungen → Tracking & Attribution → „Propstack-Custom-Fields“. Nur zugeordnete Felder werden gesendet (Standard: keine). Das Plugin legt keine Felder in Propstack an.

| Schlüssel | Wert |
|---|---|
| `lead_id` | serverseitige Lead-ID (kein Marketingwert, auch ohne Consent) |
| `first_utm_source/medium/campaign` | First Touch |
| `last_utm_source/medium/campaign` | Last Non-Direct Touch |
| `utm_content`, `utm_term` | letzter Touch, sonst erster |
| `gclid`, `gbraid`, `wbraid` | letzter Touch, sonst erster – **nur hier**, nie im dataLayer, nie in Logs, nie im HTML |
| `landing_path`, `referrer_host` | First Touch |

Ablauf: `psl-lead-event.js` setzt das Hidden Field `psl_attr` **nur bei aktuellem Consent** (beim Laden, bei Consent-Änderung, direkt vor dem Absenden); der Server validiert es mit denselben Regeln (`AttributionStorage::parse`) und übernimmt nur die zugeordneten Felder. Ungültige oder manipulierte Werte werden bereinigt bzw. verworfen und blockieren die Anfrage nie. Alte Zuordnungen `utm_source/medium/campaign` (vor 0.5.0) gelten als `last_utm_*`.

## Einstellungen (Standard)

| Einstellung | Standard |
|---|---|
| Attribution | aus |
| dataLayer-Event | aus |
| Consent-Provider | `none` |
| Speicherdauer | 90 Tage |
| Propstack-Custom-Fields | leer |

Filter: `psl_consent_providers`, `psl_datalayer_name` (Standard `dataLayer`), `psl_lead_attribution`.

## Staging-Konfiguration (Picaflor)

1. Consent-Tool der Website festlegen und für „Propstack Attribution“ (Kategorie Marketing) die JS-API anbinden (siehe Beispiele) – erst danach Provider `js_api` wählen.
2. Attribution und dataLayer-Event aktivieren.
3. Im GTM den Trigger `property_lead` anlegen (Plugin lädt GTM nicht).
4. Propstack-Custom-Fields nur zuordnen, wenn sie in Propstack angelegt sind.
5. Prüfen: Landing mit `?utm_source=test&utm_medium=cpc` → Cookie `psl_attr` erst nach Consent; Anfrage → genau ein `property_lead` im dataLayer (GTM-Vorschau).
6. Echter Propstack-E2E-Test weiterhin **BLOCKED** bis Postfachadresse, Automatisierung, Quelle und Mailtransport bestätigt sind ([leads.md](leads.md)).

## SEO

UTM- und Klick-ID-Parameter beeinflussen Canonical, `og:url`, JSON-LD-URLs und Sitemap nicht (Regressionstests); keine Weiterleitungen zum Entfernen der Parameter.
