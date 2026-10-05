<?php

namespace PropstackLite\Tests\Http;

use PropstackLite\Domain\Property;
use PropstackLite\Mapping\PropertyMapper;
use PropstackLite\Routing\UrlGenerator;
use PropstackLite\Tests\Integration\IntegrationTestCase;

/**
 * End-to-End-Tests der Detailseiten über echte HTTP-Requests.
 *
 * Voraussetzungen (siehe docs/testing.md):
 *  - PSL_WP_LOAD      wp-load.php der Wegwerf-Testinstanz (zum Anlegen der Testdaten)
 *  - PSL_TEST_BASE_URL laufender Webserver derselben Instanz, z. B. http://127.0.0.1:8099/Picaflor
 *  - optional mu-plugin tests/Support/mu-plugins/psl-http-spy.php für den Request-Nachweis
 */
final class DetailRoutingTest extends IntegrationTestCase {

	private const PUBLIC   = 701;
	private const RESERVED = 702;
	private const SOLD     = 703;
	private const OTHER    = 799;

	private const ID_ACTIVE      = 990000001;
	private const ID_RESERVED    = 990000002;
	private const ID_SOLD_RENT   = 990000003;
	private const ID_SOLD_OLD    = 990000004;
	private const ID_REMOVED     = 990000005;
	private const ID_NOT_PUBLIC  = 990000006;
	private const ID_XSS_DIRECT  = 990000007;
	private const ID_UNKNOWN     = 990000999;

	private const XSS = '<script>alert(1)</script>';

	private string $base;

	/** @var list<array{case: string, url: string, expected: int, actual: int}> */
	private static array $matrix = [];

	protected function setUp(): void {
		$base = getenv( 'PSL_TEST_BASE_URL' );
		if ( ! is_string( $base ) || '' === $base ) {
			$this->markTestSkipped( 'PSL_TEST_BASE_URL nicht gesetzt (laufender Testserver erforderlich).' );
		}
		parent::setUp();
		$this->base = rtrim( $base, '/' );
		$this->assertSame( $this->base, rtrim( home_url(), '/' ), 'Testserver und PSL_WP_LOAD müssen dieselbe Instanz sein' );
		$this->seed();
	}

	public static function tearDownAfterClass(): void {
		if ( [] !== self::$matrix ) {
			file_put_contents( sys_get_temp_dir() . '/psl-routing-matrix.json', json_encode( self::$matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		}
	}

	/* ------------------------------------------------------------- Testdaten */

	private function seed(): void {
		$this->settings(
			[
				'public_status_ids'   => [ self::PUBLIC, self::RESERVED ],
				'reserved_status_ids' => [ self::RESERVED ],
				'sold_status_ids'     => [ self::SOLD ],
			]
		);
		$mapper = new PropertyMapper();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$fixture = json_decode( (string) file_get_contents( PSL_FIXTURES . '/unit-buy-public.json' ), true );

		// Aktiv: verborgene Adresse, private/ausgeschlossene Bilder, interne Felder (Köder) und XSS über den Mapper.
		$active = array_merge(
			$fixture,
			[
				'id'              => self::ID_ACTIVE,
				'property_status' => [ 'id' => self::PUBLIC, 'name' => 'Website' ],
				'hide_address'    => true,
				'title'           => [ 'label' => 'Titel', 'value' => 'Lichtdurchflutet ' . self::XSS ],
			]
		);
		$active['description_note']['value'] = 'Beschreibung ' . self::XSS . ' <img src=x onerror=alert(2)> Ende';
		$active['broker']['name']             = 'Erika ' . self::XSS;
		$active['images'][0]['title']         = 'Wohnzimmer " onload="alert(3)';
		$this->store->upsertActive( $mapper->map( $active ), $now );

		$this->store->upsertActive( $mapper->map( self::raw( self::ID_RESERVED, self::RESERVED, [ 'title' => [ 'label' => 'T', 'value' => 'Reserviertes Haus' ] ] ) ), $now );

		$this->store->upsertActive( $mapper->map( self::raw( self::ID_SOLD_RENT, self::PUBLIC, [ 'marketing_type' => 'RENT', 'base_rent' => 1100.0, 'price' => null, 'title' => [ 'label' => 'T', 'value' => 'Vermietete Wohnung' ] ] ) ), $now );
		$this->store->markSold( self::ID_SOLD_RENT, self::SOLD, gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );

		$this->store->upsertActive( $mapper->map( self::raw( self::ID_SOLD_OLD, self::PUBLIC, [ 'title' => [ 'label' => 'T', 'value' => 'Altes Verkauftobjekt' ] ] ) ), $now );
		$this->store->markSold( self::ID_SOLD_OLD, self::SOLD, gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) );

		$this->store->upsertActive( $mapper->map( self::raw( self::ID_REMOVED, self::PUBLIC ) ), $now );
		$this->store->markRemoved( self::ID_REMOVED, self::OTHER, $now );

		$this->store->upsertActive( $mapper->map( self::raw( self::ID_NOT_PUBLIC, self::OTHER, [ 'title' => [ 'label' => 'T', 'value' => 'NICHT-OEFFENTLICH' ] ] ) ), $now );

		// Defense in Depth: unbereinigte Werte direkt im Store (am Mapper vorbei) – Templates müssen escapen.
		$this->store->upsertActive(
			Property::fromArray(
				[
					'id'            => self::ID_XSS_DIRECT,
					'slug'          => 'xss-test',
					'title'         => 'Direkt ' . self::XSS,
					'statusId'      => self::PUBLIC,
					'marketingType' => 'BUY',
					'price'         => 1.0,
					'address'       => [ 'hidden' => true, 'city' => 'Ort ' . self::XSS ],
					'texts'         => [ 'description' => 'Text ' . self::XSS ],
					'images'        => [ [ 'id' => 1, 'title' => 'Bild "><script>alert(4)</script>', 'big' => 'javascript:alert(5)' ] ],
					'agent'         => [ 'name' => 'Makler ' . self::XSS, 'email' => 'x@example.com', 'avatarUrl' => 'javascript:alert(6)' ],
				]
			),
			$now
		);
	}

	private function url( int $id ): string {
		$row = $this->store->find( $id );
		return ( new UrlGenerator() )->canonicalUrl( $row );
	}

	/** @return array{status: int, headers: array<string, string>, body: string} */
	private function get( string $url ): array {
		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			[
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER         => true,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_TIMEOUT        => 30,
			]
		);
		$raw     = (string) curl_exec( $ch );
		$status  = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$hsize   = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$headers = [];
		foreach ( explode( "\r\n", substr( $raw, 0, $hsize ) ) as $line ) {
			if ( str_contains( $line, ':' ) ) {
				[ $k, $v ]                       = explode( ':', $line, 2 );
				$headers[ strtolower( trim( $k ) ) ] = trim( $v );
			}
		}
		return [ 'status' => $status, 'headers' => $headers, 'body' => substr( $raw, $hsize ) ];
	}

	private function check( string $case, string $url, int $expected, ?string $location = null ): array {
		$response       = $this->get( $url );
		self::$matrix[] = [ 'case' => $case, 'url' => $url, 'expected' => $expected, 'actual' => $response['status'], 'location' => $response['headers']['location'] ?? '' ];
		$this->assertSame( $expected, $response['status'], $case . ' – ' . $url );
		if ( null !== $location ) {
			$this->assertSame( $location, $response['headers']['location'] ?? null, $case . ' – Location' );
		}
		return $response;
	}

	/* ---------------------------------------------------------------- Tests */

	public function test_routing_matrix(): void {
		$canonical = $this->url( self::ID_ACTIVE );
		$this->assertStringStartsWith( $this->base . '/immobilien/', $canonical, 'Unterverzeichnis in kanonischer URL' );

		$this->check( 'gültig + korrekter Slug', $canonical, 200 );
		$this->check( 'gültig + falscher Slug', $this->base . '/immobilien/alter-falscher-name-' . self::ID_ACTIVE . '/', 301, $canonical );
		$this->check( 'falscher Slug + Kampagnenparameter', $this->base . '/immobilien/alt-' . self::ID_ACTIVE . '/?utm_source=google&gclid=abc', 301, $canonical . '?utm_source=google&gclid=abc' );
		$this->check( 'nur ID', $this->base . '/immobilien/' . self::ID_ACTIVE . '/', 301, $canonical );
		$this->check( 'ohne Trailing Slash', rtrim( $canonical, '/' ), 301, $canonical );
		$this->check( 'Großschreibung im Slug', str_replace( '/immobilien/', '/immobilien/X', $canonical ), 301, $canonical );
		$this->check( 'unbekannte ID', $this->base . '/immobilien/irgendwas-' . self::ID_UNKNOWN . '/', 404 );
		$this->check( 'reserviert', $this->url( self::ID_RESERVED ), 200 );
		$this->check( 'verkauft/vermietet ≤ 30 Tage', $this->url( self::ID_SOLD_RENT ), 200 );
		$this->check( 'verkauft > 30 Tage', $this->url( self::ID_SOLD_OLD ), 410 );
		$this->check( 'entfernt', $this->url( self::ID_REMOVED ), 410 );
		$this->check( 'entfernt + falscher Slug (kein Redirect)', $this->base . '/immobilien/x-' . self::ID_REMOVED . '/', 410 );
		$this->check( 'aktiv, Status nicht mehr öffentlich', $this->url( self::ID_NOT_PUBLIC ), 410 );
		$this->check( 'Legacy /immobilie/{slug}-{id}/', $this->base . '/immobilie/alter-titel-' . self::ID_ACTIVE . '/', 301, $canonical );
		$this->check( 'Legacy /immobilie/{id}/', $this->base . '/immobilie/' . self::ID_ACTIVE . '/', 301, $canonical );
		$this->check( 'Legacy ?ps_id=', $this->base . '/immobilie/?ps_id=' . self::ID_ACTIVE, 301, $canonical );
		$this->check( 'Legacy ?ps_id= + utm', $this->base . '/immobilie/?ps_id=' . self::ID_ACTIVE . '&utm_medium=cpc', 301, $canonical . '?utm_medium=cpc' );
		$this->check( 'Legacy entfernt → kanonisch (dort 410)', $this->base . '/immobilie/' . self::ID_REMOVED . '/', 301, $this->url( self::ID_REMOVED ) );
		$this->check( 'Legacy unbekannte ID', $this->base . '/immobilie/?ps_id=' . self::ID_UNKNOWN, 404 );
		$this->check( 'Legacy ungültige ID', $this->base . '/immobilie/?ps_id=abc', 404 );
		$this->check( 'Legacy ohne ID', $this->base . '/immobilie/', 404 );
		$this->check( 'Übersicht', $this->base . '/immobilien/', 200 );
	}

	public function test_active_page_head_and_content(): void {
		$canonical = $this->url( self::ID_ACTIVE );
		$r         = $this->get( $canonical );

		$this->assertStringContainsString( '<link rel="canonical" href="' . $canonical . '" />', $r['body'] );
		$this->assertSame( 1, substr_count( $r['body'], 'rel="canonical"' ), 'genau ein Canonical' );
		$this->assertStringNotContainsString( 'noindex', $r['body'] );
		$this->assertArrayNotHasKey( 'x-robots-tag', $r['headers'] );
		$this->assertMatchesRegularExpression( '/<body[^>]*class="[^"]*psl-property psl-property--active/', $r['body'] );
		// wp_strip_all_tags entfernt <script>-Blöcke samt Inhalt bereits beim Mapping.
		$this->assertMatchesRegularExpression( '#<title>Lichtdurchflutet &\#8211; #', $r['body'], 'Dokumenttitel aus Objekttitel' );
		$this->assertStringContainsString( 'psl-detail.css', $r['body'] );
		$this->assertStringContainsString( 'id="psl-contact"', $r['body'], 'Platzhalter Kontaktformular' );
		$this->assertStringContainsString( 'Kaufpreis', $r['body'] );
		$this->assertStringContainsString( '354.200 €', $r['body'] );
		$this->assertStringContainsString( 'Wohnfläche', $r['body'] );
	}

	public function test_privacy_rules_in_html(): void {
		$body = $this->get( $this->url( self::ID_ACTIVE ) )['body'];

		$this->assertStringNotContainsString( 'Musterstraße', $body, 'hide_address: keine Straße' );
		$this->assertStringNotContainsString( '>12<', $body );
		$this->assertStringContainsString( '10115 Berlin', $body, 'nur PLZ/Ort' );
		$this->assertStringNotContainsString( 'PRIVATE', $body, 'keine privaten Bilder' );
		$this->assertStringNotContainsString( 'NOEXPOSE', $body, 'keine is_not_for_exposee-Bilder' );
		foreach ( [ 'INTERN: Eigentümer', 'INTERNAL-TOKEN', 'intern.makler@example.com', '1111111', 'ph-intern', 'Sensible Daten', 'Grundbuchauszug', 'crm.propstack.de/public' ] as $internal ) {
			$this->assertStringNotContainsString( $internal, $body, "interner Wert im HTML: {$internal}" );
		}
		$this->assertStringContainsString( 'beratung@example.com', $body, 'öffentliche Makler-E-Mail' );
	}

	public function test_xss_is_never_executable(): void {
		foreach ( [ self::ID_ACTIVE, self::ID_XSS_DIRECT ] as $id ) {
			$body = $this->get( $this->url( $id ) )['body'];
			$this->assertStringNotContainsString( '<script>alert(', $body, "Objekt {$id}" );
			$this->assertStringNotContainsString( 'onerror=alert', $body );
			$this->assertStringNotContainsString( 'onload="alert', $body );
			$this->assertStringNotContainsString( 'javascript:alert', $body );
		}
		$direct = $this->get( $this->url( self::ID_XSS_DIRECT ) )['body'];
		$this->assertStringContainsString( 'Direkt &lt;script&gt;alert(1)&lt;/script&gt;', $direct, 'Titel escaped' );
		$this->assertStringContainsString( 'Makler &lt;script&gt;', $direct, 'Maklername escaped' );
	}

	public function test_reserved_badge(): void {
		$r = $this->get( $this->url( self::ID_RESERVED ) );
		$this->assertStringContainsString( 'psl-badge--reserved">Reserviert<', $r['body'] );
		$this->assertStringNotContainsString( 'noindex', $r['body'] );
		$this->assertStringContainsString( 'id="psl-contact"', $r['body'] );
	}

	public function test_sold_phase_is_noindex_without_contact_and_not_listed(): void {
		$r = $this->get( $this->url( self::ID_SOLD_RENT ) );
		$this->assertSame( 200, $r['status'] );
		$this->assertStringContainsString( 'psl-badge--sold">Vermietet<', $r['body'], 'RENT → Vermietet' );
		$this->assertSame( 'noindex, follow', $r['headers']['x-robots-tag'] ?? null );
		$this->assertMatchesRegularExpression( "/<meta name='robots' content='[^']*noindex[^']*follow/", $r['body'] );
		$this->assertStringNotContainsString( 'id="psl-contact"', $r['body'], 'kein Kontaktformular' );
		$this->assertStringContainsString( 'Diese Immobilie ist bereits vermietet.', $r['body'] );

		$list = $this->get( $this->base . '/immobilien/' )['body'];
		$this->assertStringNotContainsString( 'Vermietete Wohnung', $list, 'nicht in der Übersicht' );
		$this->assertStringNotContainsString( 'NICHT-OEFFENTLICH', $list );
	}

	public function test_gone_page(): void {
		$r = $this->get( $this->url( self::ID_REMOVED ) );
		$this->assertSame( 410, $r['status'] );
		$this->assertSame( 'noindex, follow', $r['headers']['x-robots-tag'] ?? null );
		$this->assertStringContainsString( 'Diese Immobilie ist nicht mehr verfügbar', $r['body'] );
		$this->assertStringContainsString( 'href="' . $this->base . '/immobilien/"', $r['body'], 'Link zur Übersicht' );
		$this->assertStringNotContainsString( 'rel="canonical"', $r['body'] );
		$this->assertStringNotContainsString( 'Altes Verkauftobjekt', $this->get( $this->url( self::ID_SOLD_OLD ) )['body'], '410 ohne Objektdaten' );
	}

	public function test_unknown_id_uses_theme_404(): void {
		$r = $this->get( $this->base . '/immobilien/' . self::ID_UNKNOWN . '/' );
		$this->assertSame( 404, $r['status'] );
		$this->assertMatchesRegularExpression( '/<body[^>]*class="[^"]*error404/', $r['body'] );
		$this->assertStringNotContainsString( 'psl-detail', $r['body'] );
	}

	public function test_no_propstack_requests_during_page_views(): void {
		$log = WP_CONTENT_DIR . '/psl-http-spy.log';
		if ( ! file_exists( WP_CONTENT_DIR . '/mu-plugins/psl-http-spy.php' ) ) {
			$this->markTestSkipped( 'mu-plugin psl-http-spy.php nicht installiert.' );
		}
		file_put_contents( $log, '' );

		$urls = [
			$this->url( self::ID_ACTIVE ),
			$this->url( self::ID_RESERVED ),
			$this->url( self::ID_SOLD_RENT ),
			$this->url( self::ID_SOLD_OLD ),
			$this->url( self::ID_REMOVED ),
			$this->base . '/immobilien/x-' . self::ID_UNKNOWN . '/',
			$this->base . '/immobilien/falsch-' . self::ID_ACTIVE . '/',
			$this->base . '/immobilie/?ps_id=' . self::ID_ACTIVE,
			$this->base . '/immobilien/',
		];
		foreach ( $urls as $url ) {
			$this->get( $url );
		}

		$lines = array_filter( explode( "\n", (string) file_get_contents( $log ) ) );
		$propstackWeb = array_filter( $lines, static fn ( $l ) => str_contains( $l, '[web' ) && str_contains( $l, 'propstack' ) );
		$this->assertSame( [], array_values( $propstackWeb ), 'Besucher-Requests dürfen Propstack nicht abfragen' );
		file_put_contents( sys_get_temp_dir() . '/psl-http-spy-result.txt', count( $urls ) . ' Seitenaufrufe, ' . count( $lines ) . ' ausgehende Requests gesamt, ' . count( $propstackWeb ) . " Propstack-Requests\n" );
	}
}
