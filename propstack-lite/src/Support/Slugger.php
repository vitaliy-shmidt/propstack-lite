<?php

namespace PropstackLite\Support;

/**
 * Slug-Erzeugung für Immobilien-URLs.
 *
 * Basiert auf dem ursprünglichen `slugify()` des Plugins, transliteriert deutsche Umlaute
 * aber deterministisch (ä → ae, unabhängig von der WordPress-Locale), damit URLs stabil bleiben.
 * Der Slug ist rein dekorativ – maßgeblich für das Routing ist die Propstack-ID.
 */
final class Slugger {

	public const FALLBACK = 'immobilie';

	/** rs_type => Bezeichnung im Slug. */
	private const TYPE_WORDS = [
		'APARTMENT'                => 'wohnung',
		'HOUSE'                    => 'haus',
		'TRADE_SITE'               => 'grundstueck',
		'OFFICE'                   => 'buero',
		'STORE'                    => 'ladenflaeche',
		'GASTRONOMY'               => 'gastronomie',
		'INDUSTRY'                 => 'halle',
		'INVESTMENT'               => 'anlageobjekt',
		'GARAGE'                   => 'stellplatz',
		'SHORT_TERM_ACCOMODATION'  => 'ferienwohnung',
		'SHORT_TERM_ACCOMMODATION' => 'ferienwohnung',
		'SPECIAL_PURPOSE'          => 'spezialimmobilie',
		'FLAT_SHARE_ROOM'          => 'wg-zimmer',
	];

	public static function slugify( string $text, int $maxLength = 80 ): string {
		$text = trim( strip_tags( $text ) );
		$text = strtr(
			$text,
			[
				'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
				'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue',
				'ß' => 'ss', 'ẞ' => 'SS',
			]
		);
		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		} elseif ( function_exists( 'iconv' ) ) {
			$converted = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );
			$text      = false === $converted ? $text : $converted;
		}
		$text = strtolower( $text );
		$text = preg_replace( '~[^a-z0-9]+~', '-', $text ) ?? '';
		$text = trim( $text, '-' );

		if ( $maxLength > 0 && strlen( $text ) > $maxLength ) {
			$text = rtrim( substr( $text, 0, $maxLength ), '-' );
		}
		return $text;
	}

	/**
	 * Strukturierter Slug, z. B. „2-zimmer-wohnung-kaufen-berlin-mariendorf“.
	 * Bewusst nicht aus dem Propstack-Titel (Marketingtexte, interne Kürzel, häufige Änderungen).
	 */
	public static function forProperty( ?string $rsType, ?float $rooms, ?string $marketingType, ?string $city, ?string $district ): string {
		$parts = [];
		if ( null !== $rooms && $rooms > 0 ) {
			$parts[] = str_replace( '.', '-', rtrim( rtrim( number_format( $rooms, 1, '.', '' ), '0' ), '.' ) ) . '-zimmer';
		}
		$parts[] = self::TYPE_WORDS[ $rsType ?? '' ] ?? self::FALLBACK;
		if ( 'BUY' === $marketingType ) {
			$parts[] = 'kaufen';
		} elseif ( 'RENT' === $marketingType ) {
			$parts[] = 'mieten';
		}
		if ( null !== $city ) {
			$parts[] = $city;
		}
		if ( null !== $district && ( null === $city || false === mb_stripos( $city, $district ) ) ) {
			$parts[] = $district;
		}

		$slug = self::slugify( implode( ' ', $parts ) );
		return '' === $slug ? self::FALLBACK : $slug;
	}
}
