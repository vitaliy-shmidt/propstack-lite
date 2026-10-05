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
		'location_id',
		'old_crm_id',
		'pricehubble_username',
		'phone_system_number',
		'phone_system_numbers',
		'lp_address',
		'short_address',
	];
}
