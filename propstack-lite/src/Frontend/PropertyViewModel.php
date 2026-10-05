<?php

namespace PropstackLite\Frontend;

use PropstackLite\Domain\Image;
use PropstackLite\Domain\Property;
use PropstackLite\Routing\RouteDecision;

/**
 * Bereitet die Daten der Detailseite für Templates auf (präsentationsfertig, unescaped).
 *
 * - Quelle ist ausschließlich das gewhitelistete Property-Modell – keine Rohdaten.
 * - Leere, null- oder 0-Werte (wo 0 „nicht angegeben“ bedeutet) erscheinen nicht.
 * - Gruppen/Listen ohne Inhalt sind leere Arrays → Templates rendern dann keine Sektion.
 * - Bei verborgener Adresse enthält das ViewModel weder Straße noch Hausnummer noch Koordinaten.
 * Escaping erfolgt im Template; Rich Text wird dort mit wp_kses_post( wpautop() ) ausgegeben.
 */
final class PropertyViewModel {

	/** Näherungsbreiten der Propstack-Bildvarianten (Begrenzungsrahmen, siehe docs/frontend.md). */
	private const WIDTH_MEDIUM = 600;
	private const WIDTH_BIG    = 1920;
	private const WIDTH_THUMB  = 280;
	private const WIDTH_SQUARE = 750;

	/** @return array<string, mixed> */
	public static function build( Property $p, RouteDecision $decision, string $canonicalUrl, string $overviewUrl ): array {
		$title    = Formatter::title( $p );
		$gallery  = self::images( $p->images, $title, 'Bild' );
		$plans    = self::images( $p->floorplans, $title, 'Grundriss' );
		$price    = Formatter::displayPrice( $p );
		$agent    = self::agent( $p );
		$location = $p->address->publicLabel();

		$view = [
			'id'            => $p->id,
			'state'         => $decision->state,
			'title'         => $title,
			'type'          => Formatter::typeLabel( $p->rsType ),
			'marketing'     => Formatter::marketingLabel( $p->marketingType ),
			'location'      => $location,
			'city'          => $p->address->city,
			'district'      => $p->address->district ?? $p->address->sublocality,
			'statusBadge'   => self::statusBadge( $p, $decision ),
			'breadcrumb'    => self::breadcrumb( $p, $title, $overviewUrl ),
			'displayPrice'  => $price,
			'keyFacts'      => self::keyFacts( $p ),
			'gallery'       => $gallery,
			'mainImage'     => $gallery[0] ?? null,
			'floorplans'    => $plans,
			'factGroups'    => self::factGroups( $p, $price ),
			'features'      => self::features( $p ),
			'texts'         => [
				'description' => $p->texts['description'] ?? null,
				'furnishing'  => $p->texts['furnishing'] ?? null,
				'location'    => $p->texts['location'] ?? null,
				'other'       => $p->texts['other'] ?? null,
			],
			'energy'        => self::energy( $p ),
			'commission'    => self::commission( $p ),
			'agent'         => $agent,
			'contact'       => [
				'allowed' => $decision->allowsContact(),
				'anchor'  => 'psl-contact',
				'heading' => 'Interesse an dieser Immobilie?',
			],
			'canonicalUrl'  => $canonicalUrl,
			'overviewUrl'   => $overviewUrl,
			'allowContact'  => $decision->allowsContact(),
			'isSold'        => RouteDecision::SOLD === $decision->state,
		];

		$view['hasLocationSection'] = null !== $view['texts']['location'] || '' !== $location;
		$view['hasEquipmentSection'] = [] !== $view['features'] || null !== $view['texts']['furnishing'];
		$view['hasOtherSection']     = null !== $view['texts']['other'] || [] !== $view['commission'];

		return $view;
	}

	/** @return array{label: string, modifier: string}|null */
	public static function statusBadge( Property $p, RouteDecision $decision ): ?array {
		$badge = match ( $decision->state ) {
			RouteDecision::SOLD     => [ 'label' => $p->isRent() ? 'Vermietet' : 'Verkauft', 'modifier' => 'sold' ],
			RouteDecision::RESERVED => [ 'label' => 'Reserviert', 'modifier' => 'reserved' ],
			default                 => null,
		};
		if ( null === $badge ) {
			return null;
		}
		$label = function_exists( 'apply_filters' )
			? (string) apply_filters( 'psl_property_status_label', $badge['label'], $decision->state, $p )
			: $badge['label'];
		return [ 'label' => $label, 'modifier' => $badge['modifier'] ];
	}

	/* ---------------------------------------------------------------- Bereiche */

	/** @return list<array{label: string, url: ?string}> */
	private static function breadcrumb( Property $p, string $title, string $overviewUrl ): array {
		$items = [ [ 'label' => 'Immobilien', 'url' => $overviewUrl ] ];
		if ( null !== $p->address->city ) {
			$items[] = [ 'label' => $p->address->city, 'url' => null ];
		}
		$items[] = [ 'label' => $title, 'url' => null ];
		return $items;
	}

	/** Kurzfakten im Hero (Zimmer, Fläche, Grundstück). @return list<array{label: string, value: string}> */
	private static function keyFacts( Property $p ): array {
		$area  = $p->mainArea();
		$facts = [];
		if ( null !== $p->rooms ) {
			$facts[] = [ 'label' => 'Zimmer', 'value' => Formatter::decimal( $p->rooms, 1 ) ];
		}
		if ( null !== $area ) {
			$facts[] = [ 'label' => null !== $p->livingSpace ? 'Wohnfläche' : 'Fläche', 'value' => (string) Formatter::area( $area ) ];
		}
		if ( null !== $p->plotArea ) {
			$facts[] = [ 'label' => 'Grundstück', 'value' => (string) Formatter::area( $p->plotArea ) ];
		}
		return $facts;
	}

	/** @return list<array{id: string, title: string, items: list<array{label: string, value: string}>}> */
	private static function factGroups( Property $p, array $price ): array {
		$f      = $p->facts;
		$num    = static fn ( string $k ): ?float => isset( $f[ $k ] ) && is_numeric( $f[ $k ] ) ? (float) $f[ $k ] : null;
		$groups = [];

		// Preise & Kosten
		$costs = [ [ 'label' => $price['label'], 'value' => $price['value'] ] ];
		if ( $p->isRent() ) {
			if ( 'Kaltmiete' === $price['label'] && null !== $p->totalRent ) {
				$costs[] = [ 'label' => 'Warmmiete', 'value' => Formatter::monthly( $p->totalRent ) ];
			}
			$service = Formatter::monthly( $num( 'service_charge' ) );
			if ( null !== $service && true === ( $f['heating_costs_in_service_charge'] ?? null ) ) {
				$service .= ' (inkl. Heizkosten)';
			}
			$costs[] = [ 'label' => Formatter::label( 'service_charge' ), 'value' => $service ];
			$costs[] = [ 'label' => Formatter::label( 'heating_costs' ), 'value' => Formatter::monthly( $num( 'heating_costs' ) ) ];
			$costs[] = [ 'label' => Formatter::label( 'deposit' ), 'value' => Formatter::text( $f['deposit'] ?? null ) ];
			$costs[] = [ 'label' => Formatter::label( 'parking_space_price' ), 'value' => Formatter::monthly( $num( 'parking_space_price' ) ) ];
		} else {
			if ( ! $price['onRequest'] ) {
				$costs[] = [ 'label' => Formatter::label( 'price_per_sqm' ), 'value' => Formatter::perSquareMeter( $num( 'price_per_sqm' ) ) ];
			}
			$costs[] = [ 'label' => Formatter::label( 'rent_subsidy' ), 'value' => Formatter::monthly( $num( 'rent_subsidy' ) ) ];
			$costs[] = [ 'label' => Formatter::label( 'maintenance_reserve' ), 'value' => Formatter::monthly( $num( 'maintenance_reserve' ) ) ];
			$costs[] = [ 'label' => 'Stellplatz', 'value' => Formatter::money( $num( 'parking_space_price' ) ) ];
		}
		$costs[] = [ 'label' => Formatter::label( 'courtage' ), 'value' => Formatter::text( $f['courtage'] ?? null ) ];
		$groups[] = [ 'id' => 'costs', 'title' => 'Preise & Kosten', 'items' => $costs ];

		// Flächen & Räume
		$rooms   = [];
		$rooms[] = [ 'label' => 'Wohnfläche', 'value' => Formatter::area( $p->livingSpace ) ];
		if ( null === $p->livingSpace ) {
			$rooms[] = [ 'label' => Formatter::label( 'property_space_value' ), 'value' => Formatter::area( $num( 'property_space_value' ) ) ];
		}
		foreach ( [ 'usable_floor_space', 'total_floor_space' ] as $k ) {
			$rooms[] = [ 'label' => Formatter::label( $k ), 'value' => Formatter::area( $num( $k ) ) ];
		}
		$rooms[] = [ 'label' => 'Grundstücksfläche', 'value' => Formatter::area( $p->plotArea ) ];
		foreach ( [ 'balcony_space', 'garden_space' ] as $k ) {
			$rooms[] = [ 'label' => Formatter::label( $k ), 'value' => Formatter::area( $num( $k ) ) ];
		}
		$rooms[] = [ 'label' => 'Zimmer', 'value' => null === $p->rooms ? null : Formatter::decimal( $p->rooms, 1 ) ];
		$rooms[] = [ 'label' => 'Schlafzimmer', 'value' => null === $p->bedrooms ? null : (string) $p->bedrooms ];
		$rooms[] = [ 'label' => 'Badezimmer', 'value' => null === $p->bathrooms ? null : (string) $p->bathrooms ];
		$rooms[] = [ 'label' => Formatter::label( 'floor' ), 'value' => Formatter::floor( isset( $f['floor'] ) && is_int( $f['floor'] ) ? $f['floor'] : null ) ];
		$rooms[] = [ 'label' => Formatter::label( 'number_of_floors' ), 'value' => isset( $f['number_of_floors'] ) ? (string) $f['number_of_floors'] : null ];
		$rooms[] = [ 'label' => Formatter::label( 'number_of_parking_spaces' ), 'value' => isset( $f['number_of_parking_spaces'] ) ? (string) $f['number_of_parking_spaces'] : null ];
		$groups[] = [ 'id' => 'areas', 'title' => 'Flächen & Räume', 'items' => $rooms ];

		// Objekt & Zustand
		$building   = [];
		$building[] = [ 'label' => 'Objektkategorie', 'value' => null === $p->rsCategory ? null : Formatter::enumLabel( 'rs_category', $p->rsCategory ) ];
		foreach ( [ 'apartment_type', 'building_type' ] as $k ) {
			$building[] = [ 'label' => Formatter::label( $k ), 'value' => Formatter::text( $f[ $k ] ?? null ) ];
		}
		foreach ( [ 'construction_year', 'last_refurbishment' ] as $k ) {
			$building[] = [ 'label' => Formatter::label( $k ), 'value' => isset( $f[ $k ] ) ? (string) $f[ $k ] : null ];
		}
		foreach ( [ 'condition', 'interior_quality' ] as $k ) {
			$building[] = [ 'label' => Formatter::label( $k ), 'value' => Formatter::text( $f[ $k ] ?? null ) ];
		}
		$building[] = [ 'label' => Formatter::label( 'free_from' ), 'value' => Formatter::date( $f['free_from'] ?? null ) ];
		$building[] = [ 'label' => Formatter::label( 'rented' ), 'value' => true === ( $f['rented'] ?? null ) ? 'Ja' : null ];
		$groups[]   = [ 'id' => 'building', 'title' => 'Objekt & Zustand', 'items' => $building ];

		// Leere Werte entfernen, leere Gruppen verwerfen.
		$result = [];
		foreach ( $groups as $group ) {
			$group['items'] = array_values( array_filter( $group['items'], static fn ( $i ) => null !== $i['value'] && '' !== $i['value'] ) );
			if ( [] !== $group['items'] ) {
				$result[] = $group;
			}
		}
		return $result;
	}

	/** Positive Ausstattungsmerkmale als Bezeichnungen. @return list<string> */
	private static function features( Property $p ): array {
		$labels = [];
		foreach ( $p->features as $key ) {
			if ( isset( \PropstackLite\Mapping\FieldCatalog::FEATURE_LABELS[ $key ] ) ) {
				$labels[] = \PropstackLite\Mapping\FieldCatalog::FEATURE_LABELS[ $key ];
			}
		}
		$f       = $p->facts;
		$parking = Formatter::text( $f['parking_space_types'] ?? ( $f['parking_space_type'] ?? null ) );
		if ( null !== $parking ) {
			$labels[] = 'Stellplatz: ' . $parking;
		}
		$pets = strtolower( (string) ( Formatter::text( $f['pets_allowed'] ?? null ) ?? '' ) );
		if ( 'ja' === $pets ) {
			$labels[] = 'Haustiere erlaubt';
		} elseif ( str_contains( $pets, 'vereinbar' ) ) {
			$labels[] = 'Haustiere nach Vereinbarung';
		}
		$bath = Formatter::text( $f['bathroom'] ?? null );
		if ( null !== $bath ) {
			$labels[] = 'Bad: ' . $bath;
		}
		$floors = Formatter::text( $f['flooring_type'] ?? null );
		if ( null !== $floors ) {
			$labels[] = 'Böden: ' . $floors;
		}
		return $labels;
	}

	/** @return list<array{label: string, value: string}> */
	private static function energy( Property $p ): array {
		$e     = $p->energy;
		$f     = $p->facts;
		$type  = Formatter::text( $e['building_energy_rating_type'] ?? null );
		$value = $e['energy_efficiency_value'] ?? ( $e['thermal_characteristic'] ?? null );
		$valueLabel = match ( true ) {
			null !== $type && false !== mb_stripos( $type, 'bedarf' )    => 'Endenergiebedarf',
			null !== $type && false !== mb_stripos( $type, 'verbrauch' ) => 'Endenergieverbrauch',
			default                                                    => 'Energiekennwert',
		};
		$items = [
			[ 'label' => Formatter::label( 'energy_certificate_availability' ), 'value' => Formatter::text( $e['energy_certificate_availability'] ?? null ) ],
			[ 'label' => Formatter::label( 'building_energy_rating_type' ), 'value' => $type ],
			[ 'label' => $valueLabel, 'value' => is_numeric( $value ) ? Formatter::energyValue( (float) $value ) : null ],
			[ 'label' => Formatter::label( 'energy_efficiency_class' ), 'value' => isset( $e['energy_efficiency_class'] ) ? Formatter::enumLabel( 'energy_efficiency_class', $e['energy_efficiency_class'] ) : null ],
			[ 'label' => Formatter::label( 'firing_types' ), 'value' => Formatter::text( $f['firing_types'] ?? null ) ],
			[ 'label' => Formatter::label( 'heating_type' ), 'value' => Formatter::text( $f['heating_type'] ?? null ) ],
			[ 'label' => Formatter::label( 'equipment_technology_construction_year' ), 'value' => isset( $e['equipment_technology_construction_year'] ) ? (string) $e['equipment_technology_construction_year'] : null ],
			[ 'label' => Formatter::label( 'energy_certificate_start_date' ), 'value' => Formatter::date( $e['energy_certificate_start_date'] ?? null ) ],
			[ 'label' => Formatter::label( 'energy_certificate_end_date' ), 'value' => Formatter::date( $e['energy_certificate_end_date'] ?? null ) ],
			[ 'label' => Formatter::label( 'energy_certificate_creation_date' ), 'value' => Formatter::text( $e['energy_certificate_creation_date'] ?? null ) ],
			[ 'label' => Formatter::label( 'energy_consumption_contains_warm_water' ), 'value' => Formatter::yesNo( isset( $e['energy_consumption_contains_warm_water'] ) ? (bool) $e['energy_consumption_contains_warm_water'] : null ) ],
		];
		return array_values( array_filter( $items, static fn ( $i ) => null !== $i['value'] && '' !== $i['value'] ) );
	}

	/** Provisionshinweis (die Provisionshöhe steht bereits unter „Preise & Kosten“). @return array{note?: string} */
	private static function commission( Property $p ): array {
		$note = Formatter::text( $p->facts['courtage_note'] ?? null );
		return null === $note ? [] : [ 'note' => $note ];
	}

	/**
	 * Öffentliche Bilder für Galerie/Lightbox.
	 *
	 * @param list<Image> $images bereits gefiltert (keine privaten/ausgeschlossenen Bilder)
	 * @return list<array<string, mixed>>
	 */
	private static function images( array $images, string $title, string $kind ): array {
		$out    = [];
		$images = array_values( $images );
		foreach ( $images as $image ) {
			// Defense in Depth: nur HTTPS-URLs (der Mapper erlaubt ohnehin nur https://*.propstack.de).
			$medium = self::https( $image->medium );
			$big    = self::https( $image->big );
			$orig   = self::https( $image->url );
			$thumb  = self::https( $image->thumb );
			$square = self::https( $image->square );

			$display = $medium ?? $big ?? $orig;
			$full    = $big ?? $orig ?? $medium;
			if ( null === $display || null === $full ) {
				continue;
			}
			$n       = count( $out ) + 1;
			$caption = $image->title;
			$sizes   = self::srcset( [ $medium => self::WIDTH_MEDIUM, $big => self::WIDTH_BIG ] );
			$out[]   = [
				'src'         => $display,
				'srcset'      => $sizes,
				'full'        => $full,
				'fullSrcset'  => $sizes,
				'thumb'       => $thumb ?? $square ?? $display,
				'thumbSrcset' => self::srcset( [ $thumb => self::WIDTH_THUMB, $square => self::WIDTH_SQUARE ] ),
				'thumbSize'   => null !== $thumb ? self::WIDTH_THUMB : null,
				'alt'         => $caption ?? '',
				'caption'     => $caption,
			];
		}
		// Alt-Text-Fallback mit Kontext, sobald die Anzahl feststeht.
		$count = count( $out );
		foreach ( $out as $i => $item ) {
			if ( '' === $item['alt'] ) {
				$out[ $i ]['alt'] = sprintf( '%s – %s %d von %d', $title, $kind, $i + 1, $count );
			}
		}
		return $out;
	}

	private static function https( ?string $url ): ?string {
		return null !== $url && str_starts_with( $url, 'https://' ) ? $url : null;
	}

	/** @param array<string|int, int> $candidates URL => Breite (null-URLs werden ignoriert) */
	private static function srcset( array $candidates ): string {
		$parts = [];
		foreach ( $candidates as $url => $width ) {
			if ( is_string( $url ) && '' !== $url ) {
				$parts[ $url ] = $url . ' ' . $width . 'w';
			}
		}
		return implode( ', ', $parts );
	}

	/** Öffentliche Makler-Daten; ohne öffentlichen Makler optionaler Fallback über Filter. */
	private static function agent( Property $p ): ?array {
		$agent = $p->agent;
		if ( null !== $agent ) {
			$name = $agent->displayName();
			if ( null !== $name && null !== $agent->academicTitle && false === mb_stripos( $name, $agent->academicTitle ) ) {
				$name = $agent->academicTitle . ' ' . $name;
			}
			$data = [
				'name'      => $name,
				'position'  => $agent->position,
				'avatarUrl' => $agent->avatarUrl,
				'email'     => $agent->email,
				'phone'     => $agent->phone,
				'cell'      => $agent->cell,
				'isFallback'=> false,
			];
			if ( null !== $data['name'] || $agent->hasContactChannel() ) {
				return $data;
			}
		}
		// Geplant: zentraler Picaflor-Kontakt aus den Einstellungen. Bis dahin per Filter möglich.
		$fallback = function_exists( 'apply_filters' ) ? apply_filters( 'psl_property_fallback_agent', null, $p ) : null;
		return is_array( $fallback ) ? $fallback + [ 'isFallback' => true ] : null;
	}
}
