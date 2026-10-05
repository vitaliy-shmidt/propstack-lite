<?php
/**
 * NUR FÜR TESTINSTANZEN – nicht ausliefern.
 *
 * Test-Hooks für tests/Http (alle nur aktiv, wenn die jeweilige Option gesetzt ist):
 * - psl_test_status_label: ersetzt das Status-Badge (Filter psl_property_status_label),
 *   um Escaping von Filter-Ausgaben zu prüfen.
 * - psl_test_mail_capture: fängt JEDE Mail über pre_wp_mail ab, schreibt sie nach
 *   wp-content/psl-mail-capture.jsonl und verschickt nichts.
 * - psl_test_mail_fail: simuliert einen fehlgeschlagenen Mailversand.
 */

add_filter(
	'psl_property_status_label',
	static function ( $label ) {
		$override = get_option( 'psl_test_status_label' );
		return is_string( $override ) && '' !== $override ? $override : $label;
	}
);

add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		if ( get_option( 'psl_test_mail_fail' ) ) {
			return false;
		}
		if ( ! get_option( 'psl_test_mail_capture' ) ) {
			return $short;
		}
		$record = [
			'to'      => $atts['to'] ?? '',
			'subject' => $atts['subject'] ?? '',
			'message' => $atts['message'] ?? '',
			'headers' => $atts['headers'] ?? [],
		];
		file_put_contents( WP_CONTENT_DIR . '/psl-mail-capture.jsonl', wp_json_encode( $record ) . "\n", FILE_APPEND );
		return true;
	},
	10,
	2
);
