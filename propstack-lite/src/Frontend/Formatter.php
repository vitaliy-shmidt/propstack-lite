<?php

namespace PropstackLite\Frontend;

use PropstackLite\Domain\Property;
use PropstackLite\Mapping\FieldCatalog;

/**
 * Deutsche Formatierung und Labels für die Ausgabe. Liefert unescapten Text –
 * das Escaping erfolgt im Template. Läuft ohne WordPress (Unit-Tests).
 */
final class Formatter {

	public const ON_REQUEST = 'Preis auf Anfrage';

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

	/** „429.000 €“; Nachkommastellen nur, wenn vorhanden („183,30 €“). */
	public static function money( ?float $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$decimals = abs( $value - round( $value ) ) < 0.005 ? 0 : 2;
		return number_format( $value, $decimals, ',', '.' ) . ' €';
	}

	/** „1.250 € / Monat“. */
	public static function monthly( ?float $value ): ?string {
		$money = self::money( $value );
		return null === $money ? null : $money . ' / Monat';
	}

	/** „5.797 €/m²“. */
	public static function perSquareMeter( ?float $value ): ?string {
		return null === $value ? null : number_format( $value, abs( $value - round( $value ) ) < 0.005 ? 0 : 2, ',', '.' ) . ' €/m²';
	}

	public static function area( ?float $value ): ?string {
		return null === $value ? null : self::decimal( $value ) . ' m²';
	}

	/** „3 Zimmer“, „2,5 Zimmer“. */
	public static function rooms( ?float $value ): ?string {
		return null === $value ? null : self::decimal( $value, 1 ) . ' Zimmer';
	}

	public static function decimal( float $value, int $maxDecimals = 2 ): string {
		$formatted = number_format( $value, $maxDecimals, ',', '.' );
		return str_contains( $formatted, ',' ) ? rtrim( rtrim( $formatted, '0' ), ',' ) : $formatted;
	}

	/** Etage: 0 → „Erdgeschoss“, n → „n. Obergeschoss“. */
	public static function floor( ?int $floor ): ?string {
		if ( null === $floor ) {
			return null;
		}
		return 0 === $floor ? 'Erdgeschoss' : $floor . '. Obergeschoss';
	}

	/** Energiekennwert mit Einheit: „26,33 kWh/(m²·a)“. */
	public static function energyValue( ?float $value ): ?string {
		return null === $value ? null : self::decimal( $value ) . ' kWh/(m²·a)';
	}

	public static function yesNo( ?bool $value ): ?string {
		return null === $value ? null : ( $value ? 'Ja' : 'Nein' );
	}

	/**
	 * Datumsangaben: ISO (2026-01-22 bzw. mit Uhrzeit) → „22.01.2026“; deutsche Angaben
	 * und Freitext („ab 1. Mai 2014“) bleiben unverändert.
	 */
	public static function date( mixed $value ): ?string {
		$text = self::text( $value );
		if ( null === $text ) {
			return null;
		}
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[T ].*)?$/', $text, $m ) ) {
			return $m[3] . '.' . $m[2] . '.' . $m[1];
		}
		return $text;
	}

	/**
	 * Freitext/Label für die Ausgabe. Werte, die wie technische Enums aussehen (z. B. FIRST_TIME_USE),
	 * werden nicht ausgegeben – stattdessen enumLabel() mit Übersetzung verwenden.
	 */
	public static function text( mixed $value ): ?string {
		if ( is_array( $value ) ) {
			$parts = array_filter( array_map( [ self::class, 'text' ], $value ) );
			return [] === $parts ? null : implode( ', ', $parts );
		}
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return null;
		}
		$text = trim( (string) $value );
		if ( '' === $text || self::looksLikeEnum( $text ) ) {
			return null;
		}
		return $text;
	}

	/** Übersetzt Enum-Werte über FieldCatalog::ENUM_LABELS; bereits lesbare Werte (z. B. „A+“) bleiben. */
	public static function enumLabel( string $field, mixed $value ): ?string {
		if ( ! is_scalar( $value ) || is_bool( $value ) ) {
			return null;
		}
		$raw = trim( (string) $value );
		$map = FieldCatalog::ENUM_LABELS[ $field ] ?? [];
		if ( isset( $map[ strtoupper( $raw ) ] ) ) {
			return $map[ strtoupper( $raw ) ];
		}
		return self::text( $raw );
	}

	public static function label( string $field ): string {
		return FieldCatalog::LABELS[ $field ] ?? $field;
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

	/** @return array{label: string, value: string} Preiszeile der Liste – nie „0 €“; „Preis auf Anfrage“ hat Vorrang. */
	public static function priceRow( Property $property ): array {
		if ( $property->isRent() ) {
			if ( $property->priceOnRequest ) {
				return [ 'label' => 'Miete', 'value' => 'auf Anfrage' ];
			}
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

	/**
	 * Hauptpreis der Detailseite.
	 *
	 * @return array{label: string, value: string, onRequest: bool}
	 */
	public static function displayPrice( Property $property ): array {
		if ( $property->isRent() ) {
			if ( null !== $property->baseRent && ! $property->priceOnRequest ) {
				return [ 'label' => 'Kaltmiete', 'value' => (string) self::monthly( $property->baseRent ), 'onRequest' => false ];
			}
			if ( null !== $property->totalRent && ! $property->priceOnRequest ) {
				return [ 'label' => 'Warmmiete', 'value' => (string) self::monthly( $property->totalRent ), 'onRequest' => false ];
			}
			return [ 'label' => 'Miete', 'value' => self::ON_REQUEST, 'onRequest' => true ];
		}
		if ( null !== $property->price && ! $property->priceOnRequest ) {
			return [ 'label' => 'Kaufpreis', 'value' => (string) self::money( $property->price ), 'onRequest' => false ];
		}
		return [ 'label' => 'Kaufpreis', 'value' => self::ON_REQUEST, 'onRequest' => true ];
	}

	/** Titel mit Fallback „Wohnung in 12107 Berlin“, falls Propstack keinen Titel liefert. */
	public static function title( Property $property ): string {
		if ( null !== $property->title ) {
			return $property->title;
		}
		$locality = $property->address->locality();
		return self::typeLabel( $property->rsType ) . ( '' === $locality ? '' : ' in ' . $locality );
	}

	private static function looksLikeEnum( string $text ): bool {
		return (bool) preg_match( '/^[A-Z][A-Z0-9]*(_[A-Z0-9]+)+$/', $text );
	}
}
