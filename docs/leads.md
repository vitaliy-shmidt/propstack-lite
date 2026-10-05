# Leads (Immobilienanfragen)

> **Status: Geplant (Phase 4).** Noch nichts implementiert. Entscheidung: [ADR 003](decisions/003-cf7-propstack-leads.md). Keine echten Lead-Daten in diesem Dokument.

## Weg für Version 1: Contact Form 7 → Propstack-Mailintegration

```
Detailseite ─► CF7-Formular (Rendering + Mailversand durch CF7)
                 │  Hidden Field psl_property_id (nur als Hinweis, wird serverseitig geprüft)
                 ▼
          wpcf7_before_send_mail / wpcf7_spam (Plugin-Hooks)
                 │  - property_id muss im PropertyStore öffentlich sein
                 │  - Titel, URL, Objektart, Vermarktungsart, Makler AUS DEM STORE (nie aus Browserfeldern)
                 │  - Honeypot, Rate-Limit, Längen-/Formatprüfung
                 ▼
          Mail-Tag [psl-propstack-block] → HTML-Container id="ps-kontaktanfrage"
                 ▼
          Mail an Propstack-Empfangsadresse (Einstellung) ─► Automatisierung „Neue Portalanfrage“
```

CF7 wird nicht verändert oder geforkt; Integration nur über offizielle Hooks (`wpcf7_form_hidden_fields`, `wpcf7_spam`, `wpcf7_before_send_mail`, `wpcf7_special_mail_tags`).

## Propstack-Format `ps-kontaktanfrage` (laut Doku)

Container mit exakt `id="ps-kontaktanfrage"`, Werte in `<span id="…">`. Unterstützte IDs:

| ID | Inhalt | Quelle im Plugin |
|---|---|---|
| `client_salutation` | `mr` / `ms` | optionales Formularfeld |
| `client_first_name`, `client_last_name` | Name | Formular |
| `client_email`, `client_phone` | Kontakt | Formular |
| `body` | Nachricht | Formular |
| `property_id` | Propstack-Objekt-ID | **Store** (validiert) |
| `project_id` | Projekt-ID | Store, falls vorhanden |
| `client_accept_contact` | Kontakterlaubnis ja/nein | nur bei entsprechender Einwilligung |
| `client_newsletter`, `client_property_mailing_wanted` | Marketing-Einwilligungen | **nicht im ersten Release** |
| `client_cf_<feld>` | Custom Fields (z. B. UTM) | erst nach Anlage in Propstack, per Einstellung aktivierbar |

Weitere dokumentierte IDs (Adresse, Sprache, Status, Suchprofil-Felder `query_*`) werden vorerst nicht genutzt. Das Musterdokument `anfrage-muster.html` der Propstack-Doku wurde **nicht eingesehen** (nur als Download verlinkt).

## Formularfelder Version 1

Vorname, Nachname, E-Mail, Telefon, Nachricht, Datenschutzbestätigung (Pflicht). Objekt-ID, -Titel und -URL ergänzt das Plugin serverseitig. **Keine vorausgewählten Marketing-Checkboxen**; Newsletter/Immobilien-Mailing später nur als getrennte, nie vorausgewählte Einwilligungen mit getrennten Propstack-Feldern.

## Geplante Einstellungen

- Propstack-Empfangsadresse (keine Hartkodierung)
- CF7-Formular-ID
- Aktivierung der `client_cf_*`-Übertragung je UTM-Feld (erst nach Anlage der Custom Fields in Propstack)
- Admin-Hinweis, solange Adresse oder Formular-ID fehlen

## Sicherheit

Nonce/CF7-Mechanismen, serverseitige Validierung, Honeypot, Rate-Limit pro gehashter IP (z. B. 5 je 10 min), Spam-Schutz über CF7 (Akismet/Turnstile optional), Escaping aller Werte im Mail-HTML, Logging nur von `lead_id`, `property_id` und Status – keine personenbezogenen Daten.

## Abstraktion `LeadSink`

```
interface LeadSink { public function submit( Lead $lead ): LeadResult; }
Cf7MailLeadSink        – Version 1 (Mail im ps-kontaktanfrage-Format)
PropstackApiLeadSink   – später optional: POST /v1/contacts, /v1/activities, /v1/client_properties
```

## Offene Punkte (fachlich, vor Phase 4)

- konkrete, mit Propstack verbundene Empfangsadresse
- Automatisierung „Neue Portalanfrage“ aktiv? Welche Quelle soll gesetzt werden (Mechanismus in der Doku nicht beschrieben)?
- Custom Fields für UTM/GCLID in Propstack anlegen
- Ende-zu-Ende-Test erzeugt einen echten Kontakt in Propstack → nur nach Freigabe, mit Testkontakt
