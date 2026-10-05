<?php

namespace PropstackLite\Frontend;

use PropstackLite\Domain\Property;

/**
 * Erzeugt absolute, kanonische URLs.
 *
 * Detail-URLs: /immobilien/{slug}-{id}/ – das Routing dafür folgt in Phase 2
 * (bis dahin liefern diese Links 404).
 */
final class UrlGenerator {

	public const BASE = 'immobilien';

	public function overviewUrl(): string {
		return home_url( '/' . self::BASE . '/' );
	}

	public function detailPath( Property $property ): string {
		return '/' . self::BASE . '/' . rawurlencode( $property->slug ) . '-' . $property->id . '/';
	}

	public function detailUrl( Property $property ): string {
		return home_url( $this->detailPath( $property ) );
	}
}
