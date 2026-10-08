<?php
/**
 * Test-Bootstrap.
 *
 * Unit-Tests laufen ohne WordPress. Integrationstests benötigen eine WordPress-Testinstanz:
 * Umgebungsvariable PSL_WP_LOAD auf deren wp-load.php setzen. ACHTUNG: Integrationstests
 * leeren die Tabelle {prefix}psl_properties und ändern Plugin-Optionen – nur gegen eine
 * Wegwerf-Instanz ausführen, niemals gegen Staging/Produktion.
 */

$loader = require dirname( __DIR__ ) . '/vendor/autoload.php';

$wpLoad = getenv( 'PSL_WP_LOAD' );
if ( is_string( $wpLoad ) && '' !== $wpLoad ) {
	if ( ! is_readable( $wpLoad ) ) {
		fwrite( STDERR, "PSL_WP_LOAD zeigt nicht auf eine lesbare wp-load.php\n" );
		exit( 1 );
	}
	$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
	$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
	define( 'PSL_RUNNING_TESTS', true );
	require $wpLoad;
	// Mit WordPress: Plugin-Klassen aus der INSTALLIERTEN Plugin-Kopie laden (z. B. aus dem Release-ZIP),
	// nicht aus dem Repository – so ist bei Artefakt-Tests das ausgelieferte Paket das Testobjekt.
	// Testklassen (PropstackLite\Tests\) kommen weiter aus tests/.
	$loader->setPsr4( 'PropstackLite\\', [] );
}

define( 'PSL_FIXTURES', __DIR__ . '/fixtures' );
