<?php

namespace PropstackLite\Frontend;

use PropstackLite\Mapping\FieldCatalog;
use PropstackLite\Storage\ListCriteria;
use PropstackLite\Storage\PropertySearchCriteria;

/**
 * Konfiguration einer Listen-Instanz aus den Shortcode-Attributen (ohne WordPress testbar).
 *
 * Feste Einschränkungen (marketing_type, property_type/rs_type, city, zip_code, price_from/price_to)
 * gelten immer und können von Besuchern weder aufgehoben noch überschrieben werden; das zugehörige
 * Formularfeld entfällt. Freischalten nicht-öffentlicher Objekte ist nicht möglich (kein `status`).
 */
final class ListingConfig {

	public const HEADINGS = [ 'h2', 'h3', 'h4' ];

	/** @param list<string> $fixedRsTypes */
	public function __construct(
		public readonly ?string $fixedMarketing = null,
		public readonly ?string $fixedTypeGroup = null,
		public readonly array $fixedRsTypes = [],
		public readonly ?string $fixedCity = null,
		public readonly ?string $fixedZip = null,
		public readonly ?float $fixedPriceMin = null,
		public readonly ?float $fixedPriceMax = null,
		public readonly string $defaultSort = PropertySearchCriteria::DEFAULT_SORT,
		public readonly int $perPage = PropertySearchCriteria::DEFAULT_PER_PAGE,
		public readonly int $startPage = 1,
		public readonly string $heading = 'h3',
		public readonly bool $showFilters = true,
		public readonly bool $showSort = true,
		public readonly bool $paginate = true
	) {}

	/** @param array<string, mixed> $a Shortcode-Attribute (nach shortcode_atts) */
	public static function fromAtts( array $a ): self {
		$str = static fn ( string $key ): string => is_scalar( $a[ $key ] ?? null ) ? trim( (string) $a[ $key ] ) : '';

		// Bisherige Attribute über die bewährte Validierung von ListCriteria.
		$legacy = ListCriteria::fromInput(
			[
				'marketing_type' => $str( 'marketing_type' ),
				'rs_type'        => $str( 'rs_type' ),
				'city'           => $str( 'city' ),
				'zip_code'       => $str( 'zip_code' ),
				'price_from'     => '' !== $str( 'price_from' ) ? $str( 'price_from' ) : $str( 'min_price' ),
				'price_to'       => '' !== $str( 'price_to' ) ? $str( 'price_to' ) : $str( 'max_price' ),
				'sort_by'        => $str( 'sort_by' ),
				'order'          => $str( 'order' ),
				'per_page'       => '' !== $str( 'limit' ) ? $str( 'limit' ) : ( '' !== $str( 'per' ) ? $str( 'per' ) : PropertySearchCriteria::DEFAULT_PER_PAGE ),
				'page'           => $str( 'page' ),
			]
		);
		$legacySearch = $legacy->toSearchCriteria();

		$group   = strtolower( $str( 'property_type' ) );
		$group   = isset( FieldCatalog::TYPE_GROUPS[ $group ] ) ? $group : null;
		$rsTypes = null !== $group ? FieldCatalog::TYPE_GROUPS[ $group ][1] : $legacySearch->rsTypes;

		$sort = strtolower( $str( 'sort' ) );
		$sort = isset( PropertySearchCriteria::SORTS[ $sort ] ) ? $sort : $legacySearch->sort;

		$heading = strtolower( $str( 'heading' ) );

		return new self(
			fixedMarketing: $legacy->marketingType,
			fixedTypeGroup: $group ?? ( [] !== $rsTypes ? FieldCatalog::typeGroupOf( $rsTypes[0] ) : null ),
			fixedRsTypes: $rsTypes,
			fixedCity: $legacy->city,
			fixedZip: $legacy->zipCode,
			fixedPriceMin: $legacy->priceFrom,
			fixedPriceMax: $legacy->priceTo,
			defaultSort: $sort,
			perPage: $legacy->perPage,
			startPage: $legacy->page,
			heading: in_array( $heading, self::HEADINGS, true ) ? $heading : 'h3',
			showFilters: self::flag( $a['show_filters'] ?? '1' ),
			showSort: self::flag( $a['show_sort'] ?? '1' ),
			paginate: self::flag( $a['pagination'] ?? '1' )
		);
	}

	/** Liest diese Instanz URL-Parameter (Filter, Sortierung, Seite)? Sonst statische Liste wie vor Phase 7. */
	public function isInteractive(): bool {
		return $this->showFilters || $this->showSort || $this->paginate;
	}

	/** Nur die festen Einschränkungen – Basis für die Filteroptionen. */
	public function baseCriteria(): PropertySearchCriteria {
		return new PropertySearchCriteria(
			marketingType: $this->fixedMarketing,
			rsTypes: $this->fixedRsTypes,
			city: $this->fixedCity,
			zipCode: $this->fixedZip,
			priceMin: $this->fixedPriceMin,
			priceMax: $this->fixedPriceMax
		);
	}

	public static function flag( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
		return in_array( $value, [ '1', 'yes', 'true', 'ja', 'on' ], true );
	}
}
