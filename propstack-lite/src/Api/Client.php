<?php

namespace PropstackLite\Api;

/**
 * Einziger HTTP-Zugang zur Propstack-API (v1, nur lesend).
 *
 * - Authentifizierung ausschließlich per Header (`X-API-KEY`), nie in der URL.
 * - Retries mit Backoff bei Netzwerkfehlern, HTTP 429 und 5xx.
 * - Fehlermeldungen enthalten keine Secrets.
 *
 * Der Transport ist injizierbar, damit der Client ohne WordPress testbar ist.
 */
final class Client {

	public const BASE_URL = 'https://api.propstack.de/v1';

	/** @var callable(string, array): array{status?: int, body?: string, headers?: array, error?: string} */
	private $transport;

	/** @var callable(int): void */
	private $sleeper;

	public function __construct(
		private string $apiKey,
		?callable $transport = null,
		?callable $sleeper = null,
		private int $maxAttempts = 3,
		private int $timeout = 20
	) {
		$this->transport = $transport ?? self::wordpressTransport();
		$this->sleeper   = $sleeper ?? static function ( int $seconds ): void {
			sleep( $seconds );
		};
	}

	/**
	 * GET-Request; liefert das dekodierte JSON.
	 *
	 * @throws ApiException
	 */
	public function get( string $path, array $query = [] ): array {
		if ( '' === trim( $this->apiKey ) ) {
			throw new ApiException( 'Kein Propstack-API-Key konfiguriert.', ApiException::CONFIG );
		}

		$url = self::BASE_URL . '/' . ltrim( $path, '/' );
		$qs  = QueryString::build( $query );
		if ( '' !== $qs ) {
			$url .= '?' . $qs;
		}

		$attempt = 0;
		while ( true ) {
			++$attempt;
			try {
				return $this->decode( $this->send( $url ) );
			} catch ( ApiException $e ) {
				if ( ! $e->isRetryable() || $attempt >= $this->maxAttempts ) {
					throw $e;
				}
				( $this->sleeper )( $this->backoffSeconds( $attempt, $e ) );
			}
		}
	}

	private function send( string $url ): array {
		$response = ( $this->transport )(
			$url,
			[
				'timeout'    => $this->timeout,
				'headers'    => [
					'X-API-KEY' => $this->apiKey,
					'Accept'    => 'application/json',
				],
				'user-agent' => 'PropstackListingsLite/' . ( defined( 'PSL_VERSION' ) ? PSL_VERSION : 'dev' ),
			]
		);

		if ( isset( $response['error'] ) ) {
			throw new ApiException( 'Netzwerkfehler: ' . self::clean( (string) $response['error'] ), ApiException::NETWORK );
		}

		$status = (int) ( $response['status'] ?? 0 );
		$body   = (string) ( $response['body'] ?? '' );

		if ( $status >= 200 && $status < 300 ) {
			return [ 'status' => $status, 'body' => $body ];
		}

		$detail = self::errorDetail( $body );
		$suffix = '' !== $detail ? ' – ' . $detail : '';

		if ( 401 === $status || 403 === $status ) {
			throw new ApiException( "HTTP {$status}: Zugriff verweigert{$suffix}", ApiException::AUTH, $status );
		}
		if ( 404 === $status ) {
			throw new ApiException( "HTTP 404: Ressource nicht gefunden{$suffix}", ApiException::NOT_FOUND, $status );
		}
		if ( 429 === $status ) {
			$retry = self::retryAfter( $response['headers'] ?? [] );
			throw new ApiException( 'HTTP 429: Rate-Limit erreicht', ApiException::RATE_LIMIT, $status, $retry );
		}
		if ( $status >= 500 ) {
			throw new ApiException( "HTTP {$status}: Serverfehler bei Propstack", ApiException::SERVER, $status );
		}
		throw new ApiException( "HTTP {$status}: Ungültige Anfrage{$suffix}", ApiException::CLIENT, $status );
	}

	private function decode( array $response ): array {
		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			throw new ApiException( 'Antwort ist kein gültiges JSON.', ApiException::INVALID_RESPONSE, $response['status'] );
		}
		return $data;
	}

	private function backoffSeconds( int $attempt, ApiException $e ): int {
		$retryAfter = $e->getRetryAfter();
		if ( null !== $retryAfter ) {
			return max( 1, min( 30, $retryAfter ) );
		}
		return 2 ** ( $attempt - 1 );
	}

	/** Liest Propstacks `{"errors": [...]}` als kurze, bereinigte Meldung. */
	private static function errorDetail( string $body ): string {
		$data = json_decode( $body, true );
		if ( is_array( $data ) && isset( $data['errors'] ) ) {
			$errors = array_filter( (array) $data['errors'], 'is_scalar' );
			return self::clean( implode( '; ', array_map( 'strval', $errors ) ) );
		}
		return '';
	}

	private static function retryAfter( array $headers ): ?int {
		foreach ( $headers as $name => $value ) {
			if ( 'retry-after' === strtolower( (string) $name ) && is_numeric( $value ) ) {
				return (int) $value;
			}
		}
		return null;
	}

	private static function clean( string $text ): string {
		$text = trim( preg_replace( '/\s+/', ' ', strip_tags( $text ) ) ?? '' );
		return mb_substr( $text, 0, 200 );
	}

	/** Standard-Transport über die WordPress HTTP API. */
	public static function wordpressTransport(): callable {
		return static function ( string $url, array $args ): array {
			$response = wp_remote_get( $url, $args );
			if ( is_wp_error( $response ) ) {
				return [ 'error' => $response->get_error_message() ];
			}
			$headers = wp_remote_retrieve_headers( $response );
			return [
				'status'  => (int) wp_remote_retrieve_response_code( $response ),
				'body'    => (string) wp_remote_retrieve_body( $response ),
				'headers' => is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers,
			];
		};
	}
}
