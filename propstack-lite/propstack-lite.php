<?php
/**
 * Plugin Name: Propstack Listings Lite
 * Description: Minimalistische Propstack-API-Ausgabe per Shortcode (Bild, Titel, Kurztext, Preis, Größe, Link).
 * Version: 0.2.0
 * Author: Vitaliy
 * License: GPLv2 or later
 * Text Domain: propstack-lite
 */

if (!defined('ABSPATH')) exit;
final class Propstack_Listings_Lite {
    const OPTION       = 'propstack_lite_settings';
    const SALT_OPTION  = 'propstack_lite_cache_salt'; // für globales Cache-Flush
    const CACHE_PREFIX = 'propstack_lite_cache';
    const STYLE_HANDLE = 'propstack-lite';
	public function __construct() {
	  // Admin
	  add_action('admin_menu',  [$this, 'add_settings_page']);
	  add_action('admin_init',  [$this, 'register_settings']);

	  // REST
	  add_action('rest_api_init', [$this, 'register_rest']);

	  // Shortcodes
	  add_shortcode('propstack_list',   [$this, 'shortcode']);
	}
    public function add_settings_page() {
        add_options_page('Propstack Lite', 'Propstack Lite', 'manage_options', 'propstack-lite', [$this, 'render_settings']);
    }
    public function register_settings() {
        register_setting(self::OPTION, self::OPTION, [
            'sanitize_callback' => function ($input) {
                return [
                    'api_key'       => sanitize_text_field($input['api_key'] ?? ''),
                    'endpoint'      => esc_url_raw($input['endpoint'] ?? ''),
                    'cache_minutes' => max(1, intval($input['cache_minutes'] ?? 15)),
                    'webhook_token' => sanitize_text_field($input['webhook_token'] ?? ''),
                    'query_params'  => sanitize_text_field($input['query_params'] ?? ''),
					'detail_url_template'=> sanitize_text_field($input['detail_url_template'] ?? ''),
					'dev_no_cache' => !empty($input['dev_no_cache']) ? 1 : 0,
                ];
            }
        ]);
    }
    public function render_settings() {
        $o = get_option(self::OPTION, []);
        ?>
        <div class="wrap">
            <h1>Propstack Listings Lite</h1>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label>Propstack API Key</label></th>
                        <td><input type="text" name="<?= esc_attr(self::OPTION) ?>[api_key]" value="<?= esc_attr($o['api_key'] ?? '') ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label>API Endpoint URL</label></th>
                        <td>
                            <input type="url" name="<?= esc_attr(self::OPTION) ?>[endpoint]" value="<?= esc_attr($o['endpoint'] ?? '') ?>" class="regular-text code" />
                            <p class="description">z. B. https://api.propstack…/properties (exakte URL aus der Propstack-Doku).</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Default Query Params</label></th>
                        <td>
                            <input type="text" name="<?= esc_attr(self::OPTION) ?>[query_params]" value="<?= esc_attr($o['query_params'] ?? 'limit=12&order=-created_at') ?>" class="regular-text code" />
                            <p class="description">werden an jeden Request angehängt (key=value&key2=value2)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Cache (Minuten)</label></th>
                        <td><input type="number" min="1" name="<?= esc_attr(self::OPTION) ?>[cache_minutes]" value="<?= esc_attr($o['cache_minutes'] ?? 15) ?>" /></td>
                    </tr>
                    <tr>
                        <th><label>Webhook Token</label></th>
                        <td>
                            <input type="text" name="<?= esc_attr(self::OPTION) ?>[webhook_token]" value="<?= esc_attr($o['webhook_token'] ?? '') ?>" class="regular-text" />
                            <p class="description">Webhook: <?= esc_html(home_url('/wp-json/propstack/v1/webhook')) ?>?token=<em>DEIN_TOKEN</em></p>
                        </td>
                    </tr>
					<tr>
  <th><label>Exposé-URL-Muster</label></th>
  <td>
    <input type="text" name="<?= esc_attr(self::OPTION) ?>[detail_url_template]"
           value="<?= esc_attr($o['detail_url_template'] ?? '') ?>" class="regular-text code" />
    <p class="description">
      Platzhalter: {id}, {exposee_id}, {unit_id}, {city}, {title}, {slug}<br>
      Beispiel intern: /immobilie/?ps_id={id}<br>
      Beispiel extern: https://expose.meineseite.de/{exposee_id}
    </p>
  </td>
</tr>
<tr>
  <th><label>Entwicklungsmodus (Cache aus)</label></th>
  <td>
    <label>
      <input type="checkbox" name="<?= esc_attr(self::OPTION) ?>[dev_no_cache]" value="1"
             <?= !empty($o['dev_no_cache']) ? 'checked' : '' ?>>
      Transient-Cache deaktivieren (nur während der Entwicklung nutzen)
    </label>
  </td>
</tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Shortcode</h2>
            <p><code>[propstack_list per="12" page="1" sort_by="created_at" order="desc"]</code></p>
            <p><code>[propstack_list per="12" marketing_type="BUY" rs_type="APARTMENT" price_from="300000" price_to="800000"]</code></p>
            <p class="description">Parameter werden whitelisted und als Query-Parameter an den Endpoint übergeben.</p>
        </div>
        <?php
    }
	public function register_rest() {
        register_rest_route('propstack/v1', '/webhook', [
            'methods'  => ['POST','GET'],
            'callback' => function ($request) {
                $o = get_option(self::OPTION, []);
                $token = $request->get_param('token') ?: '';
                if (empty($o['webhook_token']) || !hash_equals($o['webhook_token'], $token)) {
                    return new WP_REST_Response(['ok' => false, 'error' => 'invalid token'], 401);
                }
                update_option(self::SALT_OPTION, wp_generate_password(8, false, false)); // alle Keys invalidieren
                return ['ok' => true, 'flushed' => true];
            },
            'permission_callback' => '__return_true',
        ]);
    }
    private function enqueue_styles() {
        $css = ".psl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;margin:0}
		.psl-card{border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.04)}
		.psl-img{width:100%;height:auto;display:block;aspect-ratio:4/3;object-fit:cover}
		.psl-body{padding:12px}
		.psl-title{text-transform: uppercase;}
		.psl-meta{font-size:14px;color:#374151;margin:8px 0}
		.psl-text{font-size:14px;color:#4b5563;margin:0}
		.psl-link{display:inline-block;margin-top:10px;text-decoration:none;border:1px solid #111827;padding:8px 12px;border-radius:8px;width: 100%;text-align: center;}
		.psl-meta table{width:100%}
		.psl-meta table th{text-align: left;font-weight: bold;}
		.psl-card{display:flex;flex-direction:column}
		.psl-body{display:flex;flex-direction:column;gap:8px;flex:1}
		.psl-title{margin:0 0 4px 0; line-height:1.2}
		.psl-meta{margin-top:auto}          /* Meta nach unten drücken */
		.psl-link{margin-top:10px}          /* Button immer ganz unten */
		";
        if (!wp_style_is(self::STYLE_HANDLE, 'registered')) {
            wp_register_style(self::STYLE_HANDLE, false, [], null);
        }
        wp_enqueue_style(self::STYLE_HANDLE);
        wp_add_inline_style(self::STYLE_HANDLE, $css);
    }
    public function shortcode($atts) {
        // Nur bei tatsächlicher Nutzung CSS laden
        $this->enqueue_styles();
        $a = shortcode_atts([
        'limit'     => '',
        'per'       => '',
        'page'      => '',
        'ordering'  => '',
        'sort_by'   => '',
        'order'     => '', // asc|desc
        'min_price' => '',
        'max_price' => '',
        'price_from'=> '',
        'price_to'  => '',
        'city'      => '',
        'zip_code'  => '',
        'marketing_type' => '',
        'rs_type'   => '',
		'local_sort_by' => '',
		'local_order'   => 'asc'
    ], $atts);

        $items = $this->get_items($a);
        if (is_wp_error($items)) {
            // Fehlerdetails nur für Admins, Besucher sehen eine generische Meldung.
            if (current_user_can('manage_options')) {
                return '<p>Fehler beim Abruf: ' . esc_html($items->get_error_message()) . '</p>';
            }
            return '<p>Die Immobilien können derzeit nicht geladen werden. Bitte versuchen Sie es später erneut.</p>';
        }
        if (empty($items)) return '<p>Keine Objekte gefunden.</p>';

        ob_start(); ?>
		 <!--h4><?php echo 'Objektenanzahl: '.count($items)?></h4-->
         <div class="psl-grid">
		
    <?php foreach ($items as $it):
      $img   = esc_url($it['image_url'] ?? '');
      $title = esc_html($it['title'] ?? '');
      $text  = esc_html($it['excerpt'] ?? '');
      $price = esc_html($it['price_formatted'] ?? $it['price'] ?? '');
      $size  = esc_html($it['size_formatted'] ?? $it['size'] ?? '');
      $url   = isset($it['detail_url']) ? esc_url($it['detail_url']) : '';
    ?>
    <article class="psl-card">
      <?php if ($img): ?><img class="psl-img" loading="lazy" src="<?= $img ?>" alt="<?= $title ?>"><?php endif; ?>
      <div class="psl-body">
        <h4 class="psl-title"><?= $title ?></h4>
        <?php if ($text): ?><p class="psl-text"><?= $text ?></p><?php endif; ?>
		<div class="psl-meta">
		<table>
		<?php if ($price): ?>
		<tr>
			<th>Kaufpreis</th>
			<td><?= $price ?></td>
		</tr>
		<?php endif; ?>
		<?php if ($size):  ?>
		<tr>
			<th>Fläche</th>
			<td><?= $size ?></td>
		</tr>
		<?php endif; ?>
		</table>
  <?php /*if ($price): ?><div><strong>Kaufpreis:</strong> <?= $price ?></div><?php endif; */?>
  <?php /*if (!empty($it['price_per_sqm_formatted'])): ?><div><strong>€/m²:</strong> <?= esc_html($it['price_per_sqm_formatted']) ?></div><?php endif; */?>
  <?php /*if ($size):  ?><div><strong>Fläche:</strong> <?= $size ?></div><?php endif; */?>
  <?php /*if (!empty($it['rooms'])): ?><div><strong>Zimmer:</strong> <?= esc_html($it['rooms']) ?></div><?php endif; ?>
  <?php if (!empty($it['bedrooms'])): ?><div><strong>Schlafzimmer:</strong> <?= esc_html($it['bedrooms']) ?></div><?php endif; ?>
  <?php if (!empty($it['bathrooms'])): ?><div><strong>Bäder:</strong> <?= esc_html($it['bathrooms']) ?></div><?php endif; ?>
  <?php if (!empty($it['free_from'])): ?><div><strong>Verfügbar ab:</strong> <?= esc_html($it['free_from']) ?></div><?php endif; ?>
  <?php if (!empty($it['marketing_type'])): ?><div><strong>Vermarktung:</strong> <?= esc_html($it['marketing_type']==='BUY'?'Kauf':'Miete') ?></div><?php endif; ?>
  <?php if (!empty($it['status_name'])): ?>
      <div><strong>Status:</strong>
        <span style="display:inline-block;padding:2px 6px;border-radius:6px;background:<?= esc_attr($it['status_color'] ?: '#eee') ?>;color:#fff;">
          <?= esc_html($it['status_name']) ?>
        </span>
      </div>
  <?php endif; ?>
  <?php if (!empty($it['exposee_id'])): ?><div><small>Objekt-Nr.: <?= esc_html($it['exposee_id']) ?></small></div><?php endif; */?>
</div>
        
        <a class="psl-link" href="<?= $url ?>" target="_blank" rel="noopener">Zum Exposé</a>
      </div>
	  
    </article>
    <?php endforeach; ?>
  </div>
        <?php
        return shortcode_unautop(ob_get_clean());
    }

    /** DATEN **/
	private function get_items($atts = []) {
    $o        = get_option(self::OPTION, []);
    $endpoint = trim($o['endpoint'] ?? '');
    $api_key  = trim($o['api_key'] ?? '');
    if (!$endpoint || !$api_key) {
        return new WP_Error('psl_config', 'Endpoint oder API-Key fehlt (Einstellungen prüfen).');
    }

    $debug = isset($_GET['psl_debug']) && current_user_can('manage_options');

    //
    // 1) Eingaben normalisieren
    //
    // Lokale (nur WP-seitige) Sortieroptionen – NICHT an die API weitergeben
    $localBy  = strtolower($atts['local_sort_by'] ?? '');
    $localDir = strtolower($atts['local_order'] ?? 'asc');

    // Basis-Query aus Settings
    $base_qs = [];
    if (!empty($o['query_params'])) {
        parse_str($o['query_params'], $base_qs);
    }

    // Wir setzen standardmäßig with_meta=1 für saubere Pagination-Infos, überschreibbar
    if (!array_key_exists('with_meta', $base_qs)) {
        $base_qs['with_meta'] = 1;
    }

    // Propstack nutzt "ordering"; manche Setups hatten "order" – wir normalisieren
    // Erlaubte API-Keys (breit gehalten, damit du „alle Parameter“ nutzen kannst)
    // Lokale Keys werden später explizit ausgeschlossen.
    $is_safe_key = static function($k) {
        return (bool) preg_match('~^[a-z0-9_]+$~i', $k);
    };

    // 2) Query aus $atts bauen (nur sichere Keys, lokale ausgenommen)
    $qs = [];

    // per → limit (Kompatibilität)
    if (!empty($atts['per']) && empty($atts['limit'])) {
        $qs['limit'] = max(1, (int) $atts['per']);
    }
    if (!empty($atts['limit'])) $qs['limit'] = max(1, (int) $atts['limit']);
    if (!empty($atts['page']))  $qs['page']  = max(1, (int) $atts['page']);

    // Preis-Synonyme
    $from = $atts['price_from'] ?? $atts['min_price'] ?? '';
    $to   = $atts['price_to']   ?? $atts['max_price'] ?? '';
    if ($from !== '') $qs['price_from'] = max(0, (int) $from);
    if ($to   !== '') $qs['price_to']   = max(0, (int) $to);

    // ordering direkt (z. B. "-created_at") oder via sort_by + order
    if (!empty($atts['ordering'])) {
        $qs['ordering'] = sanitize_text_field($atts['ordering']);
    } elseif (!empty($atts['sort_by'])) {
        $field = preg_replace('~[^a-zA-Z0-9_]+~', '', $atts['sort_by']);
        $dir   = strtolower($atts['order'] ?? '');
        $qs['ordering'] = ($dir === 'desc' ? '-' : '') . $field;
    } elseif (!empty($atts['order'])) {
        // falls jemand direkt "-created_at" in order übergibt
        $qs['ordering'] = sanitize_text_field($atts['order']);
    }

    // Alle weiteren sicheren Keys aus $atts übernehmen (ohne lokale WP-Keys)
    $skip_local = ['local_sort_by','local_order','order','sort_by','per']; // 'order'/'sort_by' schon oben verarbeitet
    foreach ($atts as $k => $v) {
        if ($v === '' || $v === null) continue;
        if (in_array($k, $skip_local, true)) continue;
        if (!$is_safe_key($k)) continue;
        // NICHT doppelt setzen, falls bereits oben gesetzt
        if (!array_key_exists($k, $qs)) {
            $qs[$k] = is_scalar($v) ? sanitize_text_field((string) $v) : $v;
        }
    }

    // Zusammenführen: Settings → $qs (Aufrufer gewinnt)
    // Dabei "order" in "ordering" konsolidieren.
    if (isset($base_qs['order']) && !isset($qs['ordering'])) {
        $qs['ordering'] = sanitize_text_field($base_qs['order']);
        unset($base_qs['order']);
    }
    $final_qs = array_merge($base_qs, $qs);

    // Fallbacks/Defaults
    $per_page = max(1, (int) ($final_qs['limit'] ?? 20));
    $start_p  = max(1, (int) ($final_qs['page']  ?? 1));
    $final_qs['limit'] = $per_page;
    $final_qs['page']  = $start_p;

    // 3) Cache-Key (ohne page, aber MIT lokaler Sortierung)
    $qs_for_cache = $final_qs;
    unset($qs_for_cache['page']);
    $salt      = (string) get_option(self::SALT_OPTION, '1');
    $cache_key = self::CACHE_PREFIX . ':' . $salt . ':' .
                 md5($endpoint . '|' . http_build_query($qs_for_cache, '', '&') . '|lsb=' . $localBy . '|lod=' . $localDir);
    $stale_key = $cache_key . ':stale';

    if ($cached = get_transient($cache_key)) {
        return $cached;
    }

    // 4) Request-Helper mit Mini-Retry
    $do_request = function(array $params) use ($endpoint, $api_key) {
        $url  = add_query_arg($params, $endpoint);
        $args = [
            'timeout' => 20,
            'headers' => [
                'X-API-KEY' => $api_key,
                'Accept'    => 'application/json',
            ],
            'user-agent' => 'Propstack Listings Lite (WP); ' . home_url(),
        ];

        $attempts = 0; $res = null; $code = 0; $body_raw = '';
        while ($attempts < 2) {
            $attempts++;
            $res = wp_remote_get($url, $args);
            if (is_wp_error($res)) break;

            $code = (int) wp_remote_retrieve_response_code($res);
            $body_raw = (string) wp_remote_retrieve_body($res);

            if ($code >= 500) { usleep(200000); continue; }
            break;
        }
        return [$res, $code, $body_raw, $url];
    };

    // 5) Alle Seiten laden
    $items_raw = [];
    $page      = $start_p;
    $pages_max = 100; // Sicherheitskante (100 * 50 = 5.000 Objekte, je nach limit)
    $pages_cnt = 0;

    while (true) {
        $call_qs = $final_qs;
        $call_qs['page'] = $page;

        [$res, $code, $body_raw, $req_url] = $do_request($call_qs);

        if (is_wp_error($res) || $code < 200 || $code >= 300) {
            if ($stale = get_transient($stale_key)) return $stale;

            $err = '';
            if (!is_wp_error($res)) {
                $parsed = json_decode($body_raw, true);
                if (is_array($parsed) && !empty($parsed['errors'])) {
                    $err = implode('; ', array_map('strval', (array)$parsed['errors']));
                } else {
                    $err = trim(wp_strip_all_tags(mb_substr($body_raw, 0, 400)));
                }
            } else {
                $err = $res->get_error_message();
            }

            $msg = 'HTTP ' . ($code ?: 'Error') . ' beim Abruf.' . ($err ? ' Body: ' . $err : '');
            if ($debug) {
                $msg .= ' | URL: ' . esc_url_raw($req_url) .
                        ' | Param: ' . json_encode($call_qs, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            }
            return new WP_Error('psl_http', $msg);
        }

        $data = json_decode($body_raw, true);
        if (!is_array($data)) {
            if ($stale = get_transient($stale_key)) return $stale;
            return new WP_Error('psl_json', 'Unerwartete Antwort (kein JSON).');
        }

        $page_items = $this->extract_items($data);
        if (empty($page_items)) {
            break; // nichts mehr
        }
        $items_raw = array_merge($items_raw, $page_items);

        // Pagination-Entscheidung:
        // a) meta.pages vorhanden → bis pages erreicht
        // b) sonst: wenn weniger als per_page geliefert, sind wir am Ende
        $has_more = false;
        if (!empty($data['meta']['pages'])) {
            $total_pages = (int) $data['meta']['pages'];
            $has_more = ($page < $total_pages);
        } else {
            $has_more = (count($page_items) >= $per_page);
        }

        $page++;
        $pages_cnt++;

        if (!$has_more || $pages_cnt >= $pages_max) break;
    }

    // 6) Mappen + lokale Sortierung
    $items = array_map([$this, 'map_item'], $items_raw);

    if ($localBy === 'street') {
        usort($items, function($a, $b) use ($localDir) {
            $ka = $a['street'] ?? '';
            $kb = $b['street'] ?? '';

            if (!empty($a['house_number'])) $ka .= ' ' . $a['house_number'];
            if (!empty($b['house_number'])) $kb .= ' ' . $b['house_number'];

            if ($ka === '') $ka = $a['short_address'] ?? ($a['address'] ?? '');
            if ($kb === '') $kb = $b['short_address'] ?? ($b['address'] ?? '');

            $norm = static function($s) {
                $s = strtr($s, ['Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
                return mb_strtolower($s);
            };
            $ka = $norm($ka);
            $kb = $norm($kb);

            $cmp = strnatcasecmp($ka, $kb);
            return ($localDir === 'desc') ? -$cmp : $cmp;
        });
    }

    // 7) Caching (fresh + stale)
    $ttl = max(60, (int)($o['cache_minutes'] ?? 15) * MINUTE_IN_SECONDS);
    set_transient($cache_key, $items, $ttl);
    set_transient($stale_key, $items, 2 * DAY_IN_SECONDS);

    return $items;
	}
	private function map_item($r) {
  $o = get_option(self::OPTION, []);

  $arr = is_array($r) ? $r : [];

  // Bild sicher bestimmen
  $first_img = '';
  if (!empty($arr['images']) && is_array($arr['images'])) {
      // Private Bilder und Grundrisse niemals als Vorschaubild verwenden.
      foreach ($arr['images'] as $img0) {
          if (!is_array($img0) || !empty($img0['is_private']) || !empty($img0['is_not_for_exposee']) || !empty($img0['is_floorplan'])) continue;
          foreach (['medium','big','original'] as $k) {
              if (!empty($img0[$k])) { $first_img = $img0[$k]; break 2; }
          }
      }
  }

  // Preis / Fläche
  $price = $arr['price'] ?? ($arr['base_rent'] ?? null);
  $size  = $arr['living_space'] ?? ($arr['property_space_value'] ?? null);
  $ppsqm = (is_numeric($price) && is_numeric($size) && $size > 0) ? ($price / $size) : null;
  // öffentlicher Link bevorzugen, sonst Template
    $detail = '';
    if (!empty($o['detail_url_template'])) {
        $detail = $this->fill_template($o['detail_url_template'], $r);
    } else {
        $detail = $this->build_detail_url($r, false); // relativ
    }

  // Adressen & Meta sicher lesen
  $status = $arr['status'] ?? [];
  $address = $arr['address'] ?? null;
  $city = $arr['city'] ?? '';
  $zip  = $arr['zip_code'] ?? '';
  // Die flache Liste liefert kein hide_address – daher niemals Straße/Hausnummer ausgeben.
  $short_address = trim(trim((string) $zip) . ' ' . trim((string) $city));

  return [
      'id'              => $arr['id'] ?? null,
      'title'           => $arr['title'] ?? ($arr['name'] ?? 'Objekt'),
      'excerpt'         => $short_address,
      'price'           => $price,
      'price_formatted' => is_numeric($price) ? number_format($price, 0, ',', '.') . ' €' : (string)$price,
      'size'            => $size,
      'size_formatted'  => is_numeric($size) ? number_format($size, 2, ',', '.') . ' m²' : (string)$size,
      'rooms'           => $arr['number_of_rooms'] ?? null,
      'bedrooms'        => $arr['number_of_bed_rooms'] ?? null,
      'bathrooms'       => $arr['number_of_bath_rooms'] ?? null,
      'currency'        => $arr['currency'] ?? 'EUR',
      'marketing_type'  => $arr['marketing_type'] ?? null,
      'free_from'       => $arr['free_from'] ?? null,
      'rented'          => !empty($arr['rented']),
      'status_name'     => $status['name'] ?? null,
      'status_color'    => $status['color'] ?? null,
      'address'         => $address,
      'district'        => $arr['district'] ?? null,
      'zip_city'        => trim($zip.' '.$city),
      'image_url'       => $first_img,
      'image_count'     => (!empty($arr['images']) && is_array($arr['images'])) ? count($arr['images']) : 0,
      'exposee_id'      => $arr['exposee_id'] ?? null,
      'unit_id'         => $arr['unit_id'] ?? null,
      'project_id'      => $arr['project_id'] ?? null,
      'public_url'      => $arr['public_url'] ?? ($arr['url'] ?? ''), // zur Anzeige/Weiterleitung optional
      'detail_url'      => $detail,
      'price_per_sqm'   => $ppsqm,
      'price_per_sqm_formatted' => is_numeric($ppsqm) ? number_format($ppsqm, 2, ',', '.') . ' €/m²' : '',
  ];
}
    private function extract_items($data) {
        if (isset($data['data']) && is_array($data['data']))     return $data['data'];
        if (isset($data['results']) && is_array($data['results'])) return $data['results'];
        return is_array($data) ? $data : [];
    }
	private function slugify($s, int $maxLen = 80): string {
		$s = wp_strip_all_tags((string)$s);
		$s = trim($s);

		// deutsche Sonderfälle vor remove_accents
		$s = strtr($s, ['ß' => 'ss']);

		// WP-Transliteration (ä->a, ö->o, ü->u etc.)
		$s = remove_accents($s);
		$s = strtolower($s);

		// alles Nicht-Alphanumerische zu '-'
		$s = preg_replace('~[^a-z0-9]+~', '-', $s);
		// doppelte '-' vermeiden
		$s = preg_replace('~-+~', '-', $s);
		$s = trim($s, '-');

		if ($maxLen > 0 && strlen($s) > $maxLen) {
			$s = rtrim(substr($s, 0, $maxLen), '-');
		}

		return $s !== '' ? $s : 'objekt';
	}
	private function build_detail_url(array $r, bool $absolute = false): string {
		// ID ermitteln (nimm die, die bei dir wirklich gesetzt ist)
		$id = (string)($r['id'] ?? $r['unit_id'] ?? $r['exposee_id'] ?? '');
		if ($id === '') return '';

		$title = (string)($r['title'] ?? $r['name'] ?? 'Objekt');
		$slug  = $this->slugify($title);

		$path = '/immobilie/' . rawurlencode($slug) . '-' . rawurlencode($id) . '/';

		return $absolute ? home_url($path) : $path;
	}
	private function fill_template($tpl, $r) {
	  if (!$tpl) return '';
	  $repl = [
		'{id}'         => $r['id'] ?? '',
		'{exposee_id}' => $r['exposee_id'] ?? '',
		'{unit_id}'    => $r['unit_id'] ?? '',
		'{city}'       => $r['city'] ?? '',
		'{title}'      => $r['title'] ?? ($r['name'] ?? ''),
		'{slug}'       => $this->slugify($r['title'] ?? ($r['name'] ?? '')),
	  ];
	  return strtr($tpl, $repl);
	}
}

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
new Propstack_Listings_Lite();
