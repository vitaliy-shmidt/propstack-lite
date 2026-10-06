<?php

namespace PropstackLite\Seo;

use PropstackLite\Domain\Property;
use PropstackLite\Frontend\Formatter;
use PropstackLite\Mapping\FieldCatalog;
use PropstackLite\Routing\RouteDecision;

/**
 * Zentrale SEO-Logik für Immobilien-Detailseiten (ohne WordPress testbar).
 *
 * Quellen ausschließlich: gewhitelistetes Property-Modell, Routing-Entscheidung, kanonische URL
 * aus dem UrlGenerator und Site-Daten (Marke, Startseite). Keine API-Requests.
 * Datenschutz: Bei verborgener Adresse enthält nichts (Title, Description, OG, JSON-LD) Straße,
 * Hausnummer oder Koordinaten – das Address-Modell speichert sie dann gar nicht erst.
 */
final class SeoService {

	/** Max. Titellänge inkl. Marke (≈ Anzeigebreite in Suchergebnissen; Beispiel aus der Vorgabe passt genau). */
	public const TITLE_TARGET       = 70;
	public const DESCRIPTION_TARGET = 158;
	public const MAX_SCHEMA_IMAGES  = 5;

	/** Objektarten mit sinnvoller Zimmerangabe im Titel. */
	private const ROOM_TYPES = [ 'APARTMENT', 'HOUSE', 'SHORT_TERM_ACCOMODATION', 'SHORT_TERM_ACCOMMODATION' ];

	/** Schema.org-Typ je Objektart; alles andere bewusst „Place“. */
	private const SCHEMA_TYPES = [ 'APARTMENT' => 'Apartment', 'HOUSE' => 'House' ];

	/** ISO-3166-Alpha-3 → Alpha-2 für addressCountry (nur gängige Werte). */
	private const COUNTRIES = [ 'DEU' => 'DE', 'AUT' => 'AT', 'CHE' => 'CH', 'ESP' => 'ES', 'DE' => 'DE', 'AT' => 'AT', 'CH' => 'CH', 'ES' => 'ES' ];

	public function __construct(
		private string $brand,
		private string $homeUrl,
		private string $locale = 'de_DE'
	) {}

	/** SEO-Werte einer renderbaren Detailseite (200: aktiv, reserviert, Verkauft-Phase). */
	public function forProperty( Property $p, RouteDecision $decision, string $canonical, string $overviewUrl ): SeoData {
		$title       = $this->title( $p );
		$description = $this->description( $p );
		$image       = self::shareImage( $p );
		$imageUrl    = null === $image ? null : self::https( $image->src( 'big' ) );
		$heading     = Formatter::title( $p );

		$og = [
			'type'        => 'website',
			'title'       => $title,
			'description' => $description,
			'url'         => $canonical,
			'locale'      => $this->locale,
			'site_name'   => $this->brand,
		];
		if ( null !== $imageUrl ) {
			$og['image']     = $imageUrl;
			$og['image:alt'] = $image->title ?? $heading;
		}

		$twitter = [
			'card'        => null === $imageUrl ? 'summary' : 'summary_large_image',
			'title'       => $title,
			'description' => $description,
		];
		if ( null !== $imageUrl ) {
			$twitter['image'] = $imageUrl;
		}

		return new SeoData(
			title: $title,
			description: $description,
			canonical: $canonical,
			robots: [ 'index' => ! $decision->isNoindex(), 'follow' => true ],
			openGraph: self::plainDeep( $og ),
			twitter: self::plainDeep( $twitter ),
			jsonLd: self::plainDeep( $this->jsonLd( $p, $canonical, $overviewUrl, $description ) )
		);
	}

	/** HTTP 410: kein Canonical, kein OG/Schema, noindex. */
	public function forGone(): SeoData {
		return new SeoData(
			title: $this->withBrand( 'Immobilie nicht mehr verfügbar' ),
			description: null,
			canonical: null,
			robots: [ 'index' => false, 'follow' => true ]
		);
	}

	/* ------------------------------------------------------------------ Title */

	/**
	 * „3-Zimmer-Wohnung kaufen in Berlin-Mariendorf | Marke“. Kürzt schrittweise (Ortsteil, Zimmer),
	 * nie mitten im Wort; die Marke bleibt am Ende.
	 */
	public function title( Property $p ): string {
		return self::plain( $this->buildTitle( $p ) );
	}

	private function buildTitle( Property $p ): string {
		$type  = Formatter::typeLabel( $p->rsType );
		$verb  = match ( $p->marketingType ) {
			Property::BUY  => 'kaufen',
			Property::RENT => 'mieten',
			default        => null,
		};
		$rooms = null !== $p->rooms && in_array( $p->rsType, self::ROOM_TYPES, true )
			? Formatter::decimal( $p->rooms, 1 ) . '-Zimmer-'
			: '';
		$city     = $p->address->city;
		$district = $p->address->district ?? $p->address->sublocality;

		$variants = [];
		foreach ( [ true, false ] as $withRooms ) {
			foreach ( [ true, false ] as $withDistrict ) {
				$noun  = ( $withRooms && '' !== $rooms ) ? $rooms . $type : $type;
				$place = self::place( $city, $withDistrict ? $district : null );
				$variants[] = trim( $noun . ( null !== $verb ? ' ' . $verb : '' ) . ( '' !== $place ? ' in ' . $place : '' ) );
			}
		}
		$variants = array_values( array_unique( $variants ) );
		foreach ( $variants as $variant ) {
			if ( mb_strlen( $this->withBrand( $variant ) ) <= self::TITLE_TARGET ) {
				return $this->withBrand( $variant );
			}
		}
		// Keine Variante passt: kürzeste nehmen und ggf. wortweise kürzen.
		$shortest = (string) end( $variants );
		$budget   = self::TITLE_TARGET - ( '' === $this->brand ? 0 : mb_strlen( ' | ' . $this->brand ) );
		return $this->withBrand( self::truncateWords( $shortest, max( 20, $budget ), '' ) );
	}

	/* ------------------------------------------------------------ Description */

	/**
	 * „Wohnung zum Kauf in Berlin-Mariendorf: 3 Zimmer, ca. 82,5 m² Wohnfläche, Kaufpreis 429.000 €,
	 * mit Balkon/Terrasse und Aufzug. Jetzt weitere Informationen anfragen.“ – max. ~158 Zeichen.
	 */
	public function description( Property $p ): string {
		return self::plain( $this->buildDescription( $p ) );
	}

	private function buildDescription( Property $p ): string {
		$type  = Formatter::typeLabel( $p->rsType );
		$mode  = match ( $p->marketingType ) {
			Property::BUY  => ' zum Kauf',
			Property::RENT => ' zur Miete',
			default        => '',
		};
		$place = self::place( $p->address->city, $p->address->district ?? $p->address->sublocality );
		$intro = $type . $mode . ( '' !== $place ? ' in ' . $place : '' );

		$facts = [];
		if ( null !== $p->rooms ) {
			$facts['rooms'] = Formatter::decimal( $p->rooms, 1 ) . ' Zimmer';
		}
		$area = $p->mainArea();
		if ( null !== $area ) {
			$facts['area'] = 'ca. ' . Formatter::area( $area ) . ( null !== $p->livingSpace ? ' Wohnfläche' : ' Fläche' );
		}
		if ( null !== $p->plotArea && null === $p->livingSpace ) {
			$facts['plot'] = Formatter::area( $p->plotArea ) . ' Grundstück';
		}
		$price = Formatter::displayPrice( $p );
		if ( ! $price['onRequest'] ) {
			$facts['price'] = $price['label'] . ' ' . str_replace( ' / Monat', '/Monat', $price['value'] );
		}
		$features = [];
		foreach ( array_slice( $p->features, 0, 2 ) as $key ) {
			if ( isset( FieldCatalog::FEATURE_LABELS[ $key ] ) ) {
				$features[] = FieldCatalog::FEATURE_LABELS[ $key ];
			}
		}
		$cta = 'Jetzt weitere Informationen anfragen.';

		// Bausteine in Reihenfolge der Entbehrlichkeit entfernen, bis die Länge passt.
		$drop = [ 'feature', 'cta', 'feature', 'plot', 'rooms', 'area', 'price' ];
		while ( true ) {
			$parts = array_values( $facts );
			if ( [] !== $features ) {
				$parts[] = 'mit ' . implode( ' und ', $features );
			}
			$text = $intro . ( [] === $parts ? '.' : ': ' . implode( ', ', $parts ) . '.' ) . ( null !== $cta ? ' ' . $cta : '' );
			if ( mb_strlen( $text ) <= self::DESCRIPTION_TARGET || [] === $drop ) {
				break;
			}
			$next = array_shift( $drop );
			if ( 'feature' === $next ) {
				array_pop( $features );
			} elseif ( 'cta' === $next ) {
				$cta = null;
			} else {
				unset( $facts[ $next ] );
			}
		}
		return self::truncateWords( $text, self::DESCRIPTION_TARGET );
	}

	/* ---------------------------------------------------------------- JSON-LD */

	/** @return array<string, mixed> */
	public function jsonLd( Property $p, string $canonical, string $overviewUrl, string $description ): array {
		$orgId   = rtrim( $this->homeUrl, '/' ) . '/#psl-realestateagent';
		$name    = Formatter::title( $p );
		$listing = [
			'@type'       => 'RealEstateListing',
			'@id'         => $canonical . '#listing',
			'url'         => $canonical,
			'name'        => $name,
			'description' => $description,
			'about'       => [ '@id' => $canonical . '#property' ],
			'offeredBy'   => [ '@id' => $orgId ],
			'breadcrumb'  => [ '@id' => $canonical . '#breadcrumb' ],
		];
		if ( null !== $p->createdAt ) {
			$listing['datePosted'] = substr( $p->createdAt, 0, 10 );
		}
		$images = [];
		foreach ( $p->images as $image ) {
			$url = $image->isFloorplan ? null : self::https( $image->src( 'big' ) );
			if ( null !== $url ) {
				$images[] = $url;
			}
			if ( count( $images ) >= self::MAX_SCHEMA_IMAGES ) {
				break;
			}
		}
		if ( [] !== $images ) {
			$listing['image'] = $images;
		}
		$offer = $this->offer( $p, $canonical );
		if ( null !== $offer ) {
			$listing['offers'] = $offer;
		}

		$place = [
			'@type'   => self::SCHEMA_TYPES[ $p->rsType ?? '' ] ?? 'Place',
			'@id'     => $canonical . '#property',
			'name'    => $name,
			'address' => $this->postalAddress( $p ),
		];
		if ( null !== $p->rooms && in_array( $p->rsType, self::ROOM_TYPES, true ) ) {
			$place['numberOfRooms'] = $p->rooms;
		}
		$area = $p->mainArea();
		if ( null !== $area && 'Place' !== $place['@type'] ) {
			$place['floorSize'] = [ '@type' => 'QuantitativeValue', 'value' => $area, 'unitCode' => 'MTK' ];
		}
		if ( ! $p->address->hidden && null !== $p->address->lat && null !== $p->address->lng ) {
			$place['geo'] = [ '@type' => 'GeoCoordinates', 'latitude' => $p->address->lat, 'longitude' => $p->address->lng ];
		}

		// Jeder Eintrag mit URL (Google: `item` Pflicht außer beim letzten) – der Ort hat keine eigene Seite
		// und erscheint daher nur in der sichtbaren Breadcrumb, nicht im Schema.
		$crumbs = [
			[ 'name' => 'Startseite', 'item' => $this->homeUrl ],
			[ 'name' => 'Immobilien', 'item' => $overviewUrl ],
			[ 'name' => $name, 'item' => $canonical ],
		];
		$items    = [];
		foreach ( $crumbs as $i => $crumb ) {
			$items[] = [ '@type' => 'ListItem', 'position' => $i + 1 ] + $crumb;
		}

		return [
			'@context' => 'https://schema.org',
			'@graph'   => [
				$listing,
				$place,
				[ '@type' => 'RealEstateAgent', '@id' => $orgId, 'name' => $this->brand, 'url' => $this->homeUrl ],
				[ '@type' => 'BreadcrumbList', '@id' => $canonical . '#breadcrumb', 'itemListElement' => $items ],
			],
		];
	}

	/** Angebot nur mit bekanntem Preis – nie „0“. */
	private function offer( Property $p, string $canonical ): ?array {
		if ( $p->priceOnRequest ) {
			return null;
		}
		if ( $p->isRent() ) {
			$rent = $p->baseRent ?? $p->totalRent;
			if ( null === $rent ) {
				return null;
			}
			return [
				'@type'              => 'Offer',
				'url'                => $canonical,
				'businessFunction'   => 'http://purl.org/goodrelations/v1#LeaseOut',
				'priceSpecification' => [
					'@type'         => 'UnitPriceSpecification',
					'price'         => $rent,
					'priceCurrency' => 'EUR',
					'unitCode'      => 'MON',
					'unitText'      => null !== $p->baseRent ? 'Kaltmiete pro Monat' : 'Warmmiete pro Monat',
				],
			];
		}
		if ( null === $p->price ) {
			return null;
		}
		return [
			'@type'            => 'Offer',
			'url'              => $canonical,
			'businessFunction' => 'http://purl.org/goodrelations/v1#Sell',
			'price'            => $p->price,
			'priceCurrency'    => 'EUR',
		];
	}

	/** Bei verborgener Adresse nur PLZ/Ort/Region/Land (Straße ist dann ohnehin nicht im Modell). */
	private function postalAddress( Property $p ): array {
		$a       = $p->address;
		$address = [ '@type' => 'PostalAddress' ];
		if ( ! $a->hidden && null !== $a->street ) {
			$address['streetAddress'] = trim( $a->street . ' ' . ( $a->houseNumber ?? '' ) );
		}
		if ( null !== $a->zipCode ) {
			$address['postalCode'] = $a->zipCode;
		}
		if ( null !== $a->city ) {
			$address['addressLocality'] = $a->city;
		}
		if ( null !== $a->region ) {
			$address['addressRegion'] = $a->region;
		}
		$country = self::COUNTRIES[ strtoupper( (string) $a->country ) ] ?? null;
		if ( null !== $country ) {
			$address['addressCountry'] = $country;
		}
		return $address;
	}

	/* ---------------------------------------------------------------- Helfer */

	private function withBrand( string $text ): string {
		$text = trim( $text );
		if ( '' === $this->brand ) {
			return $text;
		}
		return '' === $text ? $this->brand : $text . ' | ' . $this->brand;
	}

	/** „Berlin-Mariendorf“ bzw. „Berlin“; Ortsteil nur, wenn nicht schon im Ort enthalten. */
	private static function place( ?string $city, ?string $district ): string {
		if ( null === $city ) {
			return $district ?? '';
		}
		if ( null === $district || false !== mb_stripos( $city, $district ) ) {
			return $city;
		}
		return $city . '-' . $district;
	}

	/**
	 * Klartext für Meta-Tags/JSON-LD (Defense in Depth – der Mapper liefert bereits Klartext):
	 * Tags und spitze Klammern entfernen, Leerraum normalisieren.
	 */
	public static function plain( string $text ): string {
		$text = str_replace( [ '<', '>' ], '', strip_tags( $text ) );
		return trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
	}

	/** plain() rekursiv auf alle Strings (URLs bleiben unverändert, da ohne < >). */
	private static function plainDeep( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) ) {
				$data[ $key ] = self::plain( $value );
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::plainDeep( $value );
			}
		}
		return $data;
	}

	/** Kürzt UTF-8-sicher an einer Wortgrenze (mit Auslassungszeichen). */
	public static function truncateWords( string $text, int $max, string $ellipsis = '…' ): string {
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max - mb_strlen( $ellipsis ) + 1 );
		$space = mb_strrpos( $cut, ' ' );
		$cut   = false === $space ? mb_substr( $text, 0, $max - mb_strlen( $ellipsis ) ) : mb_substr( $cut, 0, $space );
		return rtrim( $cut, " ,.;:–-" ) . $ellipsis;
	}

	/**
	 * Erstes öffentliches Galeriebild mit HTTPS-URL. Private/„nicht fürs Exposé“-Bilder verwirft
	 * bereits der Mapper; Grundrisse werden hier zusätzlich ausgeschlossen.
	 */
	public static function shareImage( Property $p ): ?\PropstackLite\Domain\Image {
		foreach ( $p->images as $image ) {
			if ( ! $image->isFloorplan && null !== self::https( $image->src( 'big' ) ) ) {
				return $image;
			}
		}
		return null;
	}

	/** Nur gültige HTTPS-URLs ohne Anführungszeichen, spitze Klammern oder Leerraum. */
	private static function https( ?string $url ): ?string {
		if ( null === $url || ! str_starts_with( $url, 'https://' ) || preg_match( '/["\'<>\s\\\\]/', $url ) ) {
			return null;
		}
		return false === filter_var( $url, FILTER_VALIDATE_URL ) ? null : $url;
	}
}
