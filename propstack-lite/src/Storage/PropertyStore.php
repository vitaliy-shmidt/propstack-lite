<?php

namespace PropstackLite\Storage;

use PropstackLite\Domain\Property;

/**
 * Zugriff auf `{prefix}psl_properties`.
 *
 * Sichtbarkeit wird hier erzwungen: Öffentliche Abfragen liefern nur Zeilen mit
 * `state = 'active'` UND `status_id` in der übergebenen Whitelist öffentlicher Status
 * (WHERE/ORDER BY der Suche: SearchQueryBuilder).
 * Alle Abfragen laufen über $wpdb->prepare bzw. $wpdb->insert/update.
 */
final class PropertyStore {

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
	 * Öffentliche Suche (Phase 7): COUNT-Query für die Gesamtzahl plus eine Seite per LIMIT/OFFSET.
	 * Nur die Zeilen der angefragten Seite werden geladen und dekodiert. Ohne öffentliche Status
	 * gibt es keine Treffer (sichere Voreinstellung).
	 *
	 * @param list<int> $publicStatusIds
	 */
	public function search( array $publicStatusIds, PropertySearchCriteria $criteria ): PropertySearchResult {
		$where = SearchQueryBuilder::where( $publicStatusIds, $criteria );
		if ( null === $where ) {
			return new PropertySearchResult( [], 0, $criteria );
		}
		[ $whereSql, $params ] = $where;

		$total = (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE {$whereSql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === $total || $criteria->offset() >= $total ) {
			return new PropertySearchResult( [], $total, $criteria );
		}

		$orderBy = SearchQueryBuilder::orderBy( $criteria );
		$rows    = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} WHERE {$whereSql} ORDER BY {$orderBy} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $params, [ $criteria->perPage, $criteria->offset() ] )
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
		return new PropertySearchResult( $items, $total, $criteria );
	}

	/**
	 * Filteroptionen aus den tatsächlich öffentlichen Objekten – eine GROUP-BY-Abfrage über Spalten,
	 * kein JSON-Dekodieren. `$base` sind feste Einschränkungen (Shortcode-Attribute), keine Besucherfilter.
	 *
	 * @param list<int> $publicStatusIds
	 * @return array{marketing: array<string, int>, rsTypes: array<string, int>, cities: array<string, int>, plotArea: bool}
	 */
	public function filterOptions( array $publicStatusIds, PropertySearchCriteria $base ): array {
		$options = [ 'marketing' => [], 'rsTypes' => [], 'cities' => [], 'plotArea' => false ];
		$where   = SearchQueryBuilder::where( $publicStatusIds, $base );
		if ( null === $where ) {
			return $options;
		}
		[ $whereSql, $params ] = $where;

		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT marketing_type, rs_type, TRIM(city) AS city, COUNT(*) AS n, MAX(plot_area > 0) AS has_plot
				 FROM {$this->table} WHERE {$whereSql} GROUP BY marketing_type, rs_type, TRIM(city)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			),
			ARRAY_A
		);

		$cityLabels = [];
		foreach ( (array) $rows as $row ) {
			$n = (int) $row['n'];
			if ( in_array( $row['marketing_type'], [ 'BUY', 'RENT' ], true ) ) {
				$options['marketing'][ $row['marketing_type'] ] = ( $options['marketing'][ $row['marketing_type'] ] ?? 0 ) + $n;
			}
			if ( null !== $row['rs_type'] && '' !== $row['rs_type'] ) {
				$options['rsTypes'][ $row['rs_type'] ] = ( $options['rsTypes'][ $row['rs_type'] ] ?? 0 ) + $n;
			}
			$city = trim( (string) preg_replace( '/\s+/u', ' ', (string) $row['city'] ) );
			if ( '' !== $city ) {
				// Schreibvarianten („berlin“/„Berlin“) zusammenfassen; die erste Schreibweise gewinnt.
				$key                = mb_strtolower( $city );
				$cityLabels[ $key ] = $cityLabels[ $key ] ?? $city;
				$options['cities'][ $cityLabels[ $key ] ] = ( $options['cities'][ $cityLabels[ $key ] ] ?? 0 ) + $n;
			}
			if ( '1' === (string) $row['has_plot'] ) {
				$options['plotArea'] = true;
			}
		}
		// Alphabetisch, Umlaute wie Grundbuchstaben (unabhängig von der Server-Locale).
		$sortKey = static fn ( string $v ): string => strtr( mb_strtolower( $v ), [ 'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss' ] );
		uksort( $options['cities'], static fn ( $a, $b ) => strcmp( $sortKey( (string) $a ), $sortKey( (string) $b ) ) );
		return $options;
	}

	/**
	 * Öffentliche Liste mit den Kriterien vor Phase 7 (rückwärtskompatibel; intern über search()).
	 *
	 * @param list<int> $publicStatusIds
	 * @return array{items: list<Property>, total: int}
	 */
	public function queryPublic( array $publicStatusIds, ListCriteria $criteria ): array {
		$result = $this->search( $publicStatusIds, $criteria->toSearchCriteria() );
		return [ 'items' => $result->items, 'total' => $result->total ];
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
		return $this->search( $publicStatusIds, new PropertySearchCriteria( perPage: 1 ) )->total;
	}

	/**
	 * Indexierbare Objekte für XML-Sitemaps: aktiv, öffentlicher Status, Daten vorhanden – also genau
	 * die Objekte, deren Detailseite mit 200 und index antwortet (keine Verkauft-Phase, kein 410).
	 * lastmod = content_changed_at (UTC; ändert sich nur bei sichtbaren Inhaltsänderungen).
	 *
	 * @param list<int> $publicStatusIds
	 * @return list<array{id: int, slug: string, lastmod: string}>
	 */
	public function sitemapRows( array $publicStatusIds, int $offset, int $limit ): array {
		$where = $this->sitemapWhere( $publicStatusIds );
		if ( null === $where ) {
			return [];
		}
		[ $sql, $params ] = $where;
		$params[]         = max( 1, $limit );
		$params[]         = max( 0, $offset );
		$rows             = $this->db->get_results(
			$this->db->prepare( "SELECT propstack_id, slug, COALESCE(content_changed_at, remote_updated_at) AS lastmod FROM {$this->table} WHERE {$sql} ORDER BY propstack_id ASC LIMIT %d OFFSET %d", $params ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[] = [ 'id' => (int) $row['propstack_id'], 'slug' => (string) $row['slug'], 'lastmod' => (string) $row['lastmod'] ];
		}
		return $out;
	}

	/** @param list<int> $publicStatusIds */
	public function countSitemap( array $publicStatusIds ): int {
		$where = $this->sitemapWhere( $publicStatusIds );
		if ( null === $where ) {
			return 0;
		}
		[ $sql, $params ] = $where;
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE {$sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Neueste lastmod aller Sitemap-Objekte (UTC) oder null. @param list<int> $publicStatusIds */
	public function sitemapLastModified( array $publicStatusIds ): ?string {
		$where = $this->sitemapWhere( $publicStatusIds );
		if ( null === $where ) {
			return null;
		}
		[ $sql, $params ] = $where;
		$value            = $this->db->get_var( $this->db->prepare( "SELECT MAX(COALESCE(content_changed_at, remote_updated_at)) FROM {$this->table} WHERE {$sql}", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return null === $value || '' === $value ? null : (string) $value;
	}

	/** @return array{0: string, 1: list<int|string>}|null */
	private function sitemapWhere( array $publicStatusIds ): ?array {
		$publicStatusIds = array_values( array_filter( array_map( 'intval', $publicStatusIds ), static fn ( $id ) => $id > 0 ) );
		if ( [] === $publicStatusIds ) {
			return null;
		}
		$sql = "state = %s AND data IS NOT NULL AND slug <> '' AND status_id IN (" . implode( ',', array_fill( 0, count( $publicStatusIds ), '%d' ) ) . ')';
		return [ $sql, array_merge( [ StoredProperty::STATE_ACTIVE ], $publicStatusIds ) ];
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
			'search_price'      => $p->searchPrice(),
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
