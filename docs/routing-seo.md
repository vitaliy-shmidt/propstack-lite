# Routing und SEO

Stand: Phase 5 (2026-10-06). Routing, Statuscodes und Kanonisierung (Phase 2) sowie Title, Description, Canonical, Robots, Open Graph, JSON-LD, Sitemap und Yoast/Rank-Math-Integration (Phase 5) sind **implementiert**. Details zu SEO: [seo.md](seo.md).

Detailseiten rendern ausschließlich aus dem lokalen `PropertyStore` – **kein Besucher-Request löst einen Propstack-Request aus** (per HTTP-Test nachgewiesen, siehe [testing.md](testing.md)).

## URLs

| URL | Bedeutung |
|---|---|
| `/immobilien/` | Übersicht – normale WordPress-Seite mit `[propstack_list]` (wird nicht vom Router behandelt) |
| `/immobilien/?marketing_type=buy&city=Berlin&seite=2` | Übersicht mit Filtern/Pagination (Phase 7, GET-Parameter, [listing.md](listing.md)). Seitenparameter `seite`, weil WordPress `?page=2` per 301 auf `/immobilien/` umleitet und `/immobilien/2/` vom Detail-Router als ID 2 interpretiert würde |
| `/immobilien/{slug}-{id}/` | dynamische Detailseite (kanonisch) |
| `/immobilien/{id}/` | Kurzform → 301 |
| `/immobilie/{slug}-{id}/`, `/immobilie/{id}/`, `/immobilie/?ps_id={id}` | Legacy (0.2.x) → 301 |
| `/?psl_property={id}` | Fallback ohne Pretty Permalinks |

Alle URLs erzeugt ausschließlich `Routing\UrlGenerator` (absolut über `home_url()`, Trailing Slash gemäß Permalink-Struktur über `user_trailingslashit()`, Unterverzeichnis-Installationen wie `/Picaflor/` getestet). Die Übersichts-URL ist über den Filter `psl_overview_url` anpassbar.

## Rewrite-Regeln und Query-Vars

`Routing\Router`, Priorität `top`:

| Regex | Query |
|---|---|
| `^immobilien/(?:([^/]+)-)?([0-9]{1,18})/?$` | `index.php?psl_property=$2&psl_slug=$1` |
| `^immobilie/(?:([^/]+)-)?([0-9]{1,18})/?$` | `index.php?psl_property=$2&psl_legacy=1` |

Query-Vars: `psl_property` (ID), `psl_slug` (angefragter Slug, nur informativ), `psl_legacy`.
`/immobilie/?ps_id=` ist für Rewrite-Regeln unsichtbar → Filter `request` mappt es, wenn der angefragte Pfad (`$wp->request`) exakt `immobilie` ist und `ps_id` rein numerisch ist. Für Legacy-Pfade ohne gültige ID wird WordPress' `redirect_guess_404_permalink` (Raten von `/immobilien/`) über `do_redirect_guess_404_permalink` abgeschaltet → echtes 404. Existiert eine echte WordPress-Seite `/immobilie/`, wird sie weiterhin normal ausgeliefert; das neue Routing braucht sie nicht.

**Lifecycle:** Regeln werden bei jedem `init` registriert (ohne Flush). Flush nur bei Aktivierung (Regeln registrieren → `flush_rewrite_rules()` → Option `psl_rewrite_version`), Deaktivierung (Regeln aus dem laufenden `$wp_rewrite` entfernen → Flush → Option löschen) und einmalig, wenn sich `Router::RULES_VERSION` ändert (Plugin-Update). Kein Flush bei normalen Requests. Getestet: nach Deaktivierung 0 Plugin-Regeln, nach Aktivierung beide Regeln ohne manuelles Speichern der Permalinks.

## Slug-Regeln

`Support\Slugger::forProperty()`: `{zimmer}-zimmer-{objektart}-{kaufen|mieten}-{ort}-{ortsteil}`, z. B. `2-zimmer-wohnung-kaufen-berlin-mitte`. Teile entfallen bei fehlenden Daten; Ortsteil entfällt, wenn im Ort enthalten; Fallback `immobilie`; Umlaute deterministisch (ä → ae, ß → ss); max. 80 Zeichen; **nicht** aus dem CRM-Titel. Der Slug wird beim Sync gespeichert (`psl_properties.slug`). **Maßgeblich ist die ID** – jeder abweichende Pfad wird per 301 kanonisiert.

## Statusmatrix (implementiert)

Entscheidung: `Routing\RouteResolver` (reine Logik, nur gespeicherter Zustand + aktuelle Zeit, keine API). Antwort: `Frontend\DetailController`.

| Fall | Erkennung (Store) | HTTP | Robots | Seite |
|---|---|---|---|---|
| gültig, öffentlich, korrekter Pfad | `active`, Status öffentlich | **200** | index | Detailseite, Kontakt-Platzhalter |
| reserviert | `active`, Status öffentlich + „Reserviert“ | **200** | index | Badge „Reserviert“, Kontakt-Platzhalter |
| falscher/alter Slug, fehlender Trailing Slash, abweichende Schreibweise, nur ID | renderbarer Zustand, Pfad ≠ kanonisch | **301** | – | → kanonische URL |
| verkauft/vermietet ≤ 30 Tage | `sold`, `sold_at` + 30 Tage > jetzt, Daten vorhanden | **200** | `noindex, follow` (Meta + `X-Robots-Tag`) | Badge „Verkauft“ bzw. „Vermietet“ (bei `marketing_type = RENT`), Hinweis + Link zur Übersicht, **kein** Kontaktbereich, Hook `psl_property_similar`; nicht in Liste |
| verkauft/vermietet > 30 Tage | `sold`, älter (oder Daten bereits entfernt) | **410** | `noindex, follow` | Hinweisseite ohne Objektdaten, Link zur Übersicht, Hook `psl_property_similar` |
| entfernt | `removed` | **410** | `noindex, follow` | wie oben |
| aktiv gespeichert, Status nicht mehr öffentlich (Einstellung geändert) | `active`, Status ∉ öffentlich | **410** | `noindex, follow` | wie oben (bis zum nächsten Sync → `removed`) |
| unbekannte ID | keine Zeile | **404** | – | Theme-404 (`set_404()`), `nocache_headers()` |
| ungültige ID (`/immobilien/x-0/`, `?psl_property=abc`) | – | **404** | – | Theme-404 statt Startseite mit 200 (Soft-404 behoben in 0.4.1) |
| Legacy mit bekannter ID | Zeile vorhanden (beliebiger Zustand) | **301** | – | → kanonische URL (dort ggf. 410) |
| Legacy mit unbekannter/ungültiger/fehlender ID | – | **404** | – | Theme-404 |

Die 30-Tage-Frist rechnet ab dem gespeicherten `sold_at` (UTC, erster Verkauft-Zeitpunkt) gegen die Serverzeit; genau 30 Tage → 410. Status-IDs für „verkauft/vermietet“ und „reserviert“ kommen aus den Plugin-Einstellungen.

**301-Regeln:** Ziel ist immer `UrlGenerator::canonicalUrl()`. Vorhandene Query-Parameter (z. B. `utm_*`, `gclid`) bleiben erhalten; interne Parameter (`ps_id`, `psl_*`) werden entfernt. Für 410-Zustände wird nicht umgeleitet (keine Redirect-Ketten auf eine 410-Seite, außer bei Legacy-URLs, damit es nur eine kanonische Adresse gibt). Ohne Pretty Permalinks findet keine Pfad-Kanonisierung statt.

## WordPress-Query-Verhalten

- Die Route ist für WordPress **weder Beitrag noch Seite**: `is_singular()`, `is_page()`, `is_home()`, `is_front_page()`, `is_archive()` sind `false` (`parse_query`).
- **Kein virtuelles `WP_Post`** und keine Fake-Posts in der Datenbank. Begründung: Ein Fake-Post würde von Themes/SEO-Plugins als echter Inhalt behandelt (Post-Meta-Abfragen, Kommentare, Edit-Links, Canonical anderer Plugins). Ob Avada ohne `$post` korrekt rendert, ist **noch nicht gegen eine reale Avada-Installation verifiziert** (siehe [avada.md](avada.md)).
- Die überflüssige `wp_posts`-Abfrage der Hauptquery wird per `posts_pre_query` übersprungen.
- `pre_handle_404`: WordPress wertet die Route nicht selbst als 404; nur bei unbekannter ID setzt der Controller `set_404()`.
- `redirect_canonical` ist für die Route deaktiviert (eigene Kanonisierung).
- `template_include` (Priorität 99): `single-property.php` bzw. `property-gone.php`, Filter `psl_property_template`.
- Body-Klassen: `psl-property`, `psl-property--{active|reserved|sold|gone}` (bei Avada zusätzlich `psl-theme-avada`).
- Dokumenttitel, Canonical, Robots-Meta: seit Phase 5 aus der SEO-Schicht ([seo.md](seo.md)); der Controller setzt nur noch den Header `X-Robots-Tag`.

## Templates und Hooks

Seit Phase 3 vollständig beschrieben in [frontend.md](frontend.md): Template-Hierarchie (`single-property.php` + 15 Teile unter `parts/`), ViewModel, Formatter, Galerie/Lightbox, Hooks und Theme-Overrides. Die 410-Seite nutzt `property-gone.php` (Override `{theme}/propstack-lite/property-gone.php`); der 404-Fall nutzt das Theme-404-Template.

Header/Footer: klassische Themes über `get_header()`/`get_footer()`, Block-Themes über die Template-Parts `header`/`footer` (getestet mit Twenty Twenty-One und Twenty Twenty-Five).

## SEO (Phase 5, implementiert)

Vollständig beschrieben in [seo.md](seo.md). Kurzfassung:

- **Canonical:** genau einer auf 200-Seiten = `UrlGenerator::canonicalUrl()`, unabhängig von UTM/`gclid`; keiner auf 404/410. (Yoast lässt ihn auf noindex-Seiten der Verkauft-Phase weg.)
- **Robots:** aktiv/reserviert `index, follow`; Verkauft-Phase und 410 `noindex, follow` als Meta **und** HTTP-Header `X-Robots-Tag` (wirkt unabhängig von SEO-Plugins); 404 normal.
- **Title/Description/OG/Twitter/JSON-LD/Sitemap:** zentral im `Seo\SeoService`, ausgegeben vom Plugin selbst (Core) oder über Yoast/Rank Math (Priorität Yoast > Rank Math > Core).
- **Statuscodes:** 200/301/404/410 wie oben; 410 ohne Weiterleitung, Legacy-URLs weiterhin 301.

Warum 410 statt Redirect auf die Übersicht: Massen-Redirects auf eine Übersicht wertet Google als Soft-404; 410 signalisiert dauerhafte Entfernung und wird schneller deindexiert.

## Übersicht (Phase 7)

Canonical der Übersicht ist selbstreferenzierend auf die normalisierte URL (nur validierte Parameter, feste Reihenfolge, ohne Tracking-Parameter); Seite n verweist nicht auf Seite 1. Ungefiltert (auch Seite 2 ff.) `index, follow`; Filter, abweichende Sortierung/Seitengröße und Seiten hinter der letzten `noindex, follow` (Meta + `X-Robots-Tag`). Details und Modus-Besonderheiten (Yoast ohne Canonical auf noindex-Seiten): [listing.md](listing.md#seo-verhalten).

## Bekannte Einschränkungen

- Unterseiten der WordPress-Seite `/immobilien/`, deren Slug auf `-{Zahl}` endet, werden vom Router überdeckt.
- Permalink-Strukturen mit `/index.php/`-Präfix (PATHINFO) sind nicht getestet.
- Webserver: getestet nur mit dem PHP-Built-in-Server (Router-Skript simuliert Rewrites), auch als Unterverzeichnis-Installation; das Plugin nutzt ausschließlich WordPress-Rewrite-Regeln. Apache (`.htaccess`) und nginx der Live-Site: **nicht getestet** (Phase 8).
- Ähnliche Immobilien, Galerie, Ausstattung, Energie, Grundrisse: Phase 3. Kontaktformular: Phase 4.
- Avada und ein Page-Cache-Plugin sind nicht gegen reale Installationen getestet; Yoast 28.6 und Rank Math 1.0.279 nur in der Testinstanz.
