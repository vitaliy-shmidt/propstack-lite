<?php

namespace PropstackLite\Frontend;

use PropstackLite\Mapping\FieldCatalog;
use PropstackLite\Storage\PropertySearchCriteria;

/**
 * Validierter Zustand der Immobiliensuche aus den GET-Parametern (ohne WordPress testbar).
 *
 * - Nur Parameter aus PARAMS werden gelesen; unbekannte (utm_*, gclid, …) werden ignoriert.
 * - Jeder Wert wird gegen eine Whitelist bzw. ein festes Muster geprüft; ungültige Werte
 *   (Arrays, negative/zu große Zahlen, SQL-/HTML-Fragmente) gelten als „nicht gesetzt“.
 * - Feste Einschränkungen der Shortcode-Instanz haben Vorrang; ausgeblendete Formularteile
 *   (show_filters/show_sort/pagination = 0) werden nicht aus der URL übernommen.
 * - queryArgs() liefert die normalisierte, geordnete Parameterliste für Pagination und Canonical.
 *
 * Seitenparameter ist `seite`, nicht `page`: `page` ist eine öffentliche WordPress-Query-Variable
 * (Paginierung von Seiteninhalten) – WordPress leitet `/immobilien/?page=2` per 301 auf
 * `/immobilien/` um, und `/immobilien/2/` gehört dem Detail-Router. Doku: docs/listing.md.
 */
final class ListingRequest {

	public const P_MARKETING = 'marketing_type';
	public const P_TYPE      = 'property_type';
	public const P_CITY      = 'city';
	public const P_PRICE_MIN = 'price_min';
	public const P_PRICE_MAX = 'price_max';
	public const P_AREA_MIN  = 'living_space_min';
	public const P_PLOT_MIN  = 'plot_area_min';
	public const P_ROOMS_MIN = 'rooms_min';
	public const P_SORT      = 'sort';
	public const P_PER       = 'per';
	public const P_PAGE      = 'seite';

	/** Erlaubte Parameter in kanonischer Reihenfolge (URLs werden immer in dieser Reihenfolge gebaut). */
	public const PARAMS = [
		self::P_MARKETING,
		self::P_TYPE,
		self::P_CITY,
		self::P_PRICE_MIN,
		self::P_PRICE_MAX,
		self::P_AREA_MIN,
		self::P_PLOT_MIN,
		self::P_ROOMS_MIN,
		self::P_SORT,
		self::P_PER,
		self::P_PAGE,
	];

	public const FILTER_PARAMS = [
		self::P_MARKETING,
		self::P_TYPE,
		self::P_CITY,
		self::P_PRICE_MIN,
		self::P_PRICE_MAX,
		self::P_AREA_MIN,
		self::P_PLOT_MIN,
		self::P_ROOMS_MIN,
	];

	public const MARKETING = [ 'buy' => 'BUY', 'rent' => 'RENT' ];

	public const ROOM_OPTIONS = [ 1, 2, 3, 4, 5 ];

	public const PER_PAGE_OPTIONS = [ 12, 24, 48 ];

	/** Sortierungen im Formular (Besucher dürfen nur diese wählen). */
	public const SORT_LABELS = [
		'newest'     => 'Neueste zuerst',
		'price_asc'  => 'Preis aufsteigend',
		'price_desc' => 'Preis absteigend',
		'area_asc'   => 'Wohnfläche aufsteigend',
		'area_desc'  => 'Wohnfläche absteigend',
		'rooms_desc' => 'Zimmer absteigend',
		'updated'    => 'Zuletzt aktualisiert',
	];

	/** Nur per Shortcode wählbare Sortierungen (Bezeichnung, falls Standard der Instanz). */
	public const EXTRA_SORT_LABELS = [
		'oldest'    => 'Älteste zuerst',
		'rooms_asc' => 'Zimmer aufsteigend',
		'city_asc'  => 'Ort (A–Z)',
	];

	private const MAX_PRICE = 9_999_999_999;
	private const MAX_AREA  = 999_999;

	/** @param array<string, string> $filters validierte Besucherfilter (Parametername => normalisierter Wert) */
	private function __construct(
		public readonly ListingConfig $config,
		private array $filters,
		public readonly string $sort,
		public readonly int $perPage,
		public readonly int $page
	) {}

	/** @param array<string, mixed> $query ungeprüfte GET-Parameter (bereits wp_unslash) */
	public static function fromQuery( array $query, ListingConfig $config ): self {
		$filters = [];
		if ( $config->showFilters ) {
			$filters = self::parseFilters( $query, $config );
		}

		$sort = $config->defaultSort;
		$per  = $config->perPage;
		if ( $config->showSort ) {
			$s = self::scalar( $query[ self::P_SORT ] ?? null );
			if ( null !== $s && isset( self::SORT_LABELS[ strtolower( $s ) ] ) ) {
				$sort = strtolower( $s );
			}
			$p = self::scalar( $query[ self::P_PER ] ?? null );
			if ( null !== $p && ctype_digit( $p ) && in_array( (int) $p, self::PER_PAGE_OPTIONS, true ) ) {
				$per = (int) $p;
			}
		}

		$page = $config->paginate ? 1 : $config->startPage;
		if ( $config->paginate ) {
			$p = self::scalar( $query[ self::P_PAGE ] ?? null );
			if ( null !== $p && ctype_digit( $p ) && strlen( $p ) <= 4 && (int) $p >= 1 && (int) $p <= PropertySearchCriteria::MAX_PAGE ) {
				$page = (int) $p;
			}
		}

		return new self( $config, $filters, $sort, $per, $page );
	}

	/** @return array<string, string> */
	private static function parseFilters( array $query, ListingConfig $config ): array {
		$f = [];

		$m = strtolower( (string) self::scalar( $query[ self::P_MARKETING ] ?? null ) );
		if ( null === $config->fixedMarketing && isset( self::MARKETING[ $m ] ) ) {
			$f[ self::P_MARKETING ] = $m;
		}

		$t = strtolower( (string) self::scalar( $query[ self::P_TYPE ] ?? null ) );
		if ( [] === $config->fixedRsTypes && isset( FieldCatalog::TYPE_GROUPS[ $t ] ) ) {
			$f[ self::P_TYPE ] = $t;
		}

		$city = self::city( $query[ self::P_CITY ] ?? null );
		if ( null === $config->fixedCity && null !== $city ) {
			$f[ self::P_CITY ] = $city;
		}

		$min = self::number( $query[ self::P_PRICE_MIN ] ?? null, self::MAX_PRICE );
		$max = self::number( $query[ self::P_PRICE_MAX ] ?? null, self::MAX_PRICE );
		if ( null !== $min && null !== $max && $min > $max ) {
			[ $min, $max ] = [ $max, $min ]; // vertauschte Eingabe freundlich korrigieren
		}
		if ( null !== $min ) {
			$f[ self::P_PRICE_MIN ] = (string) $min;
		}
		if ( null !== $max ) {
			$f[ self::P_PRICE_MAX ] = (string) $max;
		}

		$area = self::number( $query[ self::P_AREA_MIN ] ?? null, self::MAX_AREA );
		if ( null !== $area ) {
			$f[ self::P_AREA_MIN ] = (string) $area;
		}
		$plot = self::number( $query[ self::P_PLOT_MIN ] ?? null, self::MAX_AREA );
		if ( null !== $plot ) {
			$f[ self::P_PLOT_MIN ] = (string) $plot;
		}

		$rooms = self::scalar( $query[ self::P_ROOMS_MIN ] ?? null );
		if ( null !== $rooms && ctype_digit( $rooms ) && in_array( (int) $rooms, self::ROOM_OPTIONS, true ) ) {
			$f[ self::P_ROOMS_MIN ] = (string) (int) $rooms;
		}

		return $f;
	}

	/** Kriterien für den Store: feste Einschränkungen + Besucherfilter + Sortierung + Seite. */
	public function criteria(): PropertySearchCriteria {
		$c       = $this->config;
		$group   = $this->filters[ self::P_TYPE ] ?? null;
		$rsTypes = null !== $group ? FieldCatalog::TYPE_GROUPS[ $group ][1] : $c->fixedRsTypes;
		$market  = $c->fixedMarketing ?? ( self::MARKETING[ $this->filters[ self::P_MARKETING ] ?? '' ] ?? null );

		// Feste Preisgrenzen (Shortcode) bleiben untere/obere Schranke, Besucher können nur enger filtern.
		$priceMin = self::tighter( $c->fixedPriceMin, $this->float( self::P_PRICE_MIN ), 'max' );
		$priceMax = self::tighter( $c->fixedPriceMax, $this->float( self::P_PRICE_MAX ), 'min' );

		return new PropertySearchCriteria(
			marketingType: $market,
			rsTypes: $rsTypes,
			city: $c->fixedCity ?? ( $this->filters[ self::P_CITY ] ?? null ),
			zipCode: $c->fixedZip,
			priceMin: $priceMin,
			priceMax: $priceMax,
			livingSpaceMin: $this->float( self::P_AREA_MIN ),
			plotAreaMin: $this->float( self::P_PLOT_MIN ),
			roomsMin: $this->float( self::P_ROOMS_MIN ),
			sort: $this->sort,
			page: $this->page,
			perPage: $this->perPage
		);
	}

	/** Validierter Wert eines Besucherfilters ('' = nicht gesetzt) – für das Formular. */
	public function value( string $param ): string {
		return $this->filters[ $param ] ?? '';
	}

	/** @return array<string, string> aktive Besucherfilter */
	public function filters(): array {
		return $this->filters;
	}

	public function hasFilters(): bool {
		return [] !== $this->filters;
	}

	/** Weicht die Ansicht von der Standardansicht ab (Filter, Sortierung oder Seitengröße)? */
	public function isCustomized(): bool {
		return $this->hasFilters() || $this->sort !== $this->config->defaultSort || $this->perPage !== $this->config->perPage;
	}

	/** Gewählte Vermarktungsart (fest oder Besucher): 'BUY', 'RENT' oder null. */
	public function marketingType(): ?string {
		return $this->config->fixedMarketing ?? ( self::MARKETING[ $this->filters[ self::P_MARKETING ] ?? '' ] ?? null );
	}

	/**
	 * Normalisierte URL-Parameter in kanonischer Reihenfolge, ohne Standardwerte.
	 * Seite 1 erscheint nie in der URL.
	 *
	 * @return array<string, string>
	 */
	public function queryArgs( ?int $page = null ): array {
		$page = $page ?? $this->page;
		$args = [];
		foreach ( self::PARAMS as $param ) {
			if ( isset( $this->filters[ $param ] ) ) {
				$args[ $param ] = $this->filters[ $param ];
			}
		}
		if ( $this->sort !== $this->config->defaultSort ) {
			$args[ self::P_SORT ] = $this->sort;
		}
		if ( $this->perPage !== $this->config->perPage ) {
			$args[ self::P_PER ] = (string) $this->perPage;
		}
		if ( $this->config->paginate && $page > 1 ) {
			$args[ self::P_PAGE ] = (string) $page;
		}
		return $args;
	}

	/** @return array<string, string> Sortierungen für das Formular (inkl. Standard der Instanz) */
	public function sortOptions(): array {
		$options = self::SORT_LABELS;
		if ( ! isset( $options[ $this->config->defaultSort ] ) ) {
			$options = [ $this->config->defaultSort => self::EXTRA_SORT_LABELS[ $this->config->defaultSort ] ?? 'Standard' ] + $options;
		}
		return $options;
	}

	/** @return list<int> Seitengrößen für das Formular (inkl. Standard der Instanz) */
	public function perPageOptions(): array {
		$options = self::PER_PAGE_OPTIONS;
		if ( ! in_array( $this->config->perPage, $options, true ) ) {
			$options[] = $this->config->perPage;
			sort( $options );
		}
		return $options;
	}

	private function float( string $param ): ?float {
		return isset( $this->filters[ $param ] ) ? (float) $this->filters[ $param ] : null;
	}

	private static function tighter( ?float $fixed, ?float $visitor, string $fn ): ?float {
		if ( null === $fixed || null === $visitor ) {
			return $fixed ?? $visitor;
		}
		return 'max' === $fn ? max( $fixed, $visitor ) : min( $fixed, $visitor );
	}

	/** Nur skalare Strings (keine Arrays wie `page[]=1`), getrimmt, max. 200 Zeichen. */
	private static function scalar( mixed $value ): ?string {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		return '' === $value || strlen( $value ) > 200 ? null : $value;
	}

	/** Ganze Zahl > 0 bis $max; nur Ziffern (keine Vorzeichen, Exponenten, Dezimaltrenner). */
	private static function number( mixed $value, int $max ): ?int {
		$value = self::scalar( $value );
		if ( null === $value || ! ctype_digit( $value ) || strlen( $value ) > 10 ) {
			return null;
		}
		$n = (int) $value;
		return $n >= 1 && $n <= $max ? $n : null;
	}

	/** Ortsname: Buchstaben, Ziffern, Leerzeichen und übliche Satzzeichen; Leerraum normalisiert. */
	private static function city( mixed $value ): ?string {
		$value = self::scalar( $value );
		if ( null === $value ) {
			return null;
		}
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		if ( '' === $value || mb_strlen( $value ) > 100 || ! preg_match( "/^[\\p{L}\\p{M}\\p{N}][\\p{L}\\p{M}\\p{N} .,'’()\\/-]*$/u", $value ) ) {
			return null;
		}
		return $value;
	}
}
