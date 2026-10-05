<?php

namespace PropstackLite\Frontend;

use PropstackLite\Domain\Property;

/**
 * Deutsche Formatierung und Labels für die Ausgabe. Liefert unescapten Text –
 * das Escaping erfolgt im Template.
 */
final class Formatter {

	private const TYPE_LABELS = [
		'APARTMENT'                => 'Wohnung',
		'HOUSE'                    => 'Haus',
		'TRADE_SITE'               => 'Grundstück',
		'OFFICE'                   => 'Büro',
		'STORE'                    => 'Ladenfläche',
		'GASTRONOMY'               => 'Gastronomie',
		'INDUSTRY'                 => 'Halle/Produktion',
		'INVESTMENT'               => 'Anlageobjekt',
		'GARAGE'                   => 'Stellplatz/Garage',
		'SHORT_TERM_ACCOMODATION'  => 'Ferienwohnung',
		'SHORT_TERM_ACCOMMODATION' => 'Ferienwohnung',
		'SPECIAL_PURPOSE'          => 'Spezialimmobilie',
		'FLAT_SHARE_ROOM'          => 'WG-Zimmer',
	];

	public static function money( ?float $value ): ?string {
		return null === $value ? null : number_format( $value, 0, ',', '.' ) . ' €';
	}

	public static function area( ?float $value ): ?string {
		return null === $value ? null : self::decimal( $value ) . ' m²';
	}

	public static function decimal( float $value, int $maxDecimals = 2 ): string {
		$formatted = number_format( $value, $maxDecimals, ',', '.' );
		return str_contains( $formatted, ',' ) ? rtrim( rtrim( $formatted, '0' ), ',' ) : $formatted;
	}

	public static function typeLabel( ?string $rsType ): string {
		return self::TYPE_LABELS[ $rsType ?? '' ] ?? 'Immobilie';
	}

	public static function marketingLabel( ?string $marketingType ): ?string {
		return match ( $marketingType ) {
			Property::BUY  => 'Kauf',
			Property::RENT => 'Miete',
			default        => null,
		};
	}

	/** @return array{label: string, value: string} Preiszeile – nie „0 €“. */
	public static function priceRow( Property $property ): array {
		if ( $property->isRent() ) {
			if ( null !== $property->baseRent ) {
				return [ 'label' => 'Kaltmiete', 'value' => (string) self::money( $property->baseRent ) ];
			}
			if ( null !== $property->totalRent ) {
				return [ 'label' => 'Warmmiete', 'value' => (string) self::money( $property->totalRent ) ];
			}
			return [ 'label' => 'Miete', 'value' => 'auf Anfrage' ];
		}
		if ( null !== $property->price && ! $property->priceOnRequest ) {
			return [ 'label' => 'Kaufpreis', 'value' => (string) self::money( $property->price ) ];
		}
		return [ 'label' => 'Kaufpreis', 'value' => 'auf Anfrage' ];
	}

	/** Titel mit Fallback „Wohnung in 12107 Berlin“, falls Propstack keinen Titel liefert. */
	public static function title( Property $property ): string {
		if ( null !== $property->title ) {
			return $property->title;
		}
		$locality = $property->address->locality();
		return self::typeLabel( $property->rsType ) . ( '' === $locality ? '' : ' in ' . $locality );
	}
}
