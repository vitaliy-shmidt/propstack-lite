<?php
/**
 * Plugin Name:       Propstack Listings Lite
 * Description:       Propstack-Immobilien in WordPress: Synchronisation in einen lokalen Bestand und Ausgabe per Shortcode.
 * Version:           0.9.0
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.1
 * Author:            Vitaliy
 * License:           GPLv2 or later
 * Text Domain:       propstack-lite
 */

/*
 * Diese Datei muss auch unter alten PHP-Versionen (7.x) fehlerfrei parsen: Sie prüft die Voraussetzungen,
 * BEVOR Klassen aus src/ (PHP 8.1-Syntax) geladen werden. Sind PHP oder WordPress zu alt, bootet das Plugin
 * nicht, die Aktivierung wird mit einer verständlichen Meldung abgebrochen und Administratoren sehen einen
 * Hinweis – kein Fatal Error. Versionsnummer: einzige Quelle ist PSL_VERSION (Header muss übereinstimmen,
 * geprüft von bin/build-release.php und tests/Unit/ReleaseTest.php).
 */

defined( 'ABSPATH' ) || exit;

define( 'PSL_VERSION', '0.9.0' );
define( 'PSL_MIN_PHP', '8.1' );
define( 'PSL_MIN_WP', '6.4' );
define( 'PSL_FILE', __FILE__ );
define( 'PSL_DIR', plugin_dir_path( __FILE__ ) );
define( 'PSL_URL', plugin_dir_url( __FILE__ ) );

if ( ! function_exists( 'psl_requirement_errors' ) ) {
/** Fehlende Voraussetzungen als deutsche Sätze (leer = alles erfüllt). */
function psl_requirement_errors() {
	$errors = array();
	if ( version_compare( PHP_VERSION, PSL_MIN_PHP, '<' ) ) {
		$errors[] = sprintf( 'PHP %1$s oder neuer ist erforderlich (aktiv: PHP %2$s).', PSL_MIN_PHP, PHP_VERSION );
	}
	$wp = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '0';
	if ( version_compare( $wp, PSL_MIN_WP, '<' ) ) {
		$errors[] = sprintf( 'WordPress %1$s oder neuer ist erforderlich (aktiv: WordPress %2$s).', PSL_MIN_WP, $wp );
	}
	return $errors;
}
}

if ( array() !== psl_requirement_errors() ) {
	// Aktivierung verhindern – ohne Klassen aus src/ zu laden.
	register_activation_hook(
		__FILE__,
		function () {
			deactivate_plugins( plugin_basename( PSL_FILE ) );
			wp_die(
				'<p><strong>Propstack Listings Lite kann nicht aktiviert werden.</strong></p><p>' . esc_html( implode( ' ', psl_requirement_errors() ) ) . '</p>',
				'Propstack Listings Lite',
				array( 'back_link' => true )
			);
		}
	);
	// War das Plugin schon aktiv (z. B. PHP auf dem Server herabgestuft): nicht booten, Admins informieren.
	add_action(
		'admin_notices',
		function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p><strong>Propstack Listings Lite ist inaktiv:</strong> ' . esc_html( implode( ' ', psl_requirement_errors() ) ) . ' Immobilienliste und Detailseiten werden nicht ausgegeben.</p></div>';
			}
		}
	);
	// Shortcode nicht als Rohtext auf der Website stehen lassen.
	add_action(
		'init',
		function () {
			add_shortcode(
				'propstack_list',
				function () {
					return '';
				}
			);
		}
	);
	return;
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'PropstackLite\\';
		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$file = PSL_DIR . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, [ \PropstackLite\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \PropstackLite\Plugin::class, 'deactivate' ] );

add_action(
	'plugins_loaded',
	static function (): void {
		\PropstackLite\Plugin::instance()->boot();
	}
);
