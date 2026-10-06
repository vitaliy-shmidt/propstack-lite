<?php

namespace PropstackLite\Admin;

use PropstackLite\Api\ApiException;
use PropstackLite\Plugin;
use PropstackLite\Settings;
use PropstackLite\Storage\PropertyStore;
use PropstackLite\Sync\SyncLock;
use PropstackLite\Sync\SyncState;

/**
 * Einstellungsseite unter „Einstellungen → Propstack Lite“ (nur manage_options).
 *
 * Status-Auswahl wird aus `GET /v1/property_statuses` geladen (1 h gecacht); dieser Request
 * findet nur im Admin statt. Aktionen laufen über admin-post.php mit Nonce + Capability-Check.
 */
final class SettingsPage {

	public const SLUG            = 'propstack-lite';
	public const GROUP           = 'propstack_lite';
	public const STATUS_CACHE    = 'psl_property_statuses';
	public const ACTION_SYNC     = 'psl_sync_now';
	public const ACTION_STATUSES = 'psl_reload_statuses';

	public function __construct( private Settings $settings, private Plugin $plugin ) {}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'addPage' ] );
		add_action( 'admin_init', [ $this, 'registerSetting' ] );
		add_action( 'admin_post_' . self::ACTION_SYNC, [ $this, 'handleSyncNow' ] );
		add_action( 'admin_post_' . self::ACTION_STATUSES, [ $this, 'handleReloadStatuses' ] );
	}

	public function addPage(): void {
		add_options_page( 'Propstack Lite', 'Propstack Lite', 'manage_options', self::SLUG, [ $this, 'render' ] );
	}

	public function registerSetting(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this->settings, 'sanitize' ],
				'show_in_rest'      => false,
			]
		);
	}

	public static function url( array $args = [] ): string {
		return add_query_arg( array_merge( [ 'page' => self::SLUG ], $args ), admin_url( 'options-general.php' ) );
	}

	public function handleSyncNow(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( self::ACTION_SYNC );

		$result = $this->plugin->syncService()->runFull();
		set_transient( 'psl_admin_sync_result_' . get_current_user_id(), $result->summary(), 60 );
		wp_safe_redirect( self::url( [ 'psl_synced' => $result->isOk() ? '1' : '0' ] ) );
		exit;
	}

	public function handleReloadStatuses(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Keine Berechtigung.', 403 );
		}
		check_admin_referer( self::ACTION_STATUSES );
		delete_transient( self::STATUS_CACHE );
		wp_safe_redirect( self::url() );
		exit;
	}

	/** @return array{statuses: list<array{id:int,name:string}>, error: ?string} */
	private function loadStatuses(): array {
		$cached = get_transient( self::STATUS_CACHE );
		if ( is_array( $cached ) ) {
			return [ 'statuses' => $cached, 'error' => null ];
		}
		if ( ! $this->settings->hasApiKey() ) {
			return [ 'statuses' => [], 'error' => 'Bitte zuerst einen API-Key hinterlegen.' ];
		}
		try {
			$statuses = $this->plugin->unitsEndpoint()->statuses();
			set_transient( self::STATUS_CACHE, $statuses, HOUR_IN_SECONDS );
			return [ 'statuses' => $statuses, 'error' => null ];
		} catch ( ApiException $e ) {
			return [ 'statuses' => [], 'error' => $e->getMessage() ];
		}
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o        = $this->settings->all();
		$loaded   = $this->loadStatuses();
		$statuses = $loaded['statuses'];
		$name     = Settings::OPTION;
		?>
		<div class="wrap">
			<h1>Propstack Listings Lite</h1>
			<?php $this->renderSyncResultNotice(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2>Propstack-API</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="psl-api-key">API-Key</label></th>
						<td>
							<?php if ( Settings::apiKeyFromConstant() ) : ?>
								<p><strong>Über die Konstante <code>PSL_API_KEY</code> in wp-config.php gesetzt.</strong> Das Feld ist deaktiviert.</p>
							<?php else : ?>
								<input type="password" id="psl-api-key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" class="regular-text" autocomplete="new-password"
									placeholder="<?php echo esc_attr( '' !== $o['api_key'] ? '•••••••• (gespeichert – leer lassen zum Beibehalten)' : '' ); ?>">
								<?php if ( '' !== $o['api_key'] ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[api_key_clear]" value="1"> Gespeicherten Key entfernen</label>
								<?php endif; ?>
								<p class="description">Empfohlen: Key als <code>define( 'PSL_API_KEY', '…' );</code> in wp-config.php statt in der Datenbank. Der Key wird nur serverseitig verwendet.</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2>Sichtbarkeit</h2>
				<?php if ( null !== $loaded['error'] ) : ?>
					<div class="notice notice-warning inline"><p>Propstack-Status konnten nicht geladen werden: <?php echo esc_html( $loaded['error'] ); ?></p></div>
				<?php endif; ?>
				<table class="form-table" role="presentation">
					<?php
					$this->renderStatusField( 'public_status_ids', 'Öffentliche Propstack-Status', 'Nur Objekte mit diesen Status erscheinen auf der Website. Ohne Auswahl ist nichts öffentlich.', $statuses, $o['public_status_ids'] );
					$this->renderStatusField( 'sold_status_ids', 'Status „verkauft/vermietet“', 'Zuvor öffentliche Objekte mit diesem Status bleiben 30 Tage mit Hinweis erreichbar (ab Phase 2), danach HTTP 410.', $statuses, $o['sold_status_ids'] );
					$this->renderStatusField( 'reserved_status_ids', 'Status mit Badge „Reserviert“', 'Muss zusätzlich als öffentlich ausgewählt sein.', $statuses, $o['reserved_status_ids'] );
					?>
				</table>

				<h2>Synchronisation</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="psl-interval">Sync-Intervall (Minuten)</label></th>
						<td>
							<input type="number" id="psl-interval" min="<?php echo esc_attr( (string) Settings::MIN_INTERVAL ); ?>" max="<?php echo esc_attr( (string) Settings::MAX_INTERVAL ); ?>"
								name="<?php echo esc_attr( $name ); ?>[sync_interval]" value="<?php echo esc_attr( (string) $this->settings->syncInterval() ); ?>">
							<p class="description">Inkrementeller Abgleich. Zusätzlich läuft alle 6 Stunden ein Voll-Sync.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="psl-webhook-token">Webhook-Token</label></th>
						<td>
							<input type="text" id="psl-webhook-token" name="<?php echo esc_attr( $name ); ?>[webhook_token]" value="<?php echo esc_attr( $o['webhook_token'] ); ?>" class="regular-text code" autocomplete="off">
							<p class="description">Nur A–Z, 0–9, „-“, „_“. Webhook (POST): <code><?php echo esc_html( rest_url( 'propstack/v1/webhook' ) ); ?></code> mit Header <code>X-PSL-Token</code>. Leer = Webhook deaktiviert.</p>
						</td>
					</tr>
				</table>

				<?php $this->renderLeadSettings( $o ); ?>
				<?php $this->renderTrackingSettings(); ?>

				<?php submit_button(); ?>
			</form>

			<?php $this->renderLeadStatus(); ?>
			<?php $this->renderSyncBox(); ?>
			<?php $this->renderSeoBox(); ?>

			<h2>Shortcode</h2>
			<p><code>[propstack_list per="12" marketing_type="BUY" rs_type="APARTMENT" sort_by="price" order="asc"]</code></p>
			<p class="description">Attribute: per, page, marketing_type (BUY/RENT), rs_type, city, zip_code, price_from, price_to, sort_by (created_at, updated_at, price, living_space, rooms, city), order (asc/desc), heading (h2–h4). Attribute können die Liste nur einschränken, nie nicht-öffentliche Objekte freischalten.</p>
		</div>
		<?php
	}

	private function renderStatusField( string $key, string $label, string $description, array $statuses, array $selected ): void {
		$name     = Settings::OPTION . '[' . $key . '][]';
		$known    = array_column( $statuses, 'id' );
		$unknown  = array_diff( Settings::intList( $selected ), $known );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<fieldset>
					<legend class="screen-reader-text"><?php echo esc_html( $label ); ?></legend>
					<?php foreach ( $statuses as $status ) : ?>
						<label style="display:block">
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $status['id'] ); ?>" <?php checked( in_array( $status['id'], $selected, true ) ); ?>>
							<?php echo esc_html( $status['name'] ); ?> <span class="description">(ID <?php echo esc_html( (string) $status['id'] ); ?>)</span>
						</label>
					<?php endforeach; ?>
					<?php foreach ( $unknown as $id ) : // Auswahl erhalten, auch wenn die Statusliste gerade nicht ladbar ist. ?>
						<label style="display:block">
							<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $id ); ?>" checked>
							Status-ID <?php echo esc_html( (string) $id ); ?> <span class="description">(in Propstack nicht gefunden)</span>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				</fieldset>
			</td>
		</tr>
		<?php
	}

	/** Einstellungen „Immobilienanfragen“ (Contact Form 7 → Propstack-Mail). */
	private function renderLeadSettings( array $o ): void {
		$name     = Settings::OPTION;
		$forms    = [];
		$cf7Ready = \PropstackLite\Leads\Cf7Integration::isAvailable();
		if ( $cf7Ready ) {
			foreach ( get_posts( [ 'post_type' => 'wpcf7_contact_form', 'numberposts' => 100, 'post_status' => 'publish', 'orderby' => 'title', 'order' => 'ASC' ] ) as $post ) {
				$forms[ (int) $post->ID ] = $post->post_title;
			}
		}
		$labels = [
			'salutation' => 'Anrede (optional)',
			'first_name' => 'Vorname',
			'last_name'  => 'Nachname',
			'email'      => 'E-Mail',
			'phone'      => 'Telefon',
			'message'    => 'Nachricht',
			'consent'    => 'Zustimmung (acceptance-Feld)',
		];
		$map   = $this->settings->fieldMap();
		?>
		<h2>Immobilienanfragen (Contact Form 7 → Propstack)</h2>
		<?php if ( ! $cf7Ready ) : ?>
			<div class="notice notice-warning inline"><p>Contact Form 7 ist nicht aktiv. Immobilienanfragen sind derzeit deaktiviert.</p></div>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="psl-cf7-form">Contact-Form-7-Formular</label></th>
				<td>
					<?php if ( $cf7Ready ) : ?>
						<select id="psl-cf7-form" name="<?php echo esc_attr( $name ); ?>[cf7_form_id]">
							<option value="0">– kein Formular –</option>
							<?php foreach ( $forms as $id => $title ) : ?>
								<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $this->settings->cf7FormId(), $id ); ?>><?php echo esc_html( $title . ' (ID ' . $id . ')' ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<input type="number" id="psl-cf7-form" min="0" name="<?php echo esc_attr( $name ); ?>[cf7_form_id]" value="<?php echo esc_attr( (string) $this->settings->cf7FormId() ); ?>">
					<?php endif; ?>
					<p class="description">Nur dieses Formular wird von Propstack Lite verarbeitet; alle anderen CF7-Formulare bleiben unverändert.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="psl-inquiry-email">Propstack-Anfrage-E-Mail-Adresse</label></th>
				<td>
					<input type="email" id="psl-inquiry-email" name="<?php echo esc_attr( $name ); ?>[inquiry_email]" value="<?php echo esc_attr( $o['inquiry_email'] ); ?>" class="regular-text" autocomplete="off">
					<p class="description">Mit Propstack verbundenes Postfach, das die Automatisierung „Neue Portalanfrage“ verarbeitet. Empfänger der Anfrage-Mail (überschreibt den Empfänger des Formulars).</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="psl-inquiry-bcc">Interne Kopie (BCC, optional)</label></th>
				<td><input type="email" id="psl-inquiry-bcc" name="<?php echo esc_attr( $name ); ?>[inquiry_bcc]" value="<?php echo esc_attr( $o['inquiry_bcc'] ); ?>" class="regular-text" autocomplete="off"></td>
			</tr>
			<tr>
				<th scope="row">Feldzuordnung (CF7-Feldnamen)</th>
				<td>
					<fieldset>
						<legend class="screen-reader-text">Feldzuordnung</legend>
						<?php foreach ( $labels as $key => $label ) : ?>
							<label style="display:block;margin-bottom:4px">
								<span style="display:inline-block;min-width:230px"><?php echo esc_html( $label ); ?></span>
								<input type="text" class="code" name="<?php echo esc_attr( $name ); ?>[field_map][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $map[ $key ] ); ?>">
							</label>
						<?php endforeach; ?>
						<p class="description">Namen der Formular-Tags im CF7-Formular, z. B. <code>[text* your-first-name]</code> → <code>your-first-name</code>. Die Zustimmung muss ein <code>[acceptance …]</code>-Feld sein.</p>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}

	/** Phase 6: Attribution, Consent, dataLayer, Propstack-Zuordnung (sichere Defaults: aus). */
	private function renderTrackingSettings(): void {
		$name      = Settings::OPTION;
		$cfMap     = $this->settings->customFieldMap();
		$providers = \PropstackLite\Tracking\Consent\ConsentProviders::all();
		$current   = $this->settings->consentProviderId();
		$labels    = [
			'lead_id'            => 'Lead-ID (kein Marketingwert, auch ohne Consent)',
			'first_utm_source'   => 'First Touch: Quelle',
			'first_utm_medium'   => 'First Touch: Medium',
			'first_utm_campaign' => 'First Touch: Kampagne',
			'last_utm_source'    => 'Last Non-Direct: Quelle',
			'last_utm_medium'    => 'Last Non-Direct: Medium',
			'last_utm_campaign'  => 'Last Non-Direct: Kampagne',
			'utm_content'        => 'utm_content',
			'utm_term'           => 'utm_term',
			'gclid'              => 'Google-Klick-ID (gclid)',
			'gbraid'             => 'gbraid',
			'wbraid'             => 'wbraid',
			'landing_path'       => 'Erste Landingpage (Pfad)',
			'referrer_host'      => 'Referrer-Domain (First Touch)',
		];
		?>
		<h2>Tracking &amp; Attribution</h2>
		<?php if ( ( $this->settings->trackingAttribution() || $this->settings->trackingDataLayer() ) && ! ( $providers[ $current ] ?? $providers['none'] )->allowsMarketing() ) : ?>
			<div class="notice notice-warning inline"><p>Tracking ist aktiviert, aber kein Consent-System ausgewählt – es werden keine Attributionsdaten erfasst und keine Events gesendet.</p></div>
		<?php endif; ?>
		<p class="description">Das Plugin lädt kein GTM, GA4, Google Ads oder Pixel und sendet keine Daten an Dritte. Ohne Marketing-Consent werden keine Attributionsdaten gespeichert oder übertragen; die Anfrage funktioniert immer. Details: docs/tracking.md.</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Attribution</th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[tracking_attribution]" value="1" <?php checked( $this->settings->trackingAttribution() ); ?>> Kampagnenherkunft erfassen (First Touch + Last Non-Direct, First-Party-Cookie <code>psl_attr</code>)</label></td>
			</tr>
			<tr>
				<th scope="row">dataLayer-Event</th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[tracking_datalayer]" value="1" <?php checked( $this->settings->trackingDataLayer() ); ?>> <code>property_lead</code> nach erfolgreicher Immobilienanfrage in den <code>dataLayer</code> schreiben</label></td>
			</tr>
			<tr>
				<th scope="row"><label for="psl-consent-provider">Consent-Provider</label></th>
				<td>
					<select id="psl-consent-provider" name="<?php echo esc_attr( $name ); ?>[consent_provider]">
						<?php foreach ( $providers as $id => $provider ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $current, $id ); ?>><?php echo esc_html( $provider->label() . ( $provider->isVerified() ? '' : ' – nicht getestet' ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Ohne Consent-System bleibt Tracking wirkungslos (sicherer Standard). Bei „JavaScript-API“ muss das Consent-Tool der Website die Marketing-Einwilligung auf jeder Seite melden: <code>window.PSLTracking.setConsent(true|false)</code>.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="psl-attr-ttl">Speicherdauer (Tage)</label></th>
				<td><input type="number" id="psl-attr-ttl" min="1" max="<?php echo esc_attr( (string) \PropstackLite\Tracking\AttributionStorage::MAX_TTL_DAYS ); ?>" name="<?php echo esc_attr( $name ); ?>[attribution_ttl_days]" value="<?php echo esc_attr( (string) $this->settings->attributionTtlDays() ); ?>"> <span class="description">Standard <?php echo esc_html( (string) \PropstackLite\Tracking\AttributionStorage::DEFAULT_TTL_DAYS ); ?> Tage; Touches älter als diese Dauer werden verworfen.</span></td>
			</tr>
			<tr>
				<th scope="row">Propstack-Custom-Fields (optional)</th>
				<td>
					<fieldset>
						<legend class="screen-reader-text">Propstack-Custom-Fields</legend>
						<?php foreach ( Settings::ATTRIBUTION_KEYS as $key ) : ?>
							<label style="display:block;margin-bottom:4px">
								<span style="display:inline-block;min-width:330px"><?php echo esc_html( $labels[ $key ] ?? $key ); ?> → <code>client_cf_</code></span>
								<input type="text" class="code" name="<?php echo esc_attr( $name ); ?>[cf_map][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $cfMap[ $key ] ?? '' ); ?>" placeholder="leer = nicht senden">
							</label>
						<?php endforeach; ?>
						<p class="description">Nur ausfüllen, wenn das Custom Field in Propstack existiert (das Plugin legt keine Felder an). Leer = wird nicht übertragen. Attributionswerte nur mit aktivierter Attribution und Marketing-Consent des Besuchers.</p>
					</fieldset>
				</td>
			</tr>
		</table>
		<?php
	}

	/** Konfigurationsprüfung der Anfragen (keine Secrets). */
	private function renderLeadStatus(): void {
		$check = ( new \PropstackLite\Leads\LeadSetupCheck( $this->settings ) )->run();
		$row   = static function ( string $label, bool $ok, string $okText, string $failText ): void {
			printf( '<tr><th>%s</th><td>%s %s</td></tr>', esc_html( $label ), $ok ? '✅' : '⚠️', esc_html( $ok ? $okText : $failText ) );
		};
		?>
		<h2>Immobilienanfragen – Status</h2>
		<table class="widefat striped" style="max-width:720px">
			<tbody>
				<?php
				$row( 'Contact Form 7', $check['cf7'], 'erkannt', 'nicht aktiv' );
				$row( 'Formular', null !== $check['form'], 'gültig: ' . (string) $check['form'], 'fehlt oder existiert nicht' );
				$row( 'Propstack-Zieladresse', $check['email'], 'gesetzt', 'fehlt' );
				$row( 'Formularfelder', [] === $check['missingFields'] && false !== $check['consentIsAcceptance'], 'vollständig', 'fehlend/ungültig: ' . implode( ', ', $check['missingFields'] ) . ( false === $check['consentIsAcceptance'] ? ' – Zustimmung ist kein acceptance-Feld' : '' ) );
				$row( 'Lead-Integration', $check['ready'], 'bereit', 'nicht vollständig konfiguriert – Detailseiten zeigen statt des Formulars einen neutralen Kontakthinweis' );
				?>
			</tbody>
		</table>
		<?php
	}

	/** SEO-Integration (Phase 5): welches Plugin die Detailseiten-Tags ausgibt, Sitemap-URL. */
	private function renderSeoBox(): void {
		$active  = \PropstackLite\Seo\SeoPlugins::active();
		$mode    = \PropstackLite\Seo\SeoPlugins::mode();
		$sitemap = \PropstackLite\Seo\SeoPlugins::CORE === $mode ? home_url( '/wp-sitemap-propstack-1.xml' ) : home_url( '/propstack-sitemap.xml' );
		?>
		<h2>SEO</h2>
		<table class="widefat striped" style="max-width:720px">
			<tbody>
				<tr><th>Ausgabe der Head-Tags</th><td><?php echo esc_html( \PropstackLite\Seo\SeoPlugins::LABELS[ $mode ] ); ?></td></tr>
				<?php if ( count( $active ) > 1 ) : ?>
					<tr><th>Hinweis</th><td>⚠️ <?php echo esc_html( 'Mehrere SEO-Plugins aktiv. Für Propstack-Detailseiten wird nur ' . \PropstackLite\Seo\SeoPlugins::LABELS[ $mode ] . ' integriert.' ); ?></td></tr>
				<?php endif; ?>
				<tr><th>Immobilien-Sitemap</th><td><a href="<?php echo esc_url( $sitemap ); ?>"><?php echo esc_html( $sitemap ); ?></a></td></tr>
			</tbody>
		</table>
		<?php
	}

	private function renderSyncBox(): void {
		$state  = ( new SyncState() )->get();
		$store  = PropertyStore::create();
		$counts = $store->countsByState();
		$fmt    = static fn ( $v ) => is_string( $v ) && '' !== $v ? get_date_from_gmt( $v, 'd.m.Y H:i:s' ) : '–';
		?>
		<h2>Sync-Status</h2>
		<table class="widefat striped" style="max-width:720px">
			<tbody>
				<tr><th>Öffentlich sichtbar</th><td><?php echo esc_html( (string) $store->countVisible( $this->settings->publicStatusIds() ) ); ?></td></tr>
				<tr><th>Im Bestand (aktiv / verkauft / entfernt)</th><td><?php echo esc_html( sprintf( '%d / %d / %d', $counts['active'], $counts['sold'], $counts['removed'] ) ); ?></td></tr>
				<tr><th>Letzter Voll-Sync</th><td><?php echo esc_html( $fmt( $state['last_full_at'] ?? null ) ); ?></td></tr>
				<tr><th>Letzter inkrementeller Sync</th><td><?php echo esc_html( $fmt( $state['last_incremental_at'] ?? null ) ); ?></td></tr>
				<tr><th>Letzter Fehler</th><td><?php echo esc_html( isset( $state['last_error'] ) ? $fmt( $state['last_error_at'] ?? null ) . ' – ' . $state['last_error'] : '–' ); ?></td></tr>
				<tr><th>Nächster geplanter Sync</th><td><?php echo esc_html( ( $next = wp_next_scheduled( 'psl_sync_incremental' ) ) ? wp_date( 'd.m.Y H:i:s', $next ) : 'nicht geplant' ); ?></td></tr>
				<tr><th>Sync läuft gerade</th><td><?php echo esc_html( ( new SyncLock() )->isLocked() ? 'ja' : 'nein' ); ?></td></tr>
			</tbody>
		</table>
		<p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SYNC ); ?>">
				<?php wp_nonce_field( self::ACTION_SYNC ); ?>
				<?php submit_button( 'Jetzt vollständig synchronisieren', 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_STATUSES ); ?>">
				<?php wp_nonce_field( self::ACTION_STATUSES ); ?>
				<?php submit_button( 'Statusliste neu laden', 'secondary', 'submit', false ); ?>
			</form>
		</p>
		<p class="description">Diagnose per WP-CLI: <code>wp psl status</code>, <code>wp psl sync --full</code>, <code>wp psl audit</code>.</p>
		<?php
	}

	private function renderSyncResultNotice(): void {
		if ( ! isset( $_GET['psl_synced'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Anzeige.
			return;
		}
		$key     = 'psl_admin_sync_result_' . get_current_user_id();
		$summary = get_transient( $key );
		delete_transient( $key );
		if ( ! is_string( $summary ) ) {
			return;
		}
		$ok = '1' === $_GET['psl_synced']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		printf( '<div class="notice notice-%s"><p>%s</p></div>', $ok ? 'success' : 'error', esc_html( $summary ) );
	}
}
