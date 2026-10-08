<?php
/**
 * Kompatibilitäts-Smoketest des Bootstrap-Guards (ohne WordPress, ohne PHPUnit – läuft auch unter PHP 7.x):
 *
 *   php tests/compat/requirements-guard.php
 *
 * - Unter PHP < 8.1: echter Fall „PHP zu alt“.
 * - Unter PHP ≥ 8.1: simuliert WordPress 6.3 (zu alt).
 * Erwartung: kein Fatal Error, keine Klasse aus src/ geladen, Aktivierung wird mit Meldung abgebrochen,
 * Admin-Hinweis und leerer Shortcode-Platzhalter sind registriert. Exit-Code 0 = bestanden.
 * Bewusst PHP-7.0-kompatible Syntax.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['wp_version'] = version_compare( PHP_VERSION, '8.1', '<' ) ? '7.1.2' : '6.3';

$GLOBALS['psl_hooks'] = array();
$GLOBALS['psl_calls'] = array();

function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.org/wp-content/plugins/propstack-lite/'; }
function plugin_basename( $f ) { return 'propstack-lite/propstack-lite.php'; }
function register_activation_hook( $f, $cb ) { $GLOBALS['psl_hooks']['activate'] = $cb; }
function register_deactivation_hook( $f, $cb ) { $GLOBALS['psl_hooks']['deactivate'] = $cb; }
function add_action( $hook, $cb ) { $GLOBALS['psl_hooks'][ $hook ] = $cb; }
function add_shortcode( $tag, $cb ) { $GLOBALS['psl_hooks'][ 'shortcode:' . $tag ] = $cb; }
function current_user_can( $cap ) { return true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function deactivate_plugins( $p ) { $GLOBALS['psl_calls'][] = 'deactivate_plugins:' . $p; }
function wp_die( $msg, $title = '', $args = array() ) { $GLOBALS['psl_calls'][] = 'wp_die:' . $msg; }

$failures = array();
$check    = function ( $ok, $label ) use ( &$failures ) {
	echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n";
	if ( ! $ok ) {
		$failures[] = $label;
	}
};

require dirname( __DIR__, 2 ) . '/propstack-lite.php';

$check( defined( 'PSL_VERSION' ), 'PSL_VERSION definiert' );
$check( ! class_exists( 'PropstackLite\\Plugin', false ), 'keine Plugin-Klasse geladen (kein Autoloader-Boot)' );
$check( ! isset( $GLOBALS['psl_hooks']['plugins_loaded'] ), 'kein Boot über plugins_loaded' );
$check( isset( $GLOBALS['psl_hooks']['activate'] ) && $GLOBALS['psl_hooks']['activate'] instanceof Closure, 'Aktivierungs-Guard registriert' );

call_user_func( $GLOBALS['psl_hooks']['activate'] );
$calls = implode( "\n", $GLOBALS['psl_calls'] );
$check( false !== strpos( $calls, 'deactivate_plugins:propstack-lite/propstack-lite.php' ), 'Aktivierung wird zurückgenommen' );
$check( false !== strpos( $calls, 'kann nicht aktiviert werden' ), 'verständliche Meldung bei Aktivierung' );
$check( false !== strpos( $calls, version_compare( PHP_VERSION, '8.1', '<' ) ? 'PHP 8.1 oder neuer' : 'WordPress 6.4 oder neuer' ), 'Meldung nennt die Mindestversion' );

ob_start();
call_user_func( $GLOBALS['psl_hooks']['admin_notices'] );
$notice = (string) ob_get_clean();
$check( false !== strpos( $notice, 'Propstack Listings Lite ist inaktiv' ), 'Admin-Hinweis bei bereits aktivem Plugin' );

call_user_func( $GLOBALS['psl_hooks']['init'] );
$check( '' === call_user_func( $GLOBALS['psl_hooks']['shortcode:propstack_list'] ), 'Shortcode gibt nichts aus (kein Rohtext auf der Website)' );

echo ( array() === $failures ? "BESTANDEN" : 'FEHLGESCHLAGEN: ' . count( $failures ) ) . ' (PHP ' . PHP_VERSION . ', WP ' . $GLOBALS['wp_version'] . ")\n";
exit( array() === $failures ? 0 : 1 );
