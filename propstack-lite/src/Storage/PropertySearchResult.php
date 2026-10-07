<?php

namespace PropstackLite\Storage;

use PropstackLite\Domain\Property;

/** Ergebnis einer öffentlichen Suche: Objekte der angefragten Seite plus Gesamtzahl (COUNT-Query). */
final class PropertySearchResult {

	/** @param list<Property> $items */
	public function __construct(
		public readonly array $items,
		public readonly int $total,
		public readonly PropertySearchCriteria $criteria
	) {}

	public function pages(): int {
		return max( 1, (int) ceil( $this->total / $this->criteria->perPage ) );
	}

	/** Angefragte Seite liegt hinter der letzten Seite (z. B. alter Link nach Bestandsänderung). */
	public function isOutOfRange(): bool {
		return $this->criteria->page > $this->pages();
	}
}
