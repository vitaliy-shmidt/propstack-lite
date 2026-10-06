<?php
/**
 * Plugin Name:       Propstack Listings Lite
 * Description:       Propstack-Immobilien in WordPress: Synchronisation in einen lokalen Bestand und Ausgabe per Shortcode.
 * Version:           0.5.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Vitaliy
 * License:           GPLv2 or later
 * Text Domain:       propstack-lite
 */

defined( 'ABSPATH' ) || exit;

define( 'PSL_VERSION', '0.5.0' );
define( 'PSL_FILE', __FILE__ );
define( 'PSL_DIR', plugin_dir_path( __FILE__ ) );
define( 'PSL_URL', plugin_dir_url( __FILE__ ) );

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
