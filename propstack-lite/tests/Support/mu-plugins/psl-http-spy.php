<?php
/**
 * NUR FÜR TESTINSTANZEN – nicht ausliefern.
 *
 * Protokolliert jeden ausgehenden HTTP-Request (WordPress HTTP API) mit Kontext
 * (web/cron/cli) nach wp-content/psl-http-spy.log. Wird von tests/Http genutzt, um
 * nachzuweisen, dass Besucher-Requests keine Propstack-Requests auslösen.
 * Installation: Datei nach wp-content/mu-plugins/ der Testinstanz kopieren.
 */

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			$context = 'cron';
		} elseif ( defined( 'WP_CLI' ) && WP_CLI ) {
			$context = 'cli';
		} else {
			$context = 'web ' . ( $_SERVER['REQUEST_URI'] ?? '' );
		}
		$line = gmdate( 'H:i:s' ) . " [{$context}] " . wp_parse_url( $url, PHP_URL_HOST ) . wp_parse_url( $url, PHP_URL_PATH ) . "\n";
		file_put_contents( WP_CONTENT_DIR . '/psl-http-spy.log', $line, FILE_APPEND );
		return $pre;
	},
	1,
	3
);
