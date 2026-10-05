<?php

namespace PropstackLite\Domain;

/**
 * Adresse eines Objekts – bereits nach Propstack-Freigabe gefiltert.
 *
 * Ist die Adresse verborgen (`hidden`), werden Straße, Hausnummer und Koordinaten
 * gar nicht erst gespeichert. Der Konstruktor erzwingt das zusätzlich.
 */
final class Address {

	public readonly bool $hidden;
	public readonly ?string $street;
	public readonly ?string $houseNumber;
	public readonly ?string $zipCode;
	public readonly ?string $city;
	public readonly ?string $district;
	public readonly ?string $sublocality;
	public readonly ?string $region;
	public readonly ?string $country;
	public readonly ?float $lat;
	public readonly ?float $lng;

	public function __construct(
		bool $hidden,
		?string $street = null,
		?string $houseNumber = null,
		?string $zipCode = null,
		?string $city = null,
		?string $district = null,
		?string $sublocality = null,
		?string $region = null,
		?string $country = null,
		?float $lat = null,
		?float $lng = null
	) {
		$this->hidden      = $hidden;
		$this->street      = $hidden ? null : $street;
		$this->houseNumber = $hidden ? null : $houseNumber;
		$this->lat         = $hidden ? null : $lat;
		$this->lng         = $hidden ? null : $lng;
		$this->zipCode     = $zipCode;
		$this->city        = $city;
		$this->district    = $district;
		$this->sublocality = $sublocality;
		$this->region      = $region;
		$this->country     = $country;
	}

	/** „12107 Berlin“ bzw. „12107 Berlin (Mariendorf)“ – nie mit Straße. */
	public function locality(): string {
		$label = trim( ( $this->zipCode ?? '' ) . ' ' . ( $this->city ?? '' ) );
		$part  = $this->district ?? $this->sublocality;
		if ( null !== $part && ( '' === $label || false === mb_stripos( $label, $part ) ) ) {
			$label = '' === $label ? $part : $label . ' (' . $part . ')';
		}
		return $label;
	}

	/** Öffentlich darstellbare Adresse: mit Straße nur, wenn Propstack sie freigibt. */
	public function publicLabel(): string {
		$locality = $this->locality();
		if ( $this->hidden || null === $this->street ) {
			return $locality;
		}
		$street = trim( $this->street . ' ' . ( $this->houseNumber ?? '' ) );
		return '' === $locality ? $street : $street . ', ' . $locality;
	}

	public function toArray(): array {
		return [
			'hidden'      => $this->hidden,
			'street'      => $this->street,
			'houseNumber' => $this->houseNumber,
			'zipCode'     => $this->zipCode,
			'city'        => $this->city,
			'district'    => $this->district,
			'sublocality' => $this->sublocality,
			'region'      => $this->region,
			'country'     => $this->country,
			'lat'         => $this->lat,
			'lng'         => $this->lng,
		];
	}

	public static function fromArray( array $a ): self {
		return new self(
			// Fehlt die Angabe, gilt die Adresse als verborgen (sichere Voreinstellung).
			(bool) ( $a['hidden'] ?? true ),
			$a['street'] ?? null,
			$a['houseNumber'] ?? null,
			$a['zipCode'] ?? null,
			$a['city'] ?? null,
			$a['district'] ?? null,
			$a['sublocality'] ?? null,
			$a['region'] ?? null,
			$a['country'] ?? null,
			isset( $a['lat'] ) ? (float) $a['lat'] : null,
			isset( $a['lng'] ) ? (float) $a['lng'] : null
		);
	}
}
