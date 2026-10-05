<?php

namespace PropstackLite\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PropstackLite\Api\ApiException;
use PropstackLite\Api\Client;
use PropstackLite\Api\QueryString;
use PropstackLite\Tests\Support\FakeTransport as T;

final class ClientTest extends TestCase {

	private array $sleeps = [];

	private function client( T $transport, string $key = 'test-key-123' ): Client {
		return new Client( $key, $transport, function ( int $s ): void {
			$this->sleeps[] = $s;
		} );
	}

	public function test_sends_key_only_in_header_and_builds_rails_arrays(): void {
		$t = ( new T() )->queue( T::json( [ 'data' => [] ] ) );
		$this->client( $t )->get( 'units', [ 'status' => [ 1, 2 ], 'per' => 100, 'empty' => '' ] );

		$request = $t->requests[0];
		$this->assertSame( 'https://api.propstack.de/v1/units?status[]=1&status[]=2&per=100', $request['url'] );
		$this->assertSame( 'test-key-123', $request['args']['headers']['X-API-KEY'] );
		$this->assertStringNotContainsString( 'test-key-123', $request['url'] );
	}

	public function test_missing_key_is_config_error_without_request(): void {
		$t = new T();
		try {
			$this->client( $t, '  ' )->get( 'units' );
			$this->fail( 'Exception erwartet' );
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::CONFIG, $e->getCategory() );
		}
		$this->assertSame( [], $t->requests );
	}

	public function test_auth_error_is_not_retried_and_message_has_no_key(): void {
		$t = ( new T() )->queue( T::json( [ 'errors' => [ 'API-Key stimmt nicht.' ] ], 401 ) );
		try {
			$this->client( $t, 'secret-key-xyz' )->get( 'units' );
			$this->fail( 'Exception erwartet' );
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::AUTH, $e->getCategory() );
			$this->assertSame( 401, $e->getHttpStatus() );
			$this->assertStringContainsString( 'API-Key stimmt nicht.', $e->getMessage() );
			$this->assertStringNotContainsString( 'secret-key-xyz', $e->getMessage() );
		}
		$this->assertCount( 1, $t->requests );
	}

	public function test_server_errors_are_retried_with_backoff(): void {
		$t = ( new T() )->queue( T::json( [], 502 ), T::json( [], 503 ), T::json( [ 'ok' => true ] ) );
		$this->assertSame( [ 'ok' => true ], $this->client( $t )->get( 'units' ) );
		$this->assertSame( [ 1, 2 ], $this->sleeps );
	}

	public function test_rate_limit_respects_retry_after(): void {
		$t = ( new T() )->queue( T::json( [], 429, [ 'Retry-After' => '7' ] ), T::json( [ 'ok' => 1 ] ) );
		$this->client( $t )->get( 'units' );
		$this->assertSame( [ 7 ], $this->sleeps );
	}

	public function test_gives_up_after_max_attempts(): void {
		$t = ( new T() )->queue( T::json( [], 500 ), T::json( [], 500 ), T::json( [], 500 ) );
		$this->expectException( ApiException::class );
		$this->client( $t )->get( 'units' );
	}

	public function test_network_error_and_invalid_json(): void {
		$t = ( new T() )->queue( [ 'error' => 'cURL error 28' ], [ 'error' => 'x' ], [ 'error' => 'y' ] );
		try {
			$this->client( $t )->get( 'units' );
			$this->fail();
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::NETWORK, $e->getCategory() );
		}

		$t2 = ( new T() )->queue( [ 'status' => 200, 'body' => '<html>' ] );
		try {
			$this->client( $t2 )->get( 'units' );
			$this->fail();
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::INVALID_RESPONSE, $e->getCategory() );
			$this->assertFalse( $e->isRetryable() );
		}
	}

	public function test_not_found(): void {
		$t = ( new T() )->queue( T::json( [ 'errors' => [ 'nicht gefunden' ] ], 404 ) );
		try {
			$this->client( $t )->get( 'units/1' );
			$this->fail();
		} catch ( ApiException $e ) {
			$this->assertSame( ApiException::NOT_FOUND, $e->getCategory() );
		}
	}

	public function test_query_string_encoding(): void {
		$this->assertSame( 'q=Bad%20Saarow&a%26b=x%23y', QueryString::build( [ 'q' => 'Bad Saarow', 'a&b' => 'x#y', 'n' => null ] ) );
		$this->assertSame( 'flag=1', QueryString::build( [ 'flag' => true ] ) );
	}
}
