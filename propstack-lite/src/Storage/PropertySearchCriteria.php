<?php

namespace PropstackLite\Storage;

/**
 * Validierte Suchkriterien für öffentliche Listen: Filter, Sortierung, Pagination (Phase 7).
 *
 * Werte kommen nie roh aus der URL – `Frontend\ListingRequest` bzw. `ListCriteria` prüfen sie vorher.
 * Der Konstruktor erzwingt zusätzlich Wertebereiche (Defense in Depth). Kriterien können die
 * Ergebnismenge nur einschränken; die Sichtbarkeit (öffentliche Status) erzwingt der PropertyStore.
 * Läuft ohne WordPress (Unit-Tests).
 */
final class PropertySearchCriteria {

	public const DEFAULT_SORT     = 'newest';
	public const DEFAULT_PER_PAGE = 12;
	public const MAX_PER_PAGE     = 100;
	public const MAX_PAGE         = 1000;

	/**
	 * Sortier-Whitelist: Schlüssel => [Spalte, Richtung]. Spalten sind feste Literale – nie aus der URL.
	 * Jede Sortierung endet mit dem eindeutigen Tie-Breaker `propstack_id` (stabile Pagination);
	 * NULL-Werte (z. B. Preis auf Anfrage) stehen immer am Ende.
	 */
	public const SORTS = [
		'newest'     => [ 'remote_created_at', 'DESC' ],
		'oldest'     => [ 'remote_created_at', 'ASC' ],
		'updated'    => [ 'content_changed_at', 'DESC' ],
		'price_asc'  => [ 'search_price', 'ASC' ],
		'price_desc' => [ 'search_price', 'DESC' ],
		'area_asc'   => [ 'living_space', 'ASC' ],
		'area_desc'  => [ 'living_space', 'DESC' ],
		'rooms_asc'  => [ 'rooms', 'ASC' ],
		'rooms_desc' => [ 'rooms', 'DESC' ],
		'city_asc'   => [ 'city', 'ASC' ],
	];

	public readonly ?string $marketingType;
	/** @var list<string> */
	public readonly array $rsTypes;
	public readonly ?string $city;
	public readonly ?string $zipCode;
	public readonly ?float $priceMin;
	public readonly ?float $priceMax;
	public readonly ?float $livingSpaceMin;
	public readonly ?float $plotAreaMin;
	public readonly ?float $roomsMin;
	public readonly string $sort;
	public readonly int $page;
	public readonly int $perPage;
	/** @var list<int> */
	public readonly array $excludeIds;

	/**
	 * @param list<string> $rsTypes
	 * @param list<int>    $excludeIds
	 */
	public function __construct(
		?string $marketingType = null,
		array $rsTypes = [],
		?string $city = null,
		?string $zipCode = null,
		?float $priceMin = null,
		?float $priceMax = null,
		?float $livingSpaceMin = null,
		?float $plotAreaMin = null,
		?float $roomsMin = null,
		string $sort = self::DEFAULT_SORT,
		int $page = 1,
		int $perPage = self::DEFAULT_PER_PAGE,
		array $excludeIds = []
	) {
		$this->marketingType  = in_array( $marketingType, [ 'BUY', 'RENT' ], true ) ? $marketingType : null;
		$this->rsTypes        = array_values( array_unique( array_filter( $rsTypes, static fn ( $t ) => is_string( $t ) && 1 === preg_match( '/^[A-Z_]{2,40}$/', $t ) ) ) );
		$this->city           = self::text( $city, 100 );
		$this->zipCode        = self::text( $zipCode, 20 );
		$this->priceMin       = self::positive( $priceMin );
		$this->priceMax       = self::positive( $priceMax );
		$this->livingSpaceMin = self::positive( $livingSpaceMin );
		$this->plotAreaMin    = self::positive( $plotAreaMin );
		$this->roomsMin       = self::positive( $roomsMin );
		$this->sort           = isset( self::SORTS[ $sort ] ) ? $sort : self::DEFAULT_SORT;
		$this->page           = max( 1, min( self::MAX_PAGE, $page ) );
		$this->perPage        = max( 1, min( self::MAX_PER_PAGE, $perPage ) );
		$this->excludeIds     = array_values( array_filter( array_map( 'intval', $excludeIds ), static fn ( $id ) => $id > 0 ) );
	}

	public function offset(): int {
		return ( $this->page - 1 ) * $this->perPage;
	}

	public function withPage( int $page ): self {
		return new self(
			$this->marketingType,
			$this->rsTypes,
			$this->city,
			$this->zipCode,
			$this->priceMin,
			$this->priceMax,
			$this->livingSpaceMin,
			$this->plotAreaMin,
			$this->roomsMin,
			$this->sort,
			$page,
			$this->perPage,
			$this->excludeIds
		);
	}

	/** Schlüssel für Request-Caches (gleiche Kriterien → gleiche Abfrage). */
	public function cacheKey(): string {
		return md5( serialize( get_object_vars( $this ) ) );
	}

	private static function text( ?string $value, int $max ): ?string {
		if ( null === $value ) {
			return null;
		}
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		return '' === $value ? null : mb_substr( $value, 0, $max );
	}

	/** Nur endliche Werte > 0 (0/negativ/NaN = „kein Filter“); obere Schranke gegen Überläufe. */
	private static function positive( ?float $value ): ?float {
		if ( null === $value || ! is_finite( $value ) || $value <= 0 || $value > 1e12 ) {
			return null;
		}
		return $value;
	}
}
