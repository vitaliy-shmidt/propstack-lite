<?php

namespace PropstackLite\Frontend;

use PropstackLite\Domain\Property;
use PropstackLite\Mapping\FieldCatalog;
use PropstackLite\Routing\UrlGenerator;
use PropstackLite\Storage\PropertySearchResult;

/**
 * Shortcode `[propstack_list]` – Immobilienübersicht mit Filtern, Sortierung und Pagination (Phase 7).
 * Liest ausschließlich aus dem lokalen Bestand, löst also nie einen Propstack-Request aus.
 *
 * - Zustand nur über GET-Parameter (teilbare URLs, Zurück/Vor, ohne JavaScript nutzbar);
 *   Validierung in ListingRequest, SQL im PropertyStore/SearchQueryBuilder.
 * - Attribute schränken nur ein; die Sichtbarkeit (öffentliche Status) erzwingt der Store.
 *   Ein `status`-Attribut o. Ä. wird bewusst nicht unterstützt.
 * - `show_filters="0" show_sort="0" pagination="0"` ergibt eine statische Liste wie vor Phase 7
 *   (z. B. Teaser auf der Startseite), die URL-Parameter ignoriert.
 */
final class ListShortcode {

	public const TAG           = 'propstack_list';
	public const STYLE_HANDLE  = 'propstack-lite-list';
	public const SCRIPT_HANDLE = 'propstack-lite-list';

	public const DEFAULTS = [
		'per'            => '12',
		'limit'          => '',
		'page'           => '1',
		'marketing_type' => '',
		'property_type'  => '',
		'rs_type'        => '',
		'city'           => '',
		'zip_code'       => '',
		'price_from'     => '',
		'price_to'       => '',
		'min_price'      => '',
		'max_price'      => '',
		'sort'           => '',
		'sort_by'        => '',
		'order'          => '',
		'heading'        => 'h3',
		'show_filters'   => '1',
		'show_sort'      => '1',
		'pagination'     => '1',
	];

	private static int $instances = 0;

	public function __construct(
		private ListingService $service,
		private TemplateLoader $templates,
		private UrlGenerator $urls
	) {}

	public function register(): void {
		add_shortcode( self::TAG, [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'registerAssets' ] );
	}

	public function registerAssets(): void {
		wp_register_style( self::STYLE_HANDLE, PSL_URL . 'assets/css/psl-list.css', [], PSL_VERSION );
		wp_register_script( self::SCRIPT_HANDLE, PSL_URL . 'assets/js/psl-list.js', [], PSL_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
	}

	public function render( mixed $atts ): string {
		$a       = shortcode_atts( self::DEFAULTS, is_array( $atts ) ? $atts : [], self::TAG );
		$config  = ListingConfig::fromAtts( $a );
		$query   = $config->isInteractive() ? wp_unslash( $_GET ) : []; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- öffentliche GET-Suche, Whitelist in ListingRequest
		$request = ListingRequest::fromQuery( is_array( $query ) ? $query : [], $config );

		try {
			$result  = $this->service->search( $request->criteria() );
			$options = $config->showFilters ? $this->service->options( $config ) : null;
		} catch ( \Throwable ) {
			return $this->message( 'Die Immobilien können derzeit nicht geladen werden. Bitte versuchen Sie es später erneut.' );
		}

		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			$this->registerAssets(); // Rendering außerhalb von wp_enqueue_scripts (REST, AJAX, Builder-Vorschau)
		}
		wp_enqueue_style( self::STYLE_HANDLE );

		// Gar keine (passenden) Objekte im Bestand: wie bisher nur ein Hinweis, kein Formular.
		if ( 0 === $result->total && ! $request->hasFilters() ) {
			return $this->message( 'Derzeit sind keine passenden Immobilien verfügbar.' );
		}

		$id      = 'psl-list-' . ( ++self::$instances );
		$pageUrl = $this->pageUrl();
		$paging  = new Pagination( $result->total, $result->criteria->perPage, $result->criteria->page );

		$form = null;
		if ( $config->showFilters || $config->showSort ) {
			wp_enqueue_script( self::SCRIPT_HANDLE );
			$form = $this->form( $id, $request, $options, $pageUrl );
		}

		$reserved = $this->service->reservedStatusIds();
		$cards    = array_map( fn ( Property $p ) => $this->card( $p, $reserved ), $result->items );

		return $this->templates->render(
			'list.php',
			[
				'id'         => $id,
				'cards'      => $cards,
				'total'      => $result->total,
				'heading'    => $config->heading,
				'form'       => $form,
				'count'      => $this->countText( $result, $request ),
				'pageInfo'   => $paging->pages > 1 && ! $paging->isOutOfRange() ? sprintf( 'Seite %d von %d', $paging->current, $paging->pages ) : null,
				'empty'      => $this->emptyState( $result, $request, $pageUrl ),
				'pagination' => $config->paginate ? $this->pagination( $paging, $request, $pageUrl ) : null,
			]
		);
	}

	/** Permalink der aktuellen Seite (Formular-Ziel, Pagination, Reset); Fallback Übersicht. */
	private function pageUrl(): string {
		$url = is_singular() ? get_permalink() : false;
		return is_string( $url ) && '' !== $url ? $url : $this->urls->overviewUrl();
	}

	private function countText( PropertySearchResult $result, ListingRequest $request ): string {
		$n      = $result->total;
		$number = number_format( $n, 0, ',', '.' );
		if ( $request->hasFilters() ) {
			return 1 === $n ? '1 Immobilie entspricht Ihren Filtern.' : $number . ' Immobilien entsprechen Ihren Filtern.';
		}
		return 1 === $n ? '1 Immobilie gefunden' : $number . ' Immobilien gefunden';
	}

	/** @return array{message: string, url: string, label: string}|null */
	private function emptyState( PropertySearchResult $result, ListingRequest $request, string $pageUrl ): ?array {
		if ( [] !== $result->items ) {
			return null;
		}
		if ( $result->total > 0 && $result->isOutOfRange() ) {
			return [
				'message' => 'Diese Ergebnisseite ist nicht (mehr) vorhanden.',
				'url'     => $this->urls->listingUrl( $pageUrl, $request->queryArgs( 1 ) ),
				'label'   => 'Zur ersten Ergebnisseite',
			];
		}
		return [
			'message' => 'Für diese Filter wurden keine Immobilien gefunden.',
			'url'     => $this->urls->listingUrl( $pageUrl, [] ),
			'label'   => 'Filter zurücksetzen',
		];
	}

	/** @return array<string, mixed>|null */
	private function pagination( Pagination $paging, ListingRequest $request, string $pageUrl ): ?array {
		if ( ! $paging->isNeeded() ) {
			return null;
		}
		$url   = fn ( int $page ): string => $this->urls->listingUrl( $pageUrl, $request->queryArgs( $page ) );
		$items = [];
		foreach ( $paging->items() as $number ) {
			$items[] = null === $number ? null : [ 'number' => $number, 'url' => $url( $number ), 'current' => $number === $paging->current ];
		}
		return [
			'prev'  => null === $paging->previous() ? null : $url( $paging->previous() ),
			'next'  => null === $paging->next() ? null : $url( $paging->next() ),
			'items' => $items,
		];
	}

	/**
	 * View-Daten des Such-/Sortierformulars (unescaped; Escaping im Template).
	 *
	 * @param array{marketing: array<string, int>, rsTypes: array<string, int>, cities: array<string, int>, plotArea: bool}|null $options
	 * @return array<string, mixed>
	 */
	private function form( string $id, ListingRequest $request, ?array $options, string $pageUrl ): array {
		$config = $request->config;
		$fields = [];

		if ( null !== $options ) {
			if ( null === $config->fixedMarketing ) {
				$choices = [];
				foreach ( ListingRequest::MARKETING as $value => $type ) {
					if ( isset( $options['marketing'][ $type ] ) || $request->value( ListingRequest::P_MARKETING ) === $value ) {
						$choices[ $value ] = 'buy' === $value ? 'Kaufen' : 'Mieten';
					}
				}
				$fields[] = $this->select( $id, ListingRequest::P_MARKETING, 'Kaufen oder Mieten', [ '' => 'Alle' ] + $choices, $request, 2 );
			}

			if ( [] === $config->fixedRsTypes ) {
				$choices = [];
				foreach ( FieldCatalog::TYPE_GROUPS as $group => [ $label, $types ] ) {
					$present = array_intersect_key( $options['rsTypes'], array_flip( $types ) );
					if ( [] !== $present || $request->value( ListingRequest::P_TYPE ) === $group ) {
						$choices[ $group ] = $label;
					}
				}
				$fields[] = $this->select( $id, ListingRequest::P_TYPE, 'Objektart', [ '' => 'Alle Objektarten' ] + $choices, $request, 2 );
			}

			if ( null === $config->fixedCity ) {
				$choices = [];
				foreach ( array_keys( $options['cities'] ) as $city ) {
					$choices[ (string) $city ] = (string) $city;
				}
				$current = $request->value( ListingRequest::P_CITY );
				if ( '' !== $current && ! self::containsCaseless( array_keys( $choices ), $current ) ) {
					$choices[ $current ] = $current; // geteilter Link mit Ort ohne aktuelle Objekte
				}
				$fields[] = $this->select( $id, ListingRequest::P_CITY, 'Ort', [ '' => 'Alle Orte' ] + $choices, $request, 2 );
			}

			$priceLabel = match ( $request->marketingType() ) {
				'BUY'   => 'Kaufpreis',
				'RENT'  => 'Kaltmiete',
				default => 'Preis',
			};
			$priceHint  = null === $request->marketingType() ? 'Kaufpreis bzw. Kaltmiete pro Monat' : null;
			$fields[]   = $this->number( $id, ListingRequest::P_PRICE_MIN, $priceLabel . ' von', '€', $request, $priceHint );
			$fields[]   = $this->number( $id, ListingRequest::P_PRICE_MAX, $priceLabel . ' bis', '€', $request, null, null === $priceHint ? null : $id . '-' . ListingRequest::P_PRICE_MIN . '-hint' );
			$fields[]   = $this->number( $id, ListingRequest::P_AREA_MIN, 'Wohnfläche ab', 'm²', $request );
			if ( $options['plotArea'] || '' !== $request->value( ListingRequest::P_PLOT_MIN ) ) {
				$fields[] = $this->number( $id, ListingRequest::P_PLOT_MIN, 'Grundstücksfläche ab', 'm²', $request );
			}
			$rooms = [ '' => 'Beliebig' ];
			foreach ( ListingRequest::ROOM_OPTIONS as $n ) {
				$rooms[ (string) $n ] = $n . '+';
			}
			$fields[] = $this->select( $id, ListingRequest::P_ROOMS_MIN, 'Zimmer ab', $rooms, $request, 1 );
			$fields   = array_values( array_filter( $fields ) );
		}

		$sort = null;
		if ( $config->showSort ) {
			$per = [];
			foreach ( $request->perPageOptions() as $n ) {
				$per[ (string) $n ] = (string) $n;
			}
			$sort = [
				'sort' => [ 'id' => $id . '-sort', 'name' => ListingRequest::P_SORT, 'label' => 'Sortierung', 'options' => $request->sortOptions(), 'value' => $request->sort, 'default' => $config->defaultSort ],
				'per'  => [ 'id' => $id . '-per', 'name' => ListingRequest::P_PER, 'label' => 'Pro Seite', 'options' => $per, 'value' => (string) $request->perPage, 'default' => (string) $config->perPage ],
			];
		}

		// GET-Formulare verwerfen die Query der action-URL (z. B. ?page_id=4 ohne Pretty Permalinks).
		$hidden = [];
		$query  = (string) wp_parse_url( $pageUrl, PHP_URL_QUERY );
		if ( '' !== $query ) {
			parse_str( $query, $hidden );
		}

		return [
			'action'      => $this->urls->listingUrl( $pageUrl, [] ),
			'hidden'      => array_filter( $hidden, 'is_string' ),
			'fields'      => $fields,
			'sort'        => $sort,
			'activeCount' => count( $request->filters() ),
			'resetUrl'    => $request->isCustomized() ? $this->urls->listingUrl( $pageUrl, [] ) : null,
		];
	}

	/**
	 * Auswahlfeld; entfällt, wenn weniger als $minChoices echte Optionen existieren (z. B. nur ein Ort).
	 *
	 * @param array<int|string, string> $options Wert => Bezeichnung (numerische Werte werden zu int-Schlüsseln)
	 */
	private function select( string $id, string $name, string $label, array $options, ListingRequest $request, int $minChoices ): ?array {
		$value = $request->value( $name );
		if ( count( $options ) - 1 < $minChoices && '' === $value ) {
			return null;
		}
		// Wert aus der URL kann in anderer Schreibweise vorliegen („berlin“) → passende Option wählen.
		foreach ( array_keys( $options ) as $option ) {
			if ( '' !== $value && 0 === strcasecmp( (string) $option, $value ) ) {
				$value = (string) $option;
			}
		}
		return [ 'type' => 'select', 'id' => $id . '-' . $name, 'name' => $name, 'label' => $label, 'options' => $options, 'value' => $value ];
	}

	/** Zahlenfeld; $describedBy verweist auf den Hinweis eines anderen Feldes (Preis bis → Hinweis bei „Preis von“). */
	private function number( string $id, string $name, string $label, string $unit, ListingRequest $request, ?string $hint = null, ?string $describedBy = null ): array {
		$fieldId = $id . '-' . $name;
		return [
			'type'        => 'number',
			'id'          => $fieldId,
			'name'        => $name,
			'label'       => $label,
			'unit'        => $unit,
			'value'       => $request->value( $name ),
			'hint'        => $hint,
			'describedBy' => null !== $hint ? $fieldId . '-hint' : $describedBy,
		];
	}

	/** @param list<string> $haystack */
	private static function containsCaseless( array $haystack, string $needle ): bool {
		foreach ( $haystack as $item ) {
			if ( 0 === strcasecmp( mb_strtolower( $item ), mb_strtolower( $needle ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** View-Daten einer Karte (unescaped; Escaping im Template). */
	private function card( Property $p, array $reservedStatusIds ): array {
		$image = $p->mainImage(); // erstes öffentliches Nicht-Grundriss-Bild (Mapper filtert private/gesperrte)
		$area  = $p->mainArea();
		$title = Formatter::title( $p );
		$src   = $image?->src( 'medium' );

		return [
			'id'        => $p->id,
			'title'     => $title,
			'url'       => $this->urls->detailUrl( $p ),
			'image'     => null !== $src && str_starts_with( $src, 'https://' ) ? $src : null,
			'imageAlt'  => $image?->title ?? $title,
			'location'  => $p->address->publicLabel(), // bei verborgener Adresse nur PLZ/Ort/Ortsteil
			'type'      => Formatter::typeLabel( $p->rsType ),
			'marketing' => Formatter::marketingLabel( $p->marketingType ),
			'reserved'  => null !== $p->statusId && in_array( $p->statusId, $reservedStatusIds, true ),
			'price'     => Formatter::priceRow( $p ),
			'area'      => Formatter::area( $area ),
			'areaLabel' => null !== $p->livingSpace ? 'Wohnfläche' : 'Fläche',
			'rooms'     => null === $p->rooms ? null : Formatter::decimal( $p->rooms, 1 ),
		];
	}

	private function message( string $text ): string {
		return '<p class="psl-notice">' . esc_html( $text ) . '</p>';
	}
}
