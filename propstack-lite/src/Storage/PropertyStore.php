<?php

namespace PropstackLite\Storage;

use PropstackLite\Domain\Property;

/**
 * Zugriff auf `{prefix}psl_properties`.
 *
 * Sichtbarkeit wird hier erzwungen: Öffentliche Abfragen liefern nur Zeilen mit
 * `state = 'active'` UND `status_id` in der übergebenen Whitelist öffentlicher Status.
 * Alle Abfragen laufen über $wpdb->prepare bzw. $wpdb->insert/update.
 */
final class PropertyStore {

	/** Hauptpreis-Ausdruck: Kaufpreis bzw. Kaltmiete (Fallback Warmmiete). */
	private const PRICE_EXPR = "(CASE WHEN marketing_type = 'RENT' THEN COALESCE(base_rent, total_rent) ELSE price END)";

	private const SORT_COLUMNS = [
		'created_at'   => 'remote_created_at',
		'updated_at'   => 'remote_updated_at',
		'price'        => self::PRICE_EXPR,
		'living_space' => 'living_space',
		'rooms'        => 'rooms',
		'city'         => 'city',
	];

	public function __construct( private \wpdb $db, private string $table ) {}

	public static function create(): self {
		global $wpdb;
		return new self( $wpdb, Schema::table() );
	}

	/** @return 'inserted'|'updated'|'unchanged' */
	public function upsertActive( Property $property, string $now ): string {
		$json = (string) wp_json_encode( $property->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$hash = md5( $json );

		$existing = $this->db->get_row(
			$this->db->prepare( "SELECT state, data_hash FROM {$this->table} WHERE propstack_id = %d", $property->id ),
			ARRAY_A
		);

		$columns = $this->columnsFor( $property ) + [
			'state'        => StoredProperty::STATE_ACTIVE,
			'data'         => $json,
			'data_hash'    => $hash,
			'last_seen_at' => $now,
			'sold_at'      => null,
			'removed_at'   => null,
		];

		if ( null === $existing ) {
			$columns['propstack_id']       = $property->id;
			$columns['first_seen_at']      = $now;
			$columns['content_changed_at'] = $now;
			$this->assertWritten( $this->db->insert( $this->table, $columns ) );
			return 'inserted';
		}

		$changed = $existing['data_hash'] !== $hash || StoredProperty::STATE_ACTIVE !== $existing['state'];
		if ( $changed ) {
			$columns['content_changed_at'] = $now;
		}
		$this->assertWritten( $this->db->update( $this->table, $columns, [ 'propstack_id' => $property->id ] ) );
		return $changed ? 'updated' : 'unchanged';
	}

	/** Zuvor öffentliches Objekt ist verkauft/vermietet. `sold_at` bleibt beim ersten Zeitpunkt. */
	public function markSold( int $id, ?int $statusId, string $now ): bool {
		$status = self::statusSql( $statusId );
		$sql    = $this->db->prepare(
			"UPDATE {$this->table}
			 SET state = %s, status_id = {$status}, sold_at = COALESCE(sold_at, %s), removed_at = NULL, last_seen_at = %s
			 WHERE propstack_id = %d AND state <> %s",
			StoredProperty::STATE_SOLD,
			$now,
			$now,
			$id,
			StoredProperty::STATE_SOLD
		);
		return $this->assertWritten( $this->db->query( $sql ) ) > 0;
	}

	/** Objekt ist nicht mehr öffentlich: Daten sofort entfernen, Zeile für HTTP 410 behalten. */
	public function markRemoved( int $id, ?int $statusId, string $now ): bool {
		$status = self::statusSql( $statusId );
		$sql    = $this->db->prepare(
			"UPDATE {$this->table}
			 SET state = %s, status_id = {$status}, data = NULL, data_hash = NULL, removed_at = COALESCE(removed_at, %s), content_changed_at = %s
			 WHERE propstack_id = %d AND state <> %s",
			StoredProperty::STATE_REMOVED,
			$now,
			$now,
			$id,
			StoredProperty::STATE_REMOVED
		);
		return $this->assertWritten( $this->db->query( $sql ) ) > 0;
	}

	/** Entfernt die Daten verkaufter Objekte nach Ablauf der Anzeigefrist (Zeile bleibt für 410). */
	public function purgeSoldBefore( string $cutoff ): int {
		$sql = $this->db->prepare(
			"UPDATE {$this->table} SET data = NULL, data_hash = NULL
			 WHERE state = %s AND sold_at < %s AND data IS NOT NULL",
			StoredProperty::STATE_SOLD,
			$cutoff
		);
		return $this->assertWritten( $this->db->query( $sql ) );
	}

	public function stateOf( int $id ): ?string {
		$state = $this->db->get_var( $this->db->prepare( "SELECT state FROM {$this->table} WHERE propstack_id = %d", $id ) );
		return null === $state ? null : (string) $state;
	}

	/** @return list<int> */
	public function idsInState( string $state ): array {
		$ids = $this->db->get_col( $this->db->prepare( "SELECT propstack_id FROM {$this->table} WHERE state = %s", $state ) );
		return array_map( 'intval', $ids );
	}

	public function find( int $id ): ?StoredProperty {
		$row = $this->db->get_row(
			$this->db->prepare( "SELECT * FROM {$this->table} WHERE propstack_id = %d", $id ),
			ARRAY_A
		);
		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Öffentliche Liste. Ohne öffentliche Status gibt es keine Treffer (sichere Voreinstellung).
	 *
	 * @param list<int> $publicStatusIds
	 * @return array{items: list<Property>, total: int}
	 */
	public function queryPublic( array $publicStatusIds, ListCriteria $criteria ): array {
		$publicStatusIds = array_values( array_filter( array_map( 'intval', $publicStatusIds ), static fn ( $id ) => $id > 0 ) );
		if ( [] === $publicStatusIds ) {
			return [ 'items' => [], 'total' => 0 ];
		}

		$where  = [ 'state = %s', 'data IS NOT NULL' ];
		$params = [ StoredProperty::STATE_ACTIVE ];

		$where[] = 'status_id IN (' . implode( ',', array_fill( 0, count( $publicStatusIds ), '%d' ) ) . ')';
		$params  = array_merge( $params, $publicStatusIds );

		if ( null !== $criteria->marketingType ) {
			$where[]  = 'marketing_type = %s';
			$params[] = $criteria->marketingType;
		}
		if ( null !== $criteria->rsType ) {
			$where[]  = 'rs_type = %s';
			$params[] = $criteria->rsType;
		}
		if ( null !== $criteria->city ) {
			$where[]  = 'city = %s';
			$params[] = $criteria->city;
		}
		if ( null !== $criteria->zipCode ) {
			$where[]  = 'zip_code = %s';
			$params[] = $criteria->zipCode;
		}
		if ( null !== $criteria->priceFrom ) {
			$where[]  = self::PRICE_EXPR . ' >= %f';
			$params[] = $criteria->priceFrom;
		}
		if ( null !== $criteria->priceTo ) {
			$where[]  = self::PRICE_EXPR . ' <= %f';
			$params[] = $criteria->priceTo;
		}
		$exclude = array_values( array_filter( array_map( 'intval', $criteria->excludeIds ) ) );
		if ( [] !== $exclude ) {
			$where[] = 'propstack_id NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%d' ) ) . ')';
			$params  = array_merge( $params, $exclude );
		}

		$whereSql = implode( ' AND ', $where );
		$total    = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE {$whereSql}", $params ) );

		$sortExpr = self::SORT_COLUMNS[ $criteria->sortBy ] ?? self::SORT_COLUMNS['created_at'];
		$dir      = 'asc' === $criteria->order ? 'ASC' : 'DESC';
		$offset   = ( $criteria->page - 1 ) * $criteria->perPage;

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE {$whereSql}
				 ORDER BY ({$sortExpr} IS NULL) ASC, {$sortExpr} {$dir}, propstack_id DESC
				 LIMIT %d OFFSET %d",
				array_merge( $params, [ $criteria->perPage, $offset ] )
			),
			ARRAY_A
		);

		$items = [];
		foreach ( (array) $rows as $row ) {
			$stored = $this->hydrate( $row );
			if ( null !== $stored->property ) {
				$items[] = $stored->property;
			}
		}
		return [ 'items' => $items, 'total' => $total ];
	}

	/** @return array<string, int> Anzahl Zeilen je Zustand */
	public function countsByState(): array {
		$rows   = $this->db->get_results( "SELECT state, COUNT(*) AS n FROM {$this->table} GROUP BY state", ARRAY_A );
		$counts = [ StoredProperty::STATE_ACTIVE => 0, StoredProperty::STATE_SOLD => 0, StoredProperty::STATE_REMOVED => 0 ];
		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['state'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/** @param list<int> $publicStatusIds */
	public function countVisible( array $publicStatusIds ): int {
		return $this->queryPublic( $publicStatusIds, new ListCriteria( perPage: 1 ) )['total'];
	}

	/** Rohzeilen für `wp psl audit`. @return iterable<array{propstack_id: string, state: string, status_id: ?string, data: ?string}> */
	public function auditRows(): iterable {
		$offset = 0;
		do {
			$rows = $this->db->get_results(
				$this->db->prepare( "SELECT propstack_id, state, status_id, data FROM {$this->table} ORDER BY propstack_id LIMIT 200 OFFSET %d", $offset ),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				yield $row;
			}
			$offset += 200;
		} while ( ! empty( $rows ) );
	}

	public function truncate(): void {
		$this->db->query( "TRUNCATE TABLE {$this->table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function columnsFor( Property $p ): array {
		return [
			'slug'              => $p->slug,
			'status_id'         => $p->statusId,
			'marketing_type'    => $p->marketingType,
			'rs_type'           => $p->rsType,
			'object_type'       => $p->objectType,
			'city'              => $p->address->city,
			'zip_code'          => $p->address->zipCode,
			'district'          => $p->address->district,
			'price'             => $p->price,
			'base_rent'         => $p->baseRent,
			'total_rent'        => $p->totalRent,
			'living_space'      => $p->mainArea(),
			'plot_area'         => $p->plotArea,
			'rooms'             => $p->rooms,
			'remote_created_at' => self::toMysql( $p->createdAt ),
			'remote_updated_at' => self::toMysql( $p->updatedAt ),
		];
	}

	private function hydrate( array $row ): StoredProperty {
		$property = null;
		if ( ! empty( $row['data'] ) ) {
			$data = json_decode( (string) $row['data'], true );
			if ( is_array( $data ) ) {
				$property = Property::fromArray( $data );
			}
		}
		return new StoredProperty(
			id: (int) $row['propstack_id'],
			slug: (string) $row['slug'],
			state: (string) $row['state'],
			statusId: null === $row['status_id'] ? null : (int) $row['status_id'],
			soldAt: $row['sold_at'] ?? null,
			removedAt: $row['removed_at'] ?? null,
			contentChangedAt: $row['content_changed_at'] ?? null,
			property: $property
		);
	}

	/** Status-ID als SQL-Literal: geprüfter Integer oder NULL. */
	private static function statusSql( ?int $statusId ): string {
		return null === $statusId || $statusId <= 0 ? 'NULL' : (string) (int) $statusId;
	}

	private static function toMysql( ?string $iso ): ?string {
		if ( null === $iso ) {
			return null;
		}
		try {
			return ( new \DateTimeImmutable( $iso ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception ) {
			return null;
		}
	}

	/** @throws \RuntimeException bei Datenbankfehlern (Sync bricht dann sauber ab). */
	private function assertWritten( int|bool|null $result ): int {
		if ( false === $result ) {
			throw new \RuntimeException( 'Datenbankfehler beim Schreiben in ' . $this->table . '.' );
		}
		return (int) $result;
	}
}
