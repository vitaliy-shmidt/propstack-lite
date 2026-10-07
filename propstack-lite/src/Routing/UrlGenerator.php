<?php

namespace PropstackLite\Routing;

use PropstackLite\Domain\Property;
use PropstackLite\Storage\StoredProperty;

/**
 * Einzige Stelle, an der Immobilien-URLs entstehen. Templates bauen keine URLs selbst.
 *
 * - immer absolut über home_url() → funktioniert in Unterverzeichnis-Installationen (/Picaflor/)
 * - Trailing Slash gemäß Permalink-Struktur (user_trailingslashit)
 * - ohne Pretty Permalinks Fallback auf ?psl_property={id}
 */
final class UrlGenerator {

	public const BASE = 'immobilien';

	public function usesPrettyPermalinks(): bool {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	public function overviewUrl(): string {
		return (string) apply_filters( 'psl_overview_url', home_url( user_trailingslashit( self::BASE ) ) );
	}

	public function detailUrlFor( int $id, string $slug ): string {
		if ( ! $this->usesPrettyPermalinks() ) {
			return add_query_arg( Router::QV_ID, $id, home_url( '/' ) );
		}
		$slug = '' === $slug ? '' : $slug . '-';
		return home_url( user_trailingslashit( self::BASE . '/' . $slug . $id ) );
	}

	/** Kanonische Detail-URL eines Objekts. */
	public function detailUrl( Property $property ): string {
		return $this->detailUrlFor( $property->id, $property->slug );
	}

	/** Kanonische URL einer gespeicherten Zeile (auch ohne Daten, z. B. für Legacy-Weiterleitungen). */
	public function canonicalUrl( StoredProperty $stored ): string {
		return $this->detailUrlFor( $stored->id, $stored->slug );
	}

	/**
	 * URL einer Listen-/Suchansicht: Basis-URL der Seite (ohne Query) plus validierte, normalisierte
	 * Parameter aus ListingRequest::queryArgs() in fester Reihenfolge. Tracking- und unbekannte
	 * Parameter sind dort nie enthalten. Die Seiten-URL ist der Permalink (ohne Pretty Permalinks
	 * z. B. `?page_id=4` – diese Query bleibt erhalten).
	 *
	 * @param array<string, string> $args
	 */
	public function listingUrl( string $pageUrl, array $args ): string {
		$base = explode( '#', $pageUrl, 2 )[0];
		if ( [] === $args ) {
			return $base;
		}
		return $base . ( str_contains( $base, '?' ) ? '&' : '?' ) . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/** Ziel einer Legacy-URL (/immobilie/…, ?ps_id=) – immer die aktuelle kanonische URL. */
	public function legacyTarget( StoredProperty $stored ): string {
		return $this->canonicalUrl( $stored );
	}
}
