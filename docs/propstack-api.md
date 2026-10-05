# Propstack-API – Erkenntnisse

Stand: 2026-10-05. Grundlage: offizielle Doku (<https://docs.propstack.de>, Index `llms.txt`) und **ausschließlich lesende** Tests gegen den Account von Picaflor. Keine Secrets, keine personenbezogenen Daten in diesem Dokument.

## Version, Basis, Authentifizierung

- Verwendet: **API v1**, Basis `https://api.propstack.de/v1`.
- Auth: Header `X-API-KEY` (laut Doku alternativ Query-Parameter `api_key` – vom Plugin **nicht** verwendet, damit der Key nicht in URLs/Logs landet).
- Key-Ablage im Plugin: Konstante `PSL_API_KEY` (empfohlen) oder Option. Lokale Testzugänge liegen in `Propstack-API.txt` (gitignored, per `.htaccess` gesperrt).
- v2 existiert (Swagger: `https://api.propstack.de/v2/swagger_doc`, Version „0.1“), enthält u. a. `/publishings`, `/portals`, `/properties/deleted`, `/properties/scroll`. **Mit dem aktuellen Key: HTTP 403 auf alle v2-Endpunkte.**

## Rechte des aktuellen Keys (getestet)

| Endpoint | Ergebnis |
|---|---|
| `GET /v1/units`, `GET /v1/units/{id}` | ✅ 200 |
| `GET /v1/property_statuses` | ✅ 200 |
| `GET /v1/projects` | ✅ 200 |
| `GET /v1/contacts`, `/v1/client_properties`, `/v1/activity_types`, `/v1/hooks`, `/v1/brokers`, `/v1/contact_sources` | ❌ 401 „API-Key besitzt nicht genügend Rechte“ |
| alle v2-Endpunkte | ❌ 403 |

Folgen: keine Lead-Anlage per API mit diesem Key; Webhook-Registrierung (`POST /v1/hooks`) muss ein Propstack-Admin übernehmen.

## Relevante Endpunkte

| Zweck | Request |
|---|---|
| Objektliste | `GET /v1/units?with_meta=1&expand=1&per=100&page=N&sort_by=id&order=asc&status[]=…` |
| Einzelobjekt | `GET /v1/units/{id}?new=1` → 404 mit `{"errors":["Property mit der id=… konnte nicht gefunden werden."]}` |
| Objektstatus | `GET /v1/property_statuses` → `[{id, name, position, color, remove_from_portal, disable_expose_landing_page, exclude_from_search_profile_matching}]` |
| Webhooks (Doku) | `POST /v1/hooks` (`target_url`, `event`), Events u. a. `property_created/updated/deleted`; Signatur HMAC-SHA256 im Header `X-Propstack-Signature` (optional) |

## Pagination

- Parameter `per` (Doku: max. 500 empfohlen) und `page`. **`limit` wird ignoriert** (liefert immer 20).
- `with_meta=1` liefert nur `meta.total_count` – **kein** `pages`.
- **Ohne Sortierung instabil:** über 5 Seiten 5 Duplikate und 5 fehlende Objekte beobachtet. Mit `sort_by=id&order=asc` stabil. Plugin sortiert immer nach ID und dedupliziert zusätzlich.
- Seite jenseits des Endes: `data: []`.

## Sortierung und Filter (getestet)

| Parameter | Ergebnis |
|---|---|
| `sort_by=price&order=desc` | ✅ wirkt |
| `ordering=-created_at` | ❌ ignoriert (alter Plugin-Code nutzte das) |
| `status=ID`, `status[]=A&status[]=B`, `status=A,B` | ✅ (mehrere Status möglich) |
| `marketing_type=BUY|RENT`, `rs_type=…`, `city=…`, `zip_code=…` | ✅ |
| `price_from`/`price_to` | wirkt, liefert aber auch Mietobjekte mit `price = null` |
| `min_price`/`max_price` | ❌ ignoriert |
| `property_ids[]=…` | ✅ |
| `archived=-1` | ✅ inkl. archivierter (Default: nur nicht archivierte) |
| `updated_at_from` | ✅, **Zeitanteil aber nicht zuverlässig** (Filter mit `12:25Z` lieferte dieselben Treffer wie `10:20Z`, `23:59Z` dagegen 0). Plugin arbeitet mit 24 h Überlappung. |
| `expand=1` | liefert in der Liste dasselbe Format wie `GET /units/{id}?new=1` (296 Schlüssel) inkl. `hide_address` |

Größe/Laufzeit (gemessen): 50 Objekte mit `expand=1` ≈ 1,6 MB, ≈ 2 s; ohne `expand` ≈ 0,27 MB, ≈ 0,3 s.

## Feldformate

- Mit `expand=1`/`new=1`: die meisten Fachfelder als `{ "label": "…", "value": … }`; Meta-Felder flach (`id`, `marketing_type`, `rs_type`, `hide_address`, `street`, `zip_code`, `images`, `broker`, `property_status` …).
- Flache Liste (ohne `expand`): andere Bild-Keys (`original`, `big`, `medium`, `thumb`), `status` statt `property_status`, **ohne `hide_address`** → vom Plugin nicht mehr verwendet.
- Typen schwanken: Zahlen als Float, Integer oder numerischer String; `deposit`/`free_from`/`courtage` sind **Freitext** (z. B. „3.600,00 €“); `0` bedeutet oft „nicht angegeben“ (`base_rent: 0` bei Kaufobjekten); `city` teils mit Leerzeichen am Ende; Listenfelder (`bathroom`, `flooring_type`) als Array.
- Enum-Werte teils roh (`energy_efficiency_class: "A_PLUS"`), teils bereits deutsch (`condition: "Erstbezug"`).

## Statuswerte (Account Picaflor)

Akquise, In Vorbereitung, In Vermarktung, Reserviert, Verkauft, Inaktiv, „NUR online in Vermarktung“, „zum versenden in Vermarktung“, „Website Picaflor“. Ein eigener Status „Vermietet“ existiert nicht. Welche Status öffentlich sind, wird im Plugin-Backend gewählt (`wp psl statuses` listet IDs).

## Bilder

Felder je Bild: `id`, `title`, `position`, `is_private`, `is_floorplan`, `is_not_for_exposee`, `url`, `big_url`, `medium_url`, `thumb_url`, `small_thumb_url`, `square_url`, `tags`. Host `images.propstack.de` (HTTPS). In einer Stichprobe (30 Objekte, 314 Bilder): 39 privat, 35 Grundrisse, 1 nicht fürs Exposé. Doku: `is_private: true` = „wird nicht zu Portalen übertragen“. Pixelmaße der Größen sind **nicht dokumentiert** (für `srcset` in Phase 3 messen). `floorplans[]` war in allen Stichproben leer.

## Adressschutz

`hide_address` (Bool) – **die API liefert `street`, `house_number`, `short_address`, `lp_address`, `address` auch bei `hide_address: true`**. Der Schutz muss im Plugin erfolgen (siehe [property-model.md](property-model.md)). Stichprobe: 7 von 30 aktiven Objekten mit `hide_address: true`. Das interne Feld `name` enthält teils Adressen.

## Makler (`broker`)

Enthält öffentliche (`public_email`, `public_phone`, `public_cell`, `name`, `position`, `avatar_url`) **und interne** Felder (`email`, `phone`, `cell`, `old_crm_id`, `pricehubble_username`, `phone_system_number(s)`, `custom_fields`, `team`, `shop` …). Nur die öffentlichen werden übernommen.

## Interne CRM-Felder in Objektdaten

`note` (interne Notizen, in 16/30 befüllt), `internal_brokerage`, `*_commission*`, `relationships` (z. B. Notar inkl. Kontakt-ID), `folders` (u. a. „Sensible Daten“), `documents`, `token`, `is24_contact_id`, `openimmo_*`, `valuation_price*`, `custom_fields`. → Nie speichern/ausgeben.

## Website-Anfragen (Doku, nicht getestet)

- Empfohlen: E-Mail an eine mit Propstack verbundene Adresse mit HTML-Container `id="ps-kontaktanfrage"` und `<span id="client_first_name">…` usw.; Verarbeitung durch Automatisierung „Neue Portalanfrage“. Alternativ OpenImmo-XML-Anhang (auch mit UTM-Parametern).
- API-Alternative: `POST /v1/contacts` (Upsert über E-Mail laut Doku), `POST /v1/activities` (`note_type_id` aus `activity_types`, z. B. „Anfrage“), `POST /v1/client_properties` (Deal). Mit aktuellem Key nicht nutzbar.
- Details und Entscheidung: [leads.md](leads.md), [ADR 003](decisions/003-cf7-propstack-leads.md).

## Bekannte API-Probleme / Besonderheiten (Zusammenfassung)

1. `limit` und `ordering` werden ignoriert; `per` und `sort_by`/`order` verwenden.
2. Instabile Pagination ohne Sortierung.
3. `meta` nur mit `total_count`.
4. `updated_at_from` nicht sekundengenau.
5. Adressdaten trotz `hide_address`.
6. Detail-/Expand-Antworten enthalten interne CRM-Daten.
7. Ein Pfad-Traversal-Versuch (`units/../units`) wird serverseitig mit 302 auf `/app` beantwortet.
8. Rate-Limits sind nicht dokumentiert; keine Rate-Limit-Header beobachtet. Plugin: Retry mit Backoff, `Retry-After` wird beachtet.
