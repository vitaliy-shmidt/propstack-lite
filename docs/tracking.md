# Tracking (Kampagnen-Attribution und Conversion-Events)

> **Status: Geplant (Phase 6).** Noch nichts implementiert.

**Grundregel: Keine personenbezogenen Daten im dataLayer, in Analytics oder in Logs.** Name, E-Mail, Telefon und Nachricht verlassen nie das Formular/Mail-System.

## Erfasste Parameter

`utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `gclid`, `gbraid`, `wbraid`, erste Landingpage (nur Pfad), Referrer (nur Domain). Werte werden gewhitelistet und längenbegrenzt; keine IP, kein User-Agent, keine Client-IDs.

## First Touch und Last Non-Direct Touch

- **First Touch:** erster Besuch mit Kampagnen- bzw. Referrer-Informationen, wird nicht überschrieben.
- **Last Non-Direct Touch:** letzter Besuch mit Kampagnen-/Referrer-Information; Direktaufrufe überschreiben ihn nicht.

## Consent

- Abstraktion `ConsentProviderInterface` (z. B. `hasMarketingConsent()`, `onConsentChange()`); Adapter für das tatsächlich eingesetzte Consent-Tool (noch nicht festgelegt).
- Ohne erkanntes/konfiguriertes Consent-System: **kein** Setzen nicht notwendiger Tracking-Cookies.
- Das Plugin lädt **kein GTM** und blockiert GTM/dataLayer nicht.
- Immobilienseiten und Kontaktformular funktionieren vollständig ohne Marketing-Consent.

## Cookie-Struktur (Entwurf)

First-Party-Cookie `psl_attr`, Laufzeit 90 Tage, `SameSite=Lax`, `Secure`, nur nach Consent:

```json
{ "v": 1,
  "first": { "utm_source": "google", "utm_medium": "cpc", "utm_campaign": "…", "gclid": "…", "landing": "/immobilien/", "ref": "google.com", "ts": 1759660000 },
  "last":  { "…": "…" } }
```

Clientseitig gesetzt (kompatibel mit Full-Page-Cache); serverseitig nur gelesen und validiert.

## Übergabe an die Anfrage

JavaScript befüllt Hidden Fields des CF7-Formulars; der Server validiert. Übertragung an Propstack als `client_cf_*` erst, wenn die Custom Fields in Propstack angelegt und in den Einstellungen aktiviert sind (siehe [leads.md](leads.md)).

## Conversion-Event

Nur nach nachweislich erfolgreicher Übermittlung (CF7-DOM-Event `wpcf7mailsent`; nicht bei Klick, Validierungsfehler, Spam oder `mail_failed`):

```js
dataLayer.push({
  event: 'property_lead',
  lead_id: '<zufällige UUID>',
  property_id: 12345,
  property_type: 'APARTMENT',
  marketing_type: 'BUY',
  property_city: 'Berlin',
  lead_type: 'property_inquiry'
});
```

- **Deduplizierung:** `lead_id` je Absendung, Merkliste gesendeter IDs in `sessionStorage`; neue `lead_id` nach Erfolg.
- **GA4:** in GTM `property_lead` → empfohlenes Event `generate_lead`.
- **Google Ads:** Conversion-Tag mit `transaction_id = lead_id` (Google dedupliziert zusätzlich).
- GCLID im Propstack-Kontakt ermöglicht später Offline-Conversion-Import. Enhanced Conversions (gehashte E-Mail) sind bewusst nicht vorgesehen.
