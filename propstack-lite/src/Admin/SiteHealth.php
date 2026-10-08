<?php

namespace PropstackLite\Admin;

/**
 * Site Health (Werkzeuge → Website-Zustand): fünf gebündelte Tests statt einer Flut von Einzelwarnungen
 * (Konfiguration, Datenbank, Synchronisation, Anfragen, Tracking/SEO) plus Abschnitt im Info-Tab.
 * Bewertung ausschließlich über Diagnostics::evaluate() – dieselben Codes wie Admin und WP-CLI.
 */
final class SiteHealth {

	private const TESTS = [
		'config'   => [ Diagnostics::GROUP_CONFIG, 'Propstack-Anbindung ist konfiguriert', 'Propstack-Anbindung ist unvollständig' ],
		'database' => [ Diagnostics::GROUP_DATABASE, 'Immobilienbestand: Datenbank in Ordnung', 'Immobilienbestand: Datenbankproblem' ],
		'sync'     => [ Diagnostics::GROUP_SYNC, 'Propstack-Synchronisation läuft', 'Propstack-Synchronisation braucht Aufmerksamkeit' ],
		'leads'    => [ Diagnostics::GROUP_LEADS, 'Immobilienanfragen: keine Probleme erkannt', 'Immobilienanfragen sind nicht vollständig eingerichtet' ],
		'tracking' => [ [ Diagnostics::GROUP_TRACKING, Diagnostics::GROUP_SEO ], 'Tracking und SEO-Integration: keine Probleme erkannt', 'Tracking/SEO-Integration prüfen' ],
	];

	private ?array $facts = null;

	public function __construct( private Diagnostics $diagnostics ) {}

	public function register(): void {
		add_filter( 'site_status_tests', [ $this, 'tests' ] );
		add_filter( 'debug_information', [ $this, 'debugInformation' ] );
	}

	public function tests( mixed $tests ): mixed {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}
		foreach ( array_keys( self::TESTS ) as $key ) {
			$tests['direct'][ 'propstack_lite_' . $key ] = [
				'label' => 'Propstack Listings Lite',
				'test'  => fn () => $this->result( $key ),
			];
		}
		return $tests;
	}

	/** @return array<string, mixed> Site-Health-Ergebnis eines Tests */
	public function result( string $key ): array {
		[ $groups, $okLabel, $failLabel ] = self::TESTS[ $key ];
		$checks = array_values( array_filter( Diagnostics::evaluate( $this->facts() ), static fn ( $c ) => in_array( $c['group'], (array) $groups, true ) ) );

		$status = 'good';
		foreach ( $checks as $check ) {
			$status = Diagnostics::CRITICAL === $check['severity'] ? 'critical' : ( 'critical' === $status ? 'critical' : 'recommended' );
		}

		$items = '';
		foreach ( $checks as $check ) {
			$items .= '<li><code>' . esc_html( $check['code'] ) . '</code> ' . esc_html( $check['message'] ) . '</li>';
		}

		return [
			'label'       => 'good' === $status ? $okLabel : $failLabel,
			'status'      => $status,
			'badge'       => [ 'label' => 'Propstack', 'color' => 'good' === $status ? 'blue' : ( 'critical' === $status ? 'red' : 'orange' ) ],
			'description' => '' === $items ? '<p>Keine Probleme erkannt.</p>' : '<ul>' . $items . '</ul>',
			'actions'     => sprintf( '<p><a href="%s">Propstack-Einstellungen und Diagnose öffnen</a></p>', esc_url( SettingsPage::url() ) ),
			'test'        => 'propstack_lite_' . $key,
		];
	}

	public function debugInformation( mixed $info ): mixed {
		if ( ! is_array( $info ) ) {
			return $info;
		}
		$fields = [];
		foreach ( Diagnostics::summary( $this->facts() ) as $label => $value ) {
			$fields[ sanitize_key( $label ) ] = [ 'label' => $label, 'value' => $value ];
		}
		$info['propstack-lite'] = [ 'label' => 'Propstack Listings Lite', 'fields' => $fields ];
		return $info;
	}

	private function facts(): array {
		return $this->facts ??= $this->diagnostics->facts();
	}
}
