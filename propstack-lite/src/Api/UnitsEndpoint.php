<?php

namespace PropstackLite\Api;

/**
 * Zugriff auf `/v1/units` und `/v1/property_statuses`.
 *
 * Pagination laut Propstack: `per` + `page`, Metadaten über `with_meta=1` (nur `total_count`).
 * Es wird immer stabil nach `id` sortiert – ohne Sortierung liefert Propstack über Seiten
 * hinweg Duplikate und lässt Objekte aus (im Audit nachgewiesen).
 */
final class UnitsEndpoint {

	public const PER_PAGE  = 100;
	public const MAX_PAGES = 200;

	public function __construct( private Client $client ) {}

	/**
	 * Lädt alle Seiten einer Objektliste im erweiterten Format (`expand=1`).
	 *
	 * @param array $filters Propstack-Filter, z. B. `['status' => [1, 2]]`.
	 * @return array{items: list<array>, total: ?int, complete: bool}
	 * @throws ApiException
	 */
	public function listAll( array $filters, int $per = self::PER_PAGE ): array {
		$per   = max( 1, min( 500, $per ) );
		$items = [];
		$seen  = [];
		$total = null;
		$page  = 1;

		while ( $page <= self::MAX_PAGES ) {
			$response = $this->client->get(
				'units',
				$filters + [
					'with_meta' => 1,
					'expand'    => 1,
					'per'       => $per,
					'page'      => $page,
					'sort_by'   => 'id',
					'order'     => 'asc',
				]
			);

			$data = $response['data'] ?? null;
			if ( ! is_array( $data ) ) {
				throw new ApiException( 'Unerwartetes Format der Objektliste (kein data-Array).', ApiException::INVALID_RESPONSE );
			}
			if ( isset( $response['meta']['total_count'] ) && is_numeric( $response['meta']['total_count'] ) ) {
				$total = (int) $response['meta']['total_count'];
			}

			foreach ( $data as $row ) {
				if ( ! is_array( $row ) || empty( $row['id'] ) || ! is_numeric( $row['id'] ) ) {
					continue;
				}
				$id = (int) $row['id'];
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$items[]     = $row;
			}

			if ( [] === $data ) {
				break;
			}
			if ( null !== $total && count( $seen ) >= $total ) {
				break;
			}
			if ( null === $total && count( $data ) < $per ) {
				break;
			}
			++$page;
		}

		return [
			'items'    => $items,
			'total'    => $total,
			'complete' => null === $total ? $page <= self::MAX_PAGES : count( $seen ) >= $total,
		];
	}

	/**
	 * Einzelobjekt im erweiterten Format (`new=1`). `null`, wenn es nicht (mehr) existiert.
	 *
	 * @throws ApiException
	 */
	public function get( int $id ): ?array {
		try {
			$data = $this->client->get( 'units/' . $id, [ 'new' => 1 ] );
		} catch ( ApiException $e ) {
			if ( ApiException::NOT_FOUND === $e->getCategory() ) {
				return null;
			}
			throw $e;
		}
		if ( empty( $data['id'] ) ) {
			throw new ApiException( 'Unerwartetes Format des Einzelobjekts.', ApiException::INVALID_RESPONSE );
		}
		return $data;
	}

	/**
	 * @return list<array{id: int, name: string}> sortiert nach Propstack-Position
	 * @throws ApiException
	 */
	public function statuses(): array {
		$data = $this->client->get( 'property_statuses' );
		$rows = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;

		$statuses = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$statuses[] = [
				'id'       => (int) $row['id'],
				'name'     => trim( (string) ( $row['name'] ?? '' ) ),
				'position' => (int) ( $row['position'] ?? 0 ),
			];
		}
		usort( $statuses, static fn ( $a, $b ) => $a['position'] <=> $b['position'] );

		return array_map( static fn ( $s ) => [ 'id' => $s['id'], 'name' => $s['name'] ], $statuses );
	}
}
