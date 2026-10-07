<?php

namespace PropstackLite\Domain;

/**
 * Internes, unveränderliches Immobilienmodell – gemeinsam für Liste und Detailseite.
 *
 * Wird ausschließlich vom PropertyMapper aus gewhitelisteten Propstack-Feldern erzeugt
 * und als JSON in `{prefix}psl_properties.data` gespeichert. Feldbeschreibung: docs/property-model.md.
 */
final class Property {

	/** Version des JSON-Formats in der Spalte `data`. */
	public const FORMAT_VERSION = 1;

	public const BUY  = 'BUY';
	public const RENT = 'RENT';

	/**
	 * @param array<string, scalar|list<string>> $facts    Katalog-Felder (siehe FieldCatalog::FACTS)
	 * @param list<string>                       $features Ausstattungsmerkmale, die laut Propstack vorhanden sind
	 * @param array<string, scalar>              $energy   Katalog-Felder (siehe FieldCatalog::ENERGY)
	 * @param array<string, string>              $texts    description|furnishing|location|other (sicheres HTML)
	 * @param list<Image>                        $images   öffentliche Bilder ohne Grundrisse, nach Position sortiert
	 * @param list<Image>                        $floorplans öffentliche Grundrisse
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $slug,
		public readonly ?string $title,
		public readonly ?int $statusId,
		public readonly ?string $statusName,
		public readonly bool $archived,
		public readonly ?string $marketingType,
		public readonly ?string $objectType,
		public readonly ?string $rsType,
		public readonly ?string $rsCategory,
		public readonly Address $address,
		public readonly ?float $price,
		public readonly bool $priceOnRequest,
		public readonly ?float $baseRent,
		public readonly ?float $totalRent,
		public readonly ?float $livingSpace,
		public readonly ?float $plotArea,
		public readonly ?float $rooms,
		public readonly ?int $bedrooms,
		public readonly ?int $bathrooms,
		public readonly array $facts,
		public readonly array $features,
		public readonly array $energy,
		public readonly array $texts,
		public readonly array $images,
		public readonly array $floorplans,
		public readonly ?Agent $agent,
		public readonly ?int $projectId,
		public readonly ?string $unitId,
		public readonly ?string $exposeeId,
		public readonly ?string $publicExposeUrl,
		public readonly ?string $createdAt,
		public readonly ?string $updatedAt
	) {}

	public function isRent(): bool {
		return self::RENT === $this->marketingType;
	}

	/** Hauptpreis: Kaufpreis bzw. Kaltmiete (Fallback Warmmiete). `null` = nicht angegeben. */
	public function primaryPrice(): ?float {
		if ( $this->isRent() ) {
			return $this->baseRent ?? $this->totalRent;
		}
		return $this->price;
	}

	/**
	 * Preisbasis der Suche (Filter und Sortierung, Spalte `search_price`): Kaufpreis bzw. Kaltmiete.
	 * `null` bei „Preis auf Anfrage“ oder fehlendem Wert – nie 0, und ein verborgener Preis wird so
	 * auch nicht über Preisfilter erratbar. Warmmiete zählt bewusst nicht (Filter „Kaltmiete“).
	 */
	public function searchPrice(): ?float {
		if ( $this->priceOnRequest ) {
			return null;
		}
		$value = $this->isRent() ? $this->baseRent : $this->price;
		return null !== $value && $value > 0 ? $value : null;
	}

	public function mainImage(): ?Image {
		return $this->images[0] ?? null;
	}

	/** Hauptfläche: Wohnfläche, sonst allgemeine Fläche (z. B. bei Gewerbe). */
	public function mainArea(): ?float {
		if ( null !== $this->livingSpace ) {
			return $this->livingSpace;
		}
		$area = $this->facts['property_space_value'] ?? null;
		return is_numeric( $area ) ? (float) $area : null;
	}

	public function fact( string $key ): mixed {
		return $this->facts[ $key ] ?? null;
	}

	public function hasFeature( string $key ): bool {
		return in_array( $key, $this->features, true );
	}

	public function toArray(): array {
		return [
			'formatVersion'   => self::FORMAT_VERSION,
			'id'              => $this->id,
			'slug'            => $this->slug,
			'title'           => $this->title,
			'statusId'        => $this->statusId,
			'statusName'      => $this->statusName,
			'archived'        => $this->archived,
			'marketingType'   => $this->marketingType,
			'objectType'      => $this->objectType,
			'rsType'          => $this->rsType,
			'rsCategory'      => $this->rsCategory,
			'address'         => $this->address->toArray(),
			'price'           => $this->price,
			'priceOnRequest'  => $this->priceOnRequest,
			'baseRent'        => $this->baseRent,
			'totalRent'       => $this->totalRent,
			'livingSpace'     => $this->livingSpace,
			'plotArea'        => $this->plotArea,
			'rooms'           => $this->rooms,
			'bedrooms'        => $this->bedrooms,
			'bathrooms'       => $this->bathrooms,
			'facts'           => $this->facts,
			'features'        => $this->features,
			'energy'          => $this->energy,
			'texts'           => $this->texts,
			'images'          => array_map( static fn ( Image $i ) => $i->toArray(), $this->images ),
			'floorplans'      => array_map( static fn ( Image $i ) => $i->toArray(), $this->floorplans ),
			'agent'           => $this->agent?->toArray(),
			'projectId'       => $this->projectId,
			'unitId'          => $this->unitId,
			'exposeeId'       => $this->exposeeId,
			'publicExposeUrl' => $this->publicExposeUrl,
			'createdAt'       => $this->createdAt,
			'updatedAt'       => $this->updatedAt,
		];
	}

	public static function fromArray( array $a ): self {
		$num = static fn ( $v ) => is_numeric( $v ) ? (float) $v : null;
		$int = static fn ( $v ) => is_numeric( $v ) ? (int) $v : null;

		return new self(
			id: (int) ( $a['id'] ?? 0 ),
			slug: (string) ( $a['slug'] ?? '' ),
			title: $a['title'] ?? null,
			statusId: $int( $a['statusId'] ?? null ),
			statusName: $a['statusName'] ?? null,
			archived: (bool) ( $a['archived'] ?? false ),
			marketingType: $a['marketingType'] ?? null,
			objectType: $a['objectType'] ?? null,
			rsType: $a['rsType'] ?? null,
			rsCategory: $a['rsCategory'] ?? null,
			address: Address::fromArray( (array) ( $a['address'] ?? [] ) ),
			price: $num( $a['price'] ?? null ),
			priceOnRequest: (bool) ( $a['priceOnRequest'] ?? false ),
			baseRent: $num( $a['baseRent'] ?? null ),
			totalRent: $num( $a['totalRent'] ?? null ),
			livingSpace: $num( $a['livingSpace'] ?? null ),
			plotArea: $num( $a['plotArea'] ?? null ),
			rooms: $num( $a['rooms'] ?? null ),
			bedrooms: $int( $a['bedrooms'] ?? null ),
			bathrooms: $int( $a['bathrooms'] ?? null ),
			facts: (array) ( $a['facts'] ?? [] ),
			features: array_values( (array) ( $a['features'] ?? [] ) ),
			energy: (array) ( $a['energy'] ?? [] ),
			texts: (array) ( $a['texts'] ?? [] ),
			images: array_map( static fn ( $i ) => Image::fromArray( (array) $i ), array_values( (array) ( $a['images'] ?? [] ) ) ),
			floorplans: array_map( static fn ( $i ) => Image::fromArray( (array) $i ), array_values( (array) ( $a['floorplans'] ?? [] ) ) ),
			agent: isset( $a['agent'] ) && is_array( $a['agent'] ) ? Agent::fromArray( $a['agent'] ) : null,
			projectId: $int( $a['projectId'] ?? null ),
			unitId: $a['unitId'] ?? null,
			exposeeId: $a['exposeeId'] ?? null,
			publicExposeUrl: $a['publicExposeUrl'] ?? null,
			createdAt: $a['createdAt'] ?? null,
			updatedAt: $a['updatedAt'] ?? null
		);
	}
}
