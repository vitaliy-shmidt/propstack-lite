<?php

namespace PropstackLite\Mapping;

use PropstackLite\Domain\Address;
use PropstackLite\Domain\Agent;
use PropstackLite\Domain\Image;
use PropstackLite\Domain\Property;
use PropstackLite\Support\Slugger;

/**
 * Überführt Propstack-Rohdaten (Format `expand=1` / `new=1`) in das interne Property-Modell.
 *
 * Positivliste: Es wird nur gelesen, was hier oder im FieldCatalog steht. Interne CRM-Felder
 * (Notizen, Provisionen, Relationships, Tokens, interne Maklerdaten …) werden nie angefasst.
 *
 * Datenschutzregeln:
 * - Adresse gilt als verborgen, solange Propstack nicht ausdrücklich `hide_address: false` liefert;
 *   dann werden Straße, Hausnummer und Koordinaten nicht übernommen.
 * - Bilder mit `is_private` oder `is_not_for_exposee` werden verworfen.
 * - Der interne Objektname (`name`) wird nicht als Titel-Fallback verwendet, da er Adressen enthalten kann.
 */
final class PropertyMapper {

	/** @throws MappingException */
	public function map( array $raw ): Property {
		$id = self::idOf( $raw );
		if ( null === $id ) {
			throw new MappingException( 'Datensatz ohne gültige ID.' );
		}

		$hidden  = self::bool( $raw, 'hide_address' ) !== false;
		$address = new Address(
			hidden: $hidden,
			street: Sanitizer::text( self::value( $raw, 'street' ), 120 ),
			houseNumber: Sanitizer::text( self::value( $raw, 'house_number' ), 20 ),
			zipCode: Sanitizer::text( self::value( $raw, 'zip_code' ), 20 ),
			city: Sanitizer::text( self::value( $raw, 'city' ), 100 ),
			district: Sanitizer::text( self::value( $raw, 'district' ), 100 ),
			sublocality: Sanitizer::text( self::value( $raw, 'sublocality_level_1' ), 100 ),
			region: Sanitizer::text( self::value( $raw, 'region' ), 100 ),
			country: Sanitizer::text( self::value( $raw, 'country' ), 3 ),
			lat: Sanitizer::float( self::value( $raw, 'lat' ) ),
			lng: Sanitizer::float( self::value( $raw, 'lng' ) )
		);

		$marketingType = Sanitizer::enum( self::value( $raw, 'marketing_type' ) );
		if ( ! in_array( $marketingType, [ Property::BUY, Property::RENT ], true ) ) {
			$marketingType = null;
		}
		$rsType = Sanitizer::enum( self::value( $raw, 'rs_type' ) );
		$rooms  = Sanitizer::float( self::value( $raw, 'number_of_rooms' ) );

		[ $images, $floorplans ] = $this->mapImages( $raw );

		return new Property(
			id: $id,
			slug: Slugger::forProperty( $rsType, $rooms, $marketingType, $address->city, $address->district ?? $address->sublocality ),
			title: Sanitizer::text( self::value( $raw, 'title' ), 255 ),
			statusId: self::statusIdOf( $raw ),
			statusName: Sanitizer::text( self::status( $raw )['name'] ?? null, 100 ),
			archived: self::archivedOf( $raw ),
			marketingType: $marketingType,
			objectType: Sanitizer::enum( self::value( $raw, 'object_type' ) ),
			rsType: $rsType,
			rsCategory: Sanitizer::enum( self::value( $raw, 'rs_category' ) ),
			address: $address,
			price: Sanitizer::float( self::value( $raw, 'price' ) ),
			priceOnRequest: true === self::bool( $raw, 'price_on_inquiry' ),
			baseRent: Sanitizer::float( self::value( $raw, 'base_rent' ) ),
			totalRent: Sanitizer::float( self::value( $raw, 'total_rent' ) ),
			livingSpace: Sanitizer::float( self::value( $raw, 'living_space' ) ),
			plotArea: Sanitizer::float( self::value( $raw, 'plot_area' ) ),
			rooms: $rooms,
			bedrooms: Sanitizer::int( self::value( $raw, 'number_of_bed_rooms' ) ),
			bathrooms: Sanitizer::int( self::value( $raw, 'number_of_bath_rooms' ) ),
			facts: $this->mapCatalog( $raw, FieldCatalog::FACTS ),
			features: $this->mapFeatures( $raw ),
			energy: $this->mapCatalog( $raw, FieldCatalog::ENERGY ),
			texts: $this->mapTexts( $raw ),
			images: $images,
			floorplans: $floorplans,
			agent: $this->mapAgent( $raw['broker'] ?? null ),
			projectId: Sanitizer::int( self::value( $raw, 'project_id' ) ),
			unitId: Sanitizer::text( self::value( $raw, 'unit_id' ), 64 ),
			exposeeId: Sanitizer::text( self::value( $raw, 'exposee_id' ), 64 ),
			publicExposeUrl: Sanitizer::propstackUrl( self::value( $raw, 'public_expose_url' ) ),
			createdAt: Sanitizer::datetime( self::value( $raw, 'created_at' ) ),
			updatedAt: Sanitizer::datetime( self::value( $raw, 'updated_at' ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Hilfsfunktionen, die der Sync auch ohne vollständiges Mapping braucht
	 * ------------------------------------------------------------------- */

	public static function idOf( array $raw ): ?int {
		$id = $raw['id'] ?? null;
		return is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;
	}

	public static function statusIdOf( array $raw ): ?int {
		$id = self::status( $raw )['id'] ?? null;
		return is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;
	}

	public static function archivedOf( array $raw ): bool {
		return true === self::bool( $raw, 'archived' );
	}

	/** Detail/expand liefern `property_status`, die flache Liste `status`. */
	private static function status( array $raw ): array {
		foreach ( [ 'property_status', 'status' ] as $key ) {
			if ( isset( $raw[ $key ] ) && is_array( $raw[ $key ] ) && isset( $raw[ $key ]['id'] ) ) {
				return $raw[ $key ];
			}
		}
		return [];
	}

	/** Liest einen Wert – Propstack liefert die meisten Felder als `{label, value}`. */
	private static function value( array $raw, string $key ): mixed {
		$v = $raw[ $key ] ?? null;
		if ( is_array( $v ) && array_key_exists( 'value', $v ) && array_key_exists( 'label', $v ) ) {
			return $v['value'];
		}
		return $v;
	}

	private static function bool( array $raw, string $key ): ?bool {
		return Sanitizer::bool( self::value( $raw, $key ) );
	}

	/* --------------------------------------------------------------------- */

	private function mapCatalog( array $raw, array $catalog ): array {
		$out = [];
		foreach ( $catalog as $key => [ $type, $zeroIsNull ] ) {
			$value = self::value( $raw, $key );
			$clean = match ( $type ) {
				FieldCatalog::TYPE_FLOAT => Sanitizer::float( $value, $zeroIsNull ),
				FieldCatalog::TYPE_INT   => Sanitizer::int( $value, $zeroIsNull ),
				FieldCatalog::TYPE_BOOL  => Sanitizer::bool( $value ),
				FieldCatalog::TYPE_ENUM  => Sanitizer::enum( $value ),
				FieldCatalog::TYPE_LIST  => Sanitizer::stringList( $value ) ?: null,
				FieldCatalog::TYPE_LONG  => Sanitizer::text( $value, 2000 ),
				default                  => Sanitizer::text( $value, 255 ),
			};
			if ( null !== $clean ) {
				$out[ $key ] = $clean;
			}
		}
		return $out;
	}

	private function mapFeatures( array $raw ): array {
		$features = [];
		foreach ( FieldCatalog::FEATURES as $key ) {
			if ( true === self::bool( $raw, $key ) ) {
				$features[] = $key;
			}
		}
		return $features;
	}

	private function mapTexts( array $raw ): array {
		$texts = [];
		foreach ( FieldCatalog::TEXTS as $name => $key ) {
			$text = Sanitizer::richText( self::value( $raw, $key ) );
			if ( null !== $text ) {
				$texts[ $name ] = $text;
			}
		}
		return $texts;
	}

	/** @return array{0: list<Image>, 1: list<Image>} [Galerie, Grundrisse] */
	private function mapImages( array $raw ): array {
		$all = [];
		foreach ( [ 'images' => false, 'floorplans' => true ] as $key => $forceFloorplan ) {
			if ( empty( $raw[ $key ] ) || ! is_array( $raw[ $key ] ) ) {
				continue;
			}
			foreach ( $raw[ $key ] as $img ) {
				$image = $this->mapImage( $img, $forceFloorplan );
				if ( null !== $image ) {
					$all[] = $image;
				}
			}
		}

		usort(
			$all,
			static fn ( Image $a, Image $b ) => ( $a->position ?? PHP_INT_MAX ) <=> ( $b->position ?? PHP_INT_MAX )
		);

		$gallery    = array_values( array_filter( $all, static fn ( Image $i ) => ! $i->isFloorplan ) );
		$floorplans = array_values( array_filter( $all, static fn ( Image $i ) => $i->isFloorplan ) );
		return [ $gallery, $floorplans ];
	}

	private function mapImage( mixed $img, bool $forceFloorplan ): ?Image {
		if ( ! is_array( $img ) ) {
			return null;
		}
		// Niemals öffentlich: private oder nicht fürs Exposé freigegebene Bilder.
		// Fehlt ein Flag, gilt es als nicht gesetzt; ein vorhandener, aber uneindeutiger Wert (z. B. null) sperrt das Bild.
		$flag = static fn ( string $key ): ?bool => array_key_exists( $key, $img ) ? Sanitizer::bool( $img[ $key ] ) : false;
		if ( false !== $flag( 'is_private' ) || false !== $flag( 'is_not_for_exposee' ) ) {
			return null;
		}

		$url = static fn ( string ...$keys ) => self::firstUrl( $img, $keys );
		$image = new Image(
			id: Sanitizer::int( $img['id'] ?? null ),
			title: Sanitizer::text( $img['title'] ?? null, 200 ),
			position: Sanitizer::int( $img['position'] ?? null, false ),
			isFloorplan: $forceFloorplan || true === Sanitizer::bool( $img['is_floorplan'] ?? false ),
			url: $url( 'url', 'original' ),
			big: $url( 'big_url', 'big' ),
			medium: $url( 'medium_url', 'medium' ),
			thumb: $url( 'thumb_url', 'thumb' ),
			square: $url( 'square_url' ),
			smallThumb: $url( 'small_thumb_url' )
		);

		return null === $image->src( 'full' ) && null === $image->thumb ? null : $image;
	}

	private static function firstUrl( array $img, array $keys ): ?string {
		foreach ( $keys as $key ) {
			$url = Sanitizer::propstackUrl( $img[ $key ] ?? null );
			if ( null !== $url ) {
				return $url;
			}
		}
		return null;
	}

	/** Nur öffentliche Makler-Felder; interne Kontaktdaten werden nie gelesen. */
	private function mapAgent( mixed $broker ): ?Agent {
		if ( ! is_array( $broker ) ) {
			return null;
		}
		$agent = new Agent(
			name: Sanitizer::text( $broker['name'] ?? null, 120 ),
			firstName: Sanitizer::text( $broker['first_name'] ?? null, 60 ),
			lastName: Sanitizer::text( $broker['last_name'] ?? null, 60 ),
			academicTitle: Sanitizer::text( $broker['academic_title'] ?? null, 40 ),
			salutation: in_array( $broker['salutation'] ?? null, [ 'mr', 'ms' ], true ) ? $broker['salutation'] : null,
			position: Sanitizer::text( $broker['position'] ?? null, 120 ),
			avatarUrl: Sanitizer::propstackUrl( $broker['avatar_url'] ?? ( $broker['avatar'] ?? null ) ),
			email: Sanitizer::email( $broker['public_email'] ?? null ),
			phone: Sanitizer::phone( $broker['public_phone'] ?? null ),
			cell: Sanitizer::phone( $broker['public_cell'] ?? null )
		);
		return null === $agent->displayName() && ! $agent->hasContactChannel() ? null : $agent;
	}
}
