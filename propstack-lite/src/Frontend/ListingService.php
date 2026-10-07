<?php

namespace PropstackLite\Frontend;

use PropstackLite\Settings;
use PropstackLite\Storage\PropertySearchCriteria;
use PropstackLite\Storage\PropertySearchResult;
use PropstackLite\Storage\PropertyStore;

/**
 * Suche und Filteroptionen für einen Request – liest nur aus dem PropertyStore (nie Propstack).
 * Ergebnisse werden pro Request gemerkt: SEO-Schicht (Head) und Shortcode (Inhalt) teilen sich
 * dieselbe COUNT-/Seitenabfrage statt sie doppelt auszuführen.
 */
final class ListingService {

	/** @var array<string, PropertySearchResult> */
	private array $results = [];
	/** @var array<string, array> */
	private array $options = [];

	public function __construct( private PropertyStore $store, private Settings $settings ) {}

	public function search( PropertySearchCriteria $criteria ): PropertySearchResult {
		return $this->results[ $criteria->cacheKey() ] ??= $this->store->search( $this->settings->publicStatusIds(), $criteria );
	}

	/** @return array{marketing: array<string, int>, rsTypes: array<string, int>, cities: array<string, int>, plotArea: bool} */
	public function options( ListingConfig $config ): array {
		$base = $config->baseCriteria();
		return $this->options[ $base->cacheKey() ] ??= $this->store->filterOptions( $this->settings->publicStatusIds(), $base );
	}

	/** @return list<int> */
	public function reservedStatusIds(): array {
		return $this->settings->reservedStatusIds();
	}
}
