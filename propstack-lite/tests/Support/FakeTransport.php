<?php

namespace PropstackLite\Tests\Support;

/**
 * HTTP-Transport-Attrappe für den Api\Client.
 *
 * Antworten werden über einen Router (callable: URL-Teile → Antwort) oder eine Warteschlange
 * geliefert. Alle Requests werden protokolliert (inkl. Header) für Assertions.
 */
final class FakeTransport {

	/** @var list<array{url: string, args: array}> */
	public array $requests = [];

	/** @var list<array> */
	private array $queue = [];

	/** @var null|callable(string $path, array $query): array */
	private $router = null;

	public static function json( mixed $data, int $status = 200, array $headers = [] ): array {
		return [ 'status' => $status, 'body' => json_encode( $data ), 'headers' => $headers ];
	}

	public function queue( array ...$responses ): self {
		array_push( $this->queue, ...$responses );
		return $this;
	}

	public function route( callable $router ): self {
		$this->router = $router;
		return $this;
	}

	public function __invoke( string $url, array $args ): array {
		$this->requests[] = [ 'url' => $url, 'args' => $args ];
		if ( null !== $this->router ) {
			$parts = parse_url( $url );
			$query = self::parseQuery( $parts['query'] ?? '' );
			$path  = substr( $parts['path'] ?? '', strlen( '/v1/' ) );
			return ( $this->router )( $path, $query );
		}
		if ( [] === $this->queue ) {
			throw new \RuntimeException( 'Keine Fake-Antwort mehr für ' . $url );
		}
		return array_shift( $this->queue );
	}

	/** Parst `a=1&b[]=2&b[]=3` (Rails-Stil) in ein Array. */
	public static function parseQuery( string $query ): array {
		$out = [];
		foreach ( '' === $query ? [] : explode( '&', $query ) as $pair ) {
			[ $k, $v ] = array_pad( explode( '=', $pair, 2 ), 2, '' );
			$k = rawurldecode( $k );
			$v = rawurldecode( $v );
			if ( str_ends_with( $k, '[]' ) ) {
				$out[ substr( $k, 0, -2 ) ][] = $v;
			} else {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}
}
