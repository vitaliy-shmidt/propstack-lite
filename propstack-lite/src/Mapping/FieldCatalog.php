<?php

namespace PropstackLite\Mapping;

/**
 * Whitelist der aus Propstack übernommenen Felder.
 *
 * Nur hier aufgeführte Felder (plus die im PropertyMapper explizit gemappten Kernfelder)
 * gelangen in das Property-Modell. Alles andere wird verworfen.
 * Dokumentation: docs/property-model.md – bei Änderungen dort mitpflegen.
 */
final class FieldCatalog {

	public const TYPE_FLOAT = 'float';
	public const TYPE_INT   = 'int';
	public const TYPE_BOOL  = 'bool';
	public const TYPE_TEXT  = 'text';
	public const TYPE_LONG  = 'long_text';
	public const TYPE_LIST  = 'list';
	public const TYPE_ENUM  = 'enum';

	/**
	 * Zusätzliche Eckdaten. Format: propstack_key => [Typ, 0 bedeutet „nicht angegeben“].
	 */
	public const FACTS = [
		// Preise & Kosten
		'price_per_sqm'                   => [ self::TYPE_FLOAT, true ],
		'rent_subsidy'                    => [ self::TYPE_FLOAT, true ], // Hausgeld/Monat
		'maintenance_reserve'             => [ self::TYPE_FLOAT, true ],
		'service_charge'                  => [ self::TYPE_FLOAT, true ],
		'heating_costs'                   => [ self::TYPE_FLOAT, true ],
		'heating_costs_in_service_charge' => [ self::TYPE_BOOL, false ],
		'deposit'                         => [ self::TYPE_TEXT, false ], // Freitext, z. B. "3 Kaltmieten"
		'parking_space_price'             => [ self::TYPE_FLOAT, true ],
		'courtage'                        => [ self::TYPE_TEXT, false ],
		'courtage_note'                   => [ self::TYPE_LONG, false ],
		// Flächen & Räume
		'property_space_value'            => [ self::TYPE_FLOAT, true ],
		'usable_floor_space'              => [ self::TYPE_FLOAT, true ],
		'total_floor_space'               => [ self::TYPE_FLOAT, true ],
		'balcony_space'                   => [ self::TYPE_FLOAT, true ],
		'garden_space'                    => [ self::TYPE_FLOAT, true ],
		'floor'                           => [ self::TYPE_INT, false ], // 0 = Erdgeschoss
		'number_of_floors'                => [ self::TYPE_INT, true ],
		'number_of_parking_spaces'        => [ self::TYPE_INT, true ],
		// Gebäude & Zustand
		'construction_year'               => [ self::TYPE_INT, true ],
		'last_refurbishment'              => [ self::TYPE_INT, true ],
		'condition'                       => [ self::TYPE_TEXT, false ],
		'interior_quality'                => [ self::TYPE_TEXT, false ],
		'free_from'                       => [ self::TYPE_TEXT, false ],
		'rented'                          => [ self::TYPE_BOOL, false ],
		'apartment_type'                  => [ self::TYPE_TEXT, false ],
		'building_type'                   => [ self::TYPE_TEXT, false ],
		'parking_space_type'              => [ self::TYPE_TEXT, false ],
		'heating_type'                    => [ self::TYPE_TEXT, false ],
		'firing_types'                    => [ self::TYPE_TEXT, false ],
		'bathroom'                        => [ self::TYPE_LIST, false ],
		'flooring_type'                   => [ self::TYPE_LIST, false ],
		'parking_space_types'             => [ self::TYPE_LIST, false ], // Phase 3, z. B. ["Tiefgarage", "Außenstellplatz"]
		'pets_allowed'                    => [ self::TYPE_TEXT, false ], // Phase 3, Propstack liefert „Ja“/„Nein“/„Nach Vereinbarung“
	];

	/** Deutsche Bezeichnungen der Katalogfelder für die Ausgabe. */
	public const LABELS = [
		'price_per_sqm'                          => 'Preis pro m²',
		'rent_subsidy'                           => 'Hausgeld',
		'maintenance_reserve'                    => 'Instandhaltungsrücklage',
		'service_charge'                         => 'Nebenkosten',
		'heating_costs'                          => 'Heizkosten',
		'deposit'                                => 'Kaution',
		'parking_space_price'                    => 'Stellplatzmiete',
		'courtage'                               => 'Provision',
		'property_space_value'                   => 'Fläche',
		'usable_floor_space'                     => 'Nutzfläche',
		'total_floor_space'                      => 'Gesamtfläche',
		'balcony_space'                          => 'Balkon-/Terrassenfläche',
		'garden_space'                           => 'Gartenfläche',
		'floor'                                  => 'Etage',
		'number_of_floors'                       => 'Etagen im Gebäude',
		'number_of_parking_spaces'               => 'Stellplätze',
		'construction_year'                      => 'Baujahr',
		'last_refurbishment'                     => 'Letzte Modernisierung',
		'condition'                              => 'Zustand',
		'interior_quality'                       => 'Qualität der Ausstattung',
		'free_from'                              => 'Verfügbar ab',
		'rented'                                 => 'Vermietet',
		'apartment_type'                         => 'Wohnungstyp',
		'building_type'                          => 'Haustyp',
		'heating_type'                           => 'Heizungsart',
		'firing_types'                           => 'Wesentlicher Energieträger',
		'energy_certificate_availability'        => 'Energieausweis',
		'building_energy_rating_type'            => 'Art des Energieausweises',
		'energy_efficiency_class'                => 'Energieeffizienzklasse',
		'energy_certificate_start_date'          => 'Ausgestellt am',
		'energy_certificate_end_date'            => 'Gültig bis',
		'energy_certificate_creation_date'       => 'Ausweis erstellt',
		'energy_consumption_contains_warm_water' => 'Warmwasser im Kennwert enthalten',
		'equipment_technology_construction_year' => 'Baujahr Anlagentechnik',
	];

	/** Ausstattungsmerkmale (nur bei `true`) → Bezeichnung. */
	public const FEATURE_LABELS = [
		'balcony'          => 'Balkon/Terrasse',
		'cellar'           => 'Keller',
		'lift'             => 'Aufzug',
		'built_in_kitchen' => 'Einbauküche',
		'garden'           => 'Garten/-mitbenutzung',
		'guest_toilet'     => 'Gäste-WC',
		'barrier_free'     => 'Barrierefrei',
		'storeroom'        => 'Abstellraum',
		'loggia'           => 'Loggia',
		'sauna'            => 'Sauna',
		'chimney'          => 'Kamin',
		'kitchen_complete' => 'Küche vollständig ausgestattet',
	];

	/**
	 * Übersetzung technischer Enum-Werte. Werte, die wie ein Enum aussehen, aber hier fehlen,
	 * werden nicht ausgegeben (keine englischen Rohwerte im Frontend).
	 */
	public const ENUM_LABELS = [
		'energy_efficiency_class' => [
			'A_PLUS_PLUS' => 'A++', 'A_PLUS' => 'A+', 'A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D',
			'E' => 'E', 'F' => 'F', 'G' => 'G', 'H' => 'H',
		],
		'object_type' => [
			'LIVING' => 'Wohnen', 'COMMERCIAL' => 'Gewerbe', 'INVESTMENT' => 'Anlage',
		],
		'rs_category' => [
			'APARTMENT' => 'Etagenwohnung', 'ROOF_STOREY' => 'Dachgeschosswohnung', 'MAISONETTE' => 'Maisonette',
			'GROUND_FLOOR' => 'Erdgeschosswohnung', 'PENTHOUSE' => 'Penthouse', 'LOFT' => 'Loft',
			'TERRACE_END_HOUSE' => 'Reiheneckhaus', 'MID_TERRACE_HOUSE' => 'Reihenmittelhaus', 'END_TERRACE_HOUSE' => 'Reihenendhaus',
			'SINGLE_FAMILY_HOUSE' => 'Einfamilienhaus', 'TWO_FAMILY_HOUSE' => 'Zweifamilienhaus', 'MULTI_FAMILY_HOUSE' => 'Mehrfamilienhaus',
			'SEMIDETACHED_HOUSE' => 'Doppelhaushälfte', 'VILLA' => 'Villa', 'BUNGALOW' => 'Bungalow',
		],
	];

	/** Energieausweis-Angaben (GEG-relevant). */
	public const ENERGY = [
		'energy_certificate_availability'        => [ self::TYPE_TEXT, false ],
		'building_energy_rating_type'            => [ self::TYPE_TEXT, false ],
		'energy_efficiency_value'                => [ self::TYPE_FLOAT, true ],
		'thermal_characteristic'                 => [ self::TYPE_FLOAT, true ],
		'energy_efficiency_class'                => [ self::TYPE_ENUM, false ],
		'energy_certificate_start_date'          => [ self::TYPE_TEXT, false ],
		'energy_certificate_end_date'            => [ self::TYPE_TEXT, false ],
		'energy_certificate_creation_date'       => [ self::TYPE_TEXT, false ],
		'energy_consumption_contains_warm_water' => [ self::TYPE_BOOL, false ],
		'equipment_technology_construction_year' => [ self::TYPE_INT, true ],
	];

	/** Ausstattungsmerkmale – übernommen nur, wenn Propstack ausdrücklich `true` liefert. */
	public const FEATURES = [
		'balcony',
		'cellar',
		'lift',
		'built_in_kitchen',
		'garden',
		'guest_toilet',
		'barrier_free',
		'storeroom',
		'loggia',
		'sauna',
		'chimney',
		'kitchen_complete',
	];

	/** Freitexte: interner Name => Propstack-Feld. */
	public const TEXTS = [
		'description' => 'description_note',
		'furnishing'  => 'furnishing_note',
		'location'    => 'location_note',
		'other'       => 'other_note',
	];

	/**
	 * Objektart-Gruppen der Immobiliensuche (Phase 7): URL-Wert => [deutsche Bezeichnung, rs_type-Werte].
	 * Nur diese Gruppen sind filterbar; seltene/technische rs_type-Werte (Ferienwohnung, WG-Zimmer …)
	 * erscheinen in der ungefilterten Liste, aber nicht als eigene Filteroption. Die Reihenfolge ist
	 * die Reihenfolge im Formular. Doku: docs/listing.md.
	 */
	public const TYPE_GROUPS = [
		'apartment'  => [ 'Wohnung', [ 'APARTMENT' ] ],
		'house'      => [ 'Haus', [ 'HOUSE' ] ],
		'plot'       => [ 'Grundstück', [ 'TRADE_SITE' ] ],
		'commercial' => [ 'Gewerbe', [ 'OFFICE', 'STORE', 'GASTRONOMY', 'INDUSTRY', 'SPECIAL_PURPOSE' ] ],
		'investment' => [ 'Anlageobjekt', [ 'INVESTMENT' ] ],
		'parking'    => [ 'Stellplatz/Garage', [ 'GARAGE' ] ],
	];

	/** Gruppe (URL-Wert) eines rs_type oder null, wenn die Objektart keiner Filtergruppe angehört. */
	public static function typeGroupOf( ?string $rsType ): ?string {
		foreach ( self::TYPE_GROUPS as $key => [ , $types ] ) {
			if ( in_array( $rsType, $types, true ) ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Bekannte interne CRM-Felder, die niemals gespeichert oder ausgegeben werden dürfen.
	 * Wird von Tests und `wp psl audit` verwendet (Positivliste bleibt maßgeblich).
	 */
	public const FORBIDDEN_KEYS = [
		'note',
		'internal_brokerage',
		'internal_commission',
		'internal_commission_percentage',
		'external_commission',
		'external_commission_percentage',
		'total_commission',
		'relationships',
		'folders',
		'documents',
		'links',
		'token',
		'is24_contact_id',
		'scout_id',
		'openimmo_email',
		'openimmo_firstname',
		'openimmo_lastname',
		'openimmo_phone',
		'valuation_price',
		'valuation_price_from',
		'valuation_price_to',
		'custom_fields',
		'broker_id',
		'team_id',
		'inquiry_department_id',
		'contract_type',
		'location_id',
		'old_crm_id',
		'pricehubble_username',
		'phone_system_number',
		'phone_system_numbers',
		'lp_address',
		'short_address',
	];
}
