<?php
/**
 * NUR FÜR TESTINSTANZEN – nicht ausliefern.
 *
 * Test-Hooks für tests/Http: Wenn die Option `psl_test_status_label` gesetzt ist, ersetzt sie das
 * Status-Badge (Filter `psl_property_status_label`) – damit lässt sich prüfen, dass auch
 * Filter-Ausgaben im Template escaped werden. Ohne Option wirkungslos.
 */

add_filter(
	'psl_property_status_label',
	static function ( $label ) {
		$override = get_option( 'psl_test_status_label' );
		return is_string( $override ) && '' !== $override ? $override : $label;
	}
);
