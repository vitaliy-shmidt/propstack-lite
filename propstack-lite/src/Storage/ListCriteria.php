<?php

namespace PropstackLite\Storage;

/**
 * Filter-/Sortierkriterien für öffentliche Listen im Format vor Phase 7 (Shortcode-Attribute
 * `sort_by`/`order`, `rs_type`, `price_from`/`price_to`). Wird für die Abfrage in
 * PropertySearchCriteria übersetzt (toSearchCriteria) – es gibt nur noch einen SQL-Weg.
 *
 * Kriterien können die Ergebnismenge nur einschränken – die Sichtbarkeit (öffentliche Status)
 * wird unabhängig davon im PropertyStore erzwungen.
 */
final class ListCriteria {

	public const SORTS = [ 'created_at', 'updated_at', 'price', 'living_space', 'rooms', 'city' ];

	public const MAX_PER_PAGE = 100;

	public function __construct(
		public readonly ?string $marketingType = null,
		public readonly ?string $rsType = null,
		public readonly ?string $city = null,
		public readonly ?string $zipCode = null,
		public readonly ?float $priceFrom = null,
		public readonly ?float $priceTo = null,
		public readonly string $sortBy = 'created_at',
		public readonly string $order = 'desc',
		public readonly int $perPage = 12,
		public readonly int $page = 1,
		public readonly array $excludeIds = []
	) {}

	/**
	 * Übersetzung in die Phase-7-Kriterien. Preisfilter gelten seither für `search_price`
	 * (Kaufpreis bzw. Kaltmiete; „Preis auf Anfrage“ fällt bei aktivem Preisfilter heraus).
	 */
	public function toSearchCriteria(): PropertySearchCriteria {
		$asc  = 'asc' === $this->order;
		$sort = match ( $this->sortBy ) {
			'price'        => $asc ? 'price_asc' : 'price_desc',
			'living_space' => $asc ? 'area_asc' : 'area_desc',
			'rooms'        => $asc ? 'rooms_asc' : 'rooms_desc',
			'city'         => 'city_asc',
			'updated_at'   => 'updated',
			default        => $asc ? 'oldest' : 'newest',
		};
		return new PropertySearchCriteria(
			marketingType: $this->marketingType,
			rsTypes: null === $this->rsType ? [] : [ $this->rsType ],
			city: $this->city,
			zipCode: $this->zipCode,
			priceMin: $this->priceFrom,
			priceMax: $this->priceTo,
			sort: $sort,
			page: $this->page,
			perPage: $this->perPage,
			excludeIds: $this->excludeIds
		);
	}

	/** Erzeugt Kriterien aus ungeprüften Eingaben (z. B. Shortcode-Attributen). */
	public static function fromInput( array $in ): self {
		$text = static function ( $v, int $max ): ?string {
			$v = is_scalar( $v ) ? trim( (string) $v ) : '';
			return '' === $v ? null : mb_substr( $v, 0, $max );
		};
		$num = static fn ( $v ) => is_numeric( $v ) && (float) $v >= 0 ? (float) $v : null;

		$marketing = strtoupper( (string) ( $in['marketing_type'] ?? '' ) );
		$rsType    = strtoupper( (string) ( $in['rs_type'] ?? '' ) );
		$sort      = strtolower( (string) ( $in['sort_by'] ?? '' ) );
		$order     = strtolower( (string) ( $in['order'] ?? '' ) );

		return new self(
			marketingType: in_array( $marketing, [ 'BUY', 'RENT' ], true ) ? $marketing : null,
			rsType: preg_match( '/^[A-Z_]{2,40}$/', $rsType ) ? $rsType : null,
			city: $text( $in['city'] ?? null, 100 ),
			zipCode: $text( $in['zip_code'] ?? null, 20 ),
			priceFrom: $num( $in['price_from'] ?? null ),
			priceTo: $num( $in['price_to'] ?? null ),
			sortBy: in_array( $sort, self::SORTS, true ) ? $sort : 'created_at',
			order: 'asc' === $order ? 'asc' : 'desc',
			perPage: max( 1, min( self::MAX_PER_PAGE, (int) ( $in['per_page'] ?? 12 ) ) ),
			page: max( 1, (int) ( $in['page'] ?? 1 ) )
		);
	}
}
