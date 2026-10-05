# Internes Property-Modell

Klasse `PropstackLite\Domain\Property` (unveränderlich, PHP 8.1 `readonly`). Erzeugt ausschließlich von `Mapping\PropertyMapper` aus Propstack-Rohdaten im Format `expand=1` / `new=1`; gespeichert als JSON in `{prefix}psl_properties.data` (`formatVersion: 1`).

Notation Propstack-Quelle: `feld` = flaches Feld, `feld.value` = Label/Value-Feld (Mapper akzeptiert beide Formen).
„Öffentlich“ = darf gerendert werden. „Intern“ = gespeichert, aber nicht standardmäßig ausgegeben.

## Kernfelder

| Feld | Typ | Propstack | Öffentlich | Fallback / Besonderheiten |
|---|---|---|---|---|
| `id` | int | `id` | ja | Pflicht; ohne gültige ID → `MappingException` |
| `slug` | string | berechnet | ja | `{zimmer}-zimmer-{typ}-{kaufen|mieten}-{ort}-{ortsteil}`, Fallback `immobilie`; siehe [routing-seo.md](routing-seo.md) |
| `title` | ?string | `title.value` | ja | HTML entfernt, max. 255 Zeichen. **Kein Fallback auf `name`** (enthält teils Adressen). Ausgabe-Fallback: „{Objektart} in {Ort}“ (`Formatter::title`) |
| `statusId` | ?int | `property_status.id` (flach: `status.id`) | intern | Grundlage der Sichtbarkeit |
| `statusName` | ?string | `property_status.name` | intern | nur Anzeige im Admin/Badge-Logik |
| `archived` | bool | `archived` | intern | archiviert → nicht öffentlich |
| `marketingType` | ?enum | `marketing_type` | ja | `BUY` / `RENT`, sonst null |
| `objectType` | ?enum | `object_type` | ja | z. B. `LIVING`, `COMMERCIAL` |
| `rsType` | ?enum | `rs_type` | ja | z. B. `APARTMENT`, `HOUSE`, `TRADE_SITE`, `OFFICE` |
| `rsCategory` | ?enum | `rs_category` | ja | z. B. `ROOF_STOREY` |
| `address` | Address | s. u. | teilweise | Adressschutz |
| `price` | ?float | `price.value` | ja | `0` → null |
| `priceOnRequest` | bool | `price_on_inquiry.value` | ja | nur explizit `true` |
| `baseRent` | ?float | `base_rent.value` | ja | `0` → null (bei Kaufobjekten oft 0) |
| `totalRent` | ?float | `total_rent.value` | ja | `0` → null |
| `livingSpace` | ?float | `living_space.value` | ja | `0` → null; numerische Strings erlaubt |
| `plotArea` | ?float | `plot_area.value` | ja | `0` → null |
| `rooms` | ?float | `number_of_rooms.value` | ja | `0` → null; z. B. 4.5 |
| `bedrooms` | ?int | `number_of_bed_rooms.value` | ja | `0` → null |
| `bathrooms` | ?int | `number_of_bath_rooms.value` | ja | `0` → null |
| `facts` | map | Katalog `FieldCatalog::FACTS` | ja | nur befüllte Werte |
| `features` | list | Katalog `FieldCatalog::FEATURES` | ja | nur Merkmale mit explizit `true` |
| `energy` | map | Katalog `FieldCatalog::ENERGY` | ja | nur befüllte Werte |
| `texts` | map | `description_note`, `furnishing_note`, `location_note`, `other_note` | ja | Schlüssel `description|furnishing|location|other`; `wp_kses_post`, `\r\n` → `\n`; leere entfallen |
| `images` | Image[] | `images[]` | ja | ohne Grundrisse, ohne private/ausgeschlossene, nach `position` sortiert |
| `floorplans` | Image[] | `images[]` mit `is_floorplan`, `floorplans[]` | ja | gleiche Filter |
| `agent` | ?Agent | `broker` | ja | nur öffentliche Felder; null, wenn weder Name noch öffentlicher Kontakt |
| `projectId` | ?int | `project_id` | intern | spätere Projektseiten |
| `unitId` | ?string | `unit_id` | intern | Objektnummer (teils interne Kürzel) – Ausgabe erst nach fachlicher Freigabe |
| `exposeeId` | ?string | `exposee_id` | intern | wie oben |
| `publicExposeUrl` | ?string | `public_expose_url` | **intern** | laut Entscheidung nicht prominent rendern; nur HTTPS `*.propstack.de` |
| `createdAt` | ?string | `created_at` | ja | ISO 8601 in UTC |
| `updatedAt` | ?string | `updated_at` | intern | ISO 8601 in UTC |

Hilfsmethoden: `isRent()`, `primaryPrice()` (Kauf: `price`; Miete: `baseRent` ?? `totalRent`), `mainImage()`, `mainArea()` (Wohnfläche, sonst `facts.property_space_value`), `fact()`, `hasFeature()`.

## Address

| Feld | Propstack | Öffentlich | Regel |
|---|---|---|---|
| `hidden` | `hide_address` | intern (steuert Rendering) | **Nur explizites `false` gibt die Adresse frei.** Fehlt das Feld → verborgen |
| `street`, `houseNumber` | `street`, `house_number` | nur wenn nicht verborgen | bei `hidden` **nicht gespeichert** (Konstruktor erzwingt null) |
| `lat`, `lng` | `lat`, `lng` | nur wenn nicht verborgen | bei `hidden` nicht gespeichert |
| `zipCode`, `city` | `zip_code`, `city` | ja | getrimmt |
| `district`, `sublocality` | `district`, `sublocality_level_1` | ja | |
| `region`, `country` | `region`, `country` | ja | |

`publicLabel()`: „Straße Nr., PLZ Ort (Ortsteil)“ bzw. bei Verborgenheit „PLZ Ort (Ortsteil)“. Die Propstack-Felder `address`, `short_address`, `lp_address` werden **nie** gelesen (enthalten die Straße auch bei verborgener Adresse).

## Image

`id`, `title` (Alt-Text-Basis), `position`, `isFloorplan`, URLs `url` (`url`/`original`), `big` (`big_url`/`big`), `medium` (`medium_url`/`medium`), `thumb` (`thumb_url`/`thumb`), `square` (`square_url`), `smallThumb` (`small_thumb_url`).
Regeln: Bild verworfen, wenn `is_private` oder `is_not_for_exposee` nicht eindeutig `false` ist (fehlendes Feld = erlaubt, `null` = verworfen). URLs nur `https://` auf `propstack.de`/`*.propstack.de`, sonst null; Bild ohne gültige URL wird verworfen.

## Agent (Ansprechpartner)

| Feld | Propstack |
|---|---|
| `name`, `firstName`, `lastName`, `academicTitle`, `position` | `broker.name`, `.first_name`, `.last_name`, `.academic_title`, `.position` |
| `salutation` | `broker.salutation` (`mr`/`ms`) |
| `avatarUrl` | `broker.avatar_url` (Fallback `avatar`), nur `*.propstack.de` |
| `email` | **`broker.public_email`** |
| `phone` | **`broker.public_phone`** |
| `cell` | **`broker.public_cell`** |

Fallback auf einen zentralen Picaflor-Kontakt (Einstellung): **Geplant (Phase 3)**.

## Whitelist (FieldCatalog)

**FACTS** (Schlüssel = Propstack-Feld; „0 = leer“ markiert Felder, bei denen 0 als „nicht angegeben“ gilt):

| Feld | Typ | 0 = leer |
|---|---|---|
| `price_per_sqm`, `rent_subsidy` (Hausgeld), `maintenance_reserve`, `service_charge`, `heating_costs`, `parking_space_price` | float | ja |
| `heating_costs_in_service_charge`, `rented` | bool | – |
| `deposit`, `courtage`, `condition`, `interior_quality`, `free_from`, `apartment_type`, `building_type`, `parking_space_type`, `heating_type`, `firing_types` | Text (max. 255) | – |
| `courtage_note` | Text (max. 2000) | – |
| `property_space_value`, `usable_floor_space`, `total_floor_space`, `balcony_space`, `garden_space` | float | ja |
| `floor` | int | **nein** (0 = Erdgeschoss) |
| `number_of_floors`, `number_of_parking_spaces`, `construction_year`, `last_refurbishment` | int | ja |
| `bathroom`, `flooring_type` | Liste | – |

**ENERGY:** `energy_certificate_availability`, `building_energy_rating_type`, `energy_certificate_start_date`, `energy_certificate_end_date`, `energy_certificate_creation_date` (Text); `energy_efficiency_value`, `thermal_characteristic` (float, 0 = leer); `energy_efficiency_class` (Enum, z. B. `A_PLUS`); `energy_consumption_contains_warm_water` (bool); `equipment_technology_construction_year` (int, 0 = leer).

**FEATURES** (nur bei `true`): `balcony`, `cellar`, `lift`, `built_in_kitchen`, `garden`, `guest_toilet`, `barrier_free`, `storeroom`, `loggia`, `sauna`, `chimney`, `kitchen_complete`.

**TEXTS:** `description_note` → `description`, `furnishing_note` → `furnishing`, `location_note` → `location`, `other_note` → `other`.

Erweiterung der Whitelist: Feld in `FieldCatalog` ergänzen, Test in `tests/Unit/PropertyMapperTest.php`, diese Tabelle aktualisieren.

## Niemals speichern / ausgeben

Gepflegt in `FieldCatalog::FORBIDDEN_KEYS` (geprüft von Tests und `wp psl audit`):

- **Interne Notizen:** `note`
- **Provisionen intern:** `internal_brokerage`, `internal_commission`, `internal_commission_percentage`, `external_commission`, `external_commission_percentage`, `total_commission`
- **Beziehungen/Dokumente:** `relationships` (z. B. Notar mit Kontakt-ID), `folders` (u. a. „Sensible Daten“), `documents`, `links`
- **Tokens/CRM-IDs:** `token`, `is24_contact_id`, `scout_id`, `broker_id`, `team_id`, `inquiry_department_id`, `location_id`, `old_crm_id`
- **Portal-/Kontaktdaten:** `openimmo_email`, `openimmo_firstname`, `openimmo_lastname`, `openimmo_phone`
- **Bewertung:** `valuation_price`, `valuation_price_from`, `valuation_price_to`
- **Sonstiges:** `custom_fields`, `pricehubble_username`, `phone_system_number(s)`
- **Adressfelder mit Straße:** `lp_address`, `short_address` (sowie `address` und `name`, die nicht gelesen werden)
- **Interne Maklerkontakte:** `broker.email`, `broker.phone`, `broker.cell`
- **Nicht freigegebene Medien:** Bilder mit `is_private` oder `is_not_for_exposee`
