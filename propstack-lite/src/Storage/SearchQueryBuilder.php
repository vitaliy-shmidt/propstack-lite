<?php

namespace PropstackLite\Storage;

/**
 * Baut WHERE- und ORDER-BY-Teile der öffentlichen Suche (ohne WordPress testbar).
 *
 * - Alle Werte als Platzhalter (%s/%d/%f) für $wpdb->prepare – nie als String-Konkatenation.
 * - Spalten und Richtungen ausschließlich aus PropertySearchCriteria::SORTS (feste Literale).
 * - Sichtbarkeit: `state = 'active' AND data IS NOT NULL AND status_id IN (öffentliche Status)` ist
 *   immer Teil der Bedingung; ohne öffentliche Status gibt es keine Abfrage (null).
 */
final class SearchQueryBuilder {

	/**
	 * @param list<int> $publicStatusIds
	 * @return array{0: string, 1: list<int|float|string>}|null [WHERE-SQL, Parameter] oder null (nichts öffentlich)
	 */
	public static function where( array $publicStatusIds, PropertySearchCriteria $c ): ?array {
		$publicStatusIds = array_values( array_filter( array_map( 'intval', $publicStatusIds ), static fn ( $id ) => $id > 0 ) );
		if ( [] === $publicStatusIds ) {
			return null;
		}

		$where  = [ 'state = %s', 'data IS NOT NULL', 'status_id IN (' . self::placeholders( count( $publicStatusIds ), '%d' ) . ')' ];
		$params = array_merge( [ StoredProperty::STATE_ACTIVE ], $publicStatusIds );

		if ( null !== $c->marketingType ) {
			$where[]  = 'marketing_type = %s';
			$params[] = $c->marketingType;
		}
		if ( [] !== $c->rsTypes ) {
			$where[] = 'rs_type IN (' . self::placeholders( count( $c->rsTypes ), '%s' ) . ')';
			$params  = array_merge( $params, $c->rsTypes );
		}
		if ( null !== $c->city ) {
			$where[]  = 'city = %s'; // Kollation der Tabelle: Groß-/Kleinschreibung egal
			$params[] = $c->city;
		}
		if ( null !== $c->zipCode ) {
			$where[]  = 'zip_code = %s';
			$params[] = $c->zipCode;
		}
		if ( null !== $c->priceMin ) {
			$where[]  = 'search_price >= %f';
			$params[] = $c->priceMin;
		}
		if ( null !== $c->priceMax ) {
			$where[]  = 'search_price <= %f';
			$params[] = $c->priceMax;
		}
		if ( null !== $c->livingSpaceMin ) {
			$where[]  = 'living_space >= %f';
			$params[] = $c->livingSpaceMin;
		}
		if ( null !== $c->plotAreaMin ) {
			$where[]  = 'plot_area >= %f';
			$params[] = $c->plotAreaMin;
		}
		if ( null !== $c->roomsMin ) {
			$where[]  = 'rooms >= %f';
			$params[] = $c->roomsMin;
		}
		if ( [] !== $c->excludeIds ) {
			$where[] = 'propstack_id NOT IN (' . self::placeholders( count( $c->excludeIds ), '%d' ) . ')';
			$params  = array_merge( $params, $c->excludeIds );
		}

		return [ implode( ' AND ', $where ), $params ];
	}

	/** ORDER BY ohne Schlüsselwort: NULL-Werte zuletzt, eindeutiger Tie-Breaker in Sortierrichtung. */
	public static function orderBy( PropertySearchCriteria $c ): string {
		[ $column, $dir ] = PropertySearchCriteria::SORTS[ $c->sort ] ?? PropertySearchCriteria::SORTS[ PropertySearchCriteria::DEFAULT_SORT ];
		$dir              = 'ASC' === $dir ? 'ASC' : 'DESC';
		$nullsLast        = 'content_changed_at' === $column ? '' : "{$column} IS NULL, "; // NOT NULL-Spalte
		return "{$nullsLast}{$column} {$dir}, propstack_id {$dir}";
	}

	private static function placeholders( int $count, string $placeholder ): string {
		return implode( ',', array_fill( 0, $count, $placeholder ) );
	}
}
