<?php

namespace PropstackLite\Admin;

/**
 * Admin-Hinweise – sparsam (nur manage_options):
 * - Plugins-Seite: nur kritische Probleme und fehlschlagende Syncs (Betrieb gefährdet).
 * - Einstellungsseite des Plugins: alle Prüfungen (inkl. Empfehlungen).
 * - Überall sonst nichts; Details stehen in Site Health und auf der Einstellungsseite.
 * Bewertung über Diagnostics (stabile Codes, siehe docs/operations.md).
 */
final class Notices {

	public function __construct( private Diagnostics $diagnostics ) {}

	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		if ( ! in_array( $id, [ 'plugins', 'settings_page_' . SettingsPage::SLUG ], true ) ) {
			return;
		}

		$checks = $this->diagnostics->checks();
		if ( 'plugins' === $id ) {
			$checks = array_filter( $checks, static fn ( $c ) => Diagnostics::CRITICAL === $c['severity'] || Diagnostics::GROUP_SYNC === $c['group'] && ! in_array( $c['code'], [ 'sync_never', 'sync_stale', 'cron_overdue' ], true ) );
		}

		foreach ( $checks as $check ) {
			printf(
				'<div class="notice notice-%1$s"><p><strong>Propstack Lite:</strong> %2$s <code>%3$s</code>%4$s</p></div>',
				Diagnostics::CRITICAL === $check['severity'] ? 'error' : 'warning',
				esc_html( $check['message'] ),
				esc_html( $check['code'] ),
				'plugins' === $id ? ' <a href="' . esc_url( SettingsPage::url() ) . '">Einstellungen öffnen</a>' : ''
			);
		}
	}
}
