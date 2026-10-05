<?php

namespace PropstackLite\Tests\Integration;

use PropstackLite\Tests\Support\FakeTransport;

/**
 * In-Memory-Propstack für Sync-Tests: unterstützt die vom Plugin genutzten Filter
 * (status[], property_ids[], archived, updated_at_from, per/page) und Einzelabrufe.
 */
final class FakePropstack {

	/** @var array<int, array> */
	public array $units = [];

	public bool $failLists = false;
	public bool $ignorePropertyIdsFilter = false;
	public bool $emptyPropertyIdsResult = false;

	public FakeTransport $transport;

	public function __construct() {
		$this->transport = ( new FakeTransport() )->route( fn ( string $path, array $q ) => $this->handle( $path, $q ) );
	}

	public function put( array $raw ): void {
		$this->units[ (int) $raw['id'] ] = $raw;
	}

	public function delete( int $id ): void {
		unset( $this->units[ $id ] );
	}

	private function handle( string $path, array $q ): array {
		if ( preg_match( '#^units/(\d+)$#', $path, $m ) ) {
			$id = (int) $m[1];
			return isset( $this->units[ $id ] )
				? FakeTransport::json( $this->units[ $id ] )
				: FakeTransport::json( [ 'errors' => [ 'nicht gefunden' ] ], 404 );
		}
		if ( 'units' !== $path ) {
			return FakeTransport::json( [ 'errors' => [ 'unbekannt' ] ], 404 );
		}
		if ( $this->failLists ) {
			return FakeTransport::json( [], 500 );
		}

		$items = array_values( $this->units );
		ksort( $this->units );
		usort( $items, static fn ( $a, $b ) => $a['id'] <=> $b['id'] );

		$archived = $q['archived'] ?? null;
		$items    = array_filter(
			$items,
			static function ( array $u ) use ( $q, $archived ): bool {
				if ( '-1' !== $archived && ! empty( $u['archived'] ) ) {
					return false;
				}
				if ( isset( $q['status'] ) && ! in_array( (string) $u['property_status']['id'], (array) $q['status'], true ) ) {
					return false;
				}
				return true;
			}
		);
		if ( isset( $q['property_ids'] ) && ! $this->ignorePropertyIdsFilter ) {
			$items = $this->emptyPropertyIdsResult ? [] : array_filter( $items, static fn ( $u ) => in_array( (string) $u['id'], (array) $q['property_ids'], true ) );
		}
		$items = array_values( $items );

		$per  = (int) ( $q['per'] ?? 20 );
		$page = (int) ( $q['page'] ?? 1 );
		return FakeTransport::json(
			[
				'data' => array_slice( $items, ( $page - 1 ) * $per, $per ),
				'meta' => [ 'total_count' => count( $items ) ],
			]
		);
	}
}
