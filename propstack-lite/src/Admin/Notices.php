<?php

namespace PropstackLite\Admin;

use PropstackLite\Settings;
use PropstackLite\Sync\SyncState;

/** Admin-Hinweise bei fehlender Konfiguration oder Sync-Fehlern (nur für manage_options). */
final class Notices {

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->id, [ 'dashboard', 'plugins', 'settings_page_' . SettingsPage::SLUG ], true ) ) {
			return;
		}

		$messages = [];
		if ( ! $this->settings->hasApiKey() ) {
			$messages[] = [ 'error', 'Propstack Lite: Es ist kein API-Key hinterlegt.' ];
		}
		if ( [] === $this->settings->publicStatusIds() ) {
			$messages[] = [ 'warning', 'Propstack Lite: Es sind keine öffentlichen Propstack-Status ausgewählt – auf der Website erscheinen keine Immobilien.' ];
		}
		if ( ! \PropstackLite\Leads\Cf7Integration::isAvailable() ) {
			$messages[] = [ 'warning', 'Contact Form 7 ist nicht aktiv. Immobilienanfragen sind derzeit deaktiviert.' ];
		}
		if ( count( \PropstackLite\Seo\SeoPlugins::active() ) > 1 ) {
			$messages[] = [ 'warning', 'Mehrere SEO-Plugins aktiv. Für Propstack-Detailseiten wird nur ' . \PropstackLite\Seo\SeoPlugins::LABELS[ \PropstackLite\Seo\SeoPlugins::mode() ] . ' integriert.' ];
		}
		$state = new SyncState();
		if ( $state->hasUnresolvedError() ) {
			$messages[] = [ 'error', 'Propstack Lite: Der letzte Sync ist fehlgeschlagen – ' . ( $state->get()['last_error'] ?? '' ) ];
		}

		foreach ( $messages as [ $type, $text ] ) {
			printf(
				'<div class="notice notice-%1$s"><p>%2$s <a href="%3$s">Einstellungen öffnen</a></p></div>',
				esc_attr( $type ),
				esc_html( $text ),
				esc_url( SettingsPage::url() )
			);
		}
	}
}
