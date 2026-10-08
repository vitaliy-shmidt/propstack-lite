<?php

namespace PropstackLite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PropstackLite\Admin\Diagnostics;
use PropstackLite\Api\ApiException;
use PropstackLite\Support\ErrorCode;
use PropstackLite\Support\Logger;
use PropstackLite\Sync\SyncResult;

/** Phase 8: Diagnose-Bewertung, Fehlercodes, Logger ohne PII, Versionskonsistenz. */
final class OperationsTest extends TestCase {

	private const NOW = 1_800_000_000;

	/** Gesunde Ausgangslage – einzelne Tests verändern gezielt einen Fakt. */
	private static function facts( array $override = [] ): array {
		return array_replace(
			[
				'apiKey'          => true,
				'publicStatusIds' => 1,
				'schema'          => [ 'installed' => 2, 'expected' => 2, 'table' => true, 'missingColumns' => [] ],
				'syncFailing'     => false,
				'lastErrorCode'   => '',
				'lastError'       => '',
				'lastSuccessAt'   => self::NOW - 600,
				'nextIncremental' => self::NOW + 300,
				'nextFull'        => self::NOW + 3600,
				'wpCronDisabled'  => false,
				'cf7'             => true,
				'cf7FormId'       => 98,
				'leadForm'        => 'Immobilienanfrage',
				'leadTarget'      => true,
				'tracking'        => false,
				'consentProvider' => 'none',
				'seoActive'       => [],
				'seoMode'         => 'core',
				'now'             => self::NOW,
			],
			$override
		);
	}

	/** @return list<string> */
	private static function codes( array $override = [] ): array {
		return array_column( Diagnostics::evaluate( self::facts( $override ) ), 'code' );
	}

	public function test_healthy_installation_has_no_checks(): void {
		$this->assertSame( [], Diagnostics::evaluate( self::facts() ) );
	}

	public function test_critical_configuration(): void {
		$checks = Diagnostics::evaluate( self::facts( [ 'apiKey' => false, 'publicStatusIds' => 0, 'lastSuccessAt' => null ] ) );
		$this->assertSame( [ 'api_key_missing', 'no_public_status' ], array_column( $checks, 'code' ), 'Folgefehler (sync_never) werden nicht zusätzlich gemeldet' );
		$this->assertSame( [ 'critical', 'critical' ], array_column( $checks, 'severity' ) );
		$this->assertStringContainsString( 'PSL_API_KEY', $checks[0]['message'] );
	}

	public function test_schema_states(): void {
		$this->assertSame( [ 'schema_missing' ], self::codes( [ 'schema' => [ 'installed' => 2, 'expected' => 2, 'table' => false, 'missingColumns' => [] ] ] ) );
		$this->assertSame( [ 'schema_missing' ], self::codes( [ 'schema' => [ 'installed' => 2, 'expected' => 2, 'table' => true, 'missingColumns' => [ 'search_price' ] ] ] ) );
		$this->assertSame( [ 'schema_outdated' ], self::codes( [ 'schema' => [ 'installed' => 1, 'expected' => 2, 'table' => true, 'missingColumns' => [] ] ] ) );
		$this->assertSame( [ 'schema_newer' ], self::codes( [ 'schema' => [ 'installed' => 3, 'expected' => 2, 'table' => true, 'missingColumns' => [] ] ] ) );
	}

	public function test_sync_and_cron_states(): void {
		$this->assertSame( [ 'api_auth_failed' ], self::codes( [ 'syncFailing' => true, 'lastErrorCode' => 'api_auth_failed', 'lastError' => 'full: HTTP 401' ] ) );
		$this->assertSame( [ 'sync_failed' ], self::codes( [ 'syncFailing' => true ] ), 'Altzustand ohne Code' );
		$this->assertSame( [ 'sync_never' ], self::codes( [ 'lastSuccessAt' => null ] ) );
		$this->assertSame( [], self::codes( [ 'lastSuccessAt' => self::NOW - Diagnostics::STALE_AFTER + 60 ] ) );
		$this->assertSame( [ 'sync_stale' ], self::codes( [ 'lastSuccessAt' => self::NOW - Diagnostics::STALE_AFTER - 60 ] ) );
		$this->assertSame( [ 'cron_overdue' ], self::codes( [ 'nextIncremental' => self::NOW - 7200 ] ) );
		$this->assertSame( [ 'cron_overdue' ], self::codes( [ 'nextFull' => null ] ), 'Event fehlt' );
		$this->assertSame( [], self::codes( [ 'nextIncremental' => self::NOW - 600 ] ), 'kurz überfällig ist normal (WP-Cron)' );
		$overdue = Diagnostics::evaluate( self::facts( [ 'nextFull' => self::NOW - 7200, 'wpCronDisabled' => true ] ) );
		$this->assertStringContainsString( 'System-Cron', $overdue[0]['message'] );
	}

	public function test_lead_tracking_and_seo_checks_only_when_relevant(): void {
		$this->assertSame( [], self::codes( [ 'cf7' => false, 'cf7FormId' => 0, 'leadForm' => null, 'leadTarget' => false ] ), 'Anfragen nicht genutzt → keine Warnung' );
		$this->assertSame( [ 'cf7_missing', 'lead_target_missing' ], self::codes( [ 'cf7' => false, 'leadForm' => null, 'leadTarget' => false ] ) );
		$this->assertSame( [ 'lead_form_missing' ], self::codes( [ 'leadForm' => null ] ) );
		$this->assertSame( [ 'consent_provider_missing' ], self::codes( [ 'tracking' => true ] ) );
		$this->assertSame( [], self::codes( [ 'tracking' => true, 'consentProvider' => 'cookiebot_api' ] ) );
		$this->assertSame( [ 'seo_plugin_conflict' ], self::codes( [ 'seoActive' => [ 'yoast', 'rankmath' ], 'seoMode' => 'yoast' ] ) );
	}

	public function test_every_code_has_a_german_label(): void {
		foreach ( ( new \ReflectionClass( ErrorCode::class ) )->getConstants() as $name => $value ) {
			if ( 'LABELS' === $name ) {
				continue;
			}
			$this->assertMatchesRegularExpression( '/^[a-z0-9_]+$/', $value );
			$this->assertArrayHasKey( $value, ErrorCode::LABELS, $value );
		}
	}

	public function test_api_exception_codes(): void {
		$map = [
			ApiException::CONFIG           => 'api_key_missing',
			ApiException::AUTH             => 'api_auth_failed',
			ApiException::NETWORK          => 'api_unreachable',
			ApiException::RATE_LIMIT       => 'api_rate_limited',
			ApiException::SERVER           => 'api_server_error',
			ApiException::INVALID_RESPONSE => 'api_invalid_response',
			ApiException::NOT_FOUND        => 'api_request_failed',
			ApiException::CLIENT           => 'api_request_failed',
		];
		foreach ( $map as $category => $code ) {
			$this->assertSame( $code, ErrorCode::fromApiException( new ApiException( 'x', $category ) ) );
		}
		$r       = new SyncResult( 'full', SyncResult::ERROR, 'HTTP 401' );
		$r->code = 'api_auth_failed';
		$this->assertStringContainsString( 'full-Sync error [api_auth_failed]', $r->summary() );
		$this->assertSame( 'api_auth_failed', $r->toArray()['code'] );
	}

	public function test_logger_never_writes_pii_or_tracking_ids(): void {
		$line = Logger::format(
			Logger::ERROR,
			'Mail an max.mustermann@example.org fehlgeschlagen, Seite https://picaflor.example/immobilien/?utm_source=x&gclid=Cj0abc',
			[
				'code'      => 'lead_target_missing',
				'lead'      => 'uuid-1',
				'property'  => 4711,
				'email'     => 'max@example.org',
				'phone'     => '+49 30 123',
				'name'      => 'Max',
				'message'   => 'Hallo',
				'ip'        => '203.0.113.5',
				'gclid'     => 'Cj0abc',
				'url'       => 'https://x.example/?a=1',
				'api_key'   => 'secret',
				'note'      => 'Rückruf an rueckruf@example.org',
				'nested'    => [ 'x' => 1 ],
			]
		);

		$this->assertStringStartsWith( '[propstack-lite] ERROR ', $line );
		$this->assertStringContainsString( '"code":"lead_target_missing"', $line );
		$this->assertStringContainsString( '"property":4711', $line );
		foreach ( [ 'max.mustermann', 'max@example.org', '+49 30', 'Max"', 'Hallo', '203.0.113.5', 'Cj0abc', 'utm_source', 'secret', 'rueckruf@example.org', 'nested' ] as $needle ) {
			$this->assertStringNotContainsString( $needle, $line, $needle );
		}
		$this->assertStringContainsString( 'https://picaflor.example/immobilien/', $line, 'URL ohne Query bleibt nachvollziehbar' );
	}

	public function test_logger_levels(): void {
		$this->assertTrue( Logger::enabled( Logger::ERROR ) );
		$this->assertTrue( Logger::enabled( Logger::WARNING ) );
		$this->assertSame( defined( 'WP_DEBUG' ) && WP_DEBUG, Logger::enabled( Logger::INFO ), 'info nur mit WP_DEBUG' );

		$lines  = [];
		$logger = new Logger( static function ( string $l ) use ( &$lines ): void {
			$lines[] = $l;
		} );
		$logger->error( 'a', [ 'code' => 'sync_failed' ] );
		$logger->warning( 'b' );
		$this->assertCount( 2, $lines );
	}

	/** Release ohne vendor/: Produktionscode braucht keine Composer-Pakete (eigener Autoloader). */
	public function test_no_composer_runtime_dependencies(): void {
		$root     = dirname( __DIR__, 2 );
		$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
		$runtime  = array_filter( array_keys( $composer['require'] ), static fn ( $p ) => 'php' !== $p && ! str_starts_with( $p, 'ext-' ) );
		$this->assertSame( [], array_values( $runtime ), 'require enthält nur php/ext-*' );
		$this->assertSame( [], json_decode( (string) file_get_contents( $root . '/composer.lock' ), true )['packages'], 'composer.lock ohne Produktionspakete' );

		$hits = [];
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( preg_match( '#vendor/|autoload\.php|Composer\\\\#', (string) file_get_contents( (string) $file ) ) ) {
				$hits[] = $file->getFilename();
			}
		}
		$this->assertSame( [], $hits, 'src/ referenziert weder vendor/ noch Composer' );
	}

	public function test_version_is_consistent(): void {
		$main = (string) file_get_contents( dirname( __DIR__, 2 ) . '/propstack-lite.php' );
		preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $header );
		preg_match( "/define\( 'PSL_VERSION', '([^']+)' \)/", $main, $constant );
		preg_match( '/^\s*\*\s*Requires PHP:\s*(\S+)/m', $main, $php );
		preg_match( "/define\( 'PSL_MIN_PHP', '([^']+)' \)/", $main, $minPhp );
		preg_match( '/^\s*\*\s*Requires at least:\s*(\S+)/m', $main, $wp );
		preg_match( "/define\( 'PSL_MIN_WP', '([^']+)' \)/", $main, $minWp );

		$this->assertSame( $header[1], $constant[1], 'Header-Version = PSL_VERSION' );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $constant[1] );
		$this->assertSame( $php[1], $minPhp[1] );
		$this->assertSame( $wp[1], $minWp[1] );
		$this->assertSame( '8.1', $composerPhp = ltrim( (string) json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' ), true )['require']['php'], '>=' ) );
		$this->assertSame( $minPhp[1], $composerPhp );

		$changelog = dirname( __DIR__, 3 ) . '/docs/changelog.md';
		if ( is_readable( $changelog ) ) {
			$this->assertMatchesRegularExpression( '/^## .*\(Version ' . preg_quote( $constant[1], '/' ) . '\)/m', (string) file_get_contents( $changelog ) );
		}
	}
}
