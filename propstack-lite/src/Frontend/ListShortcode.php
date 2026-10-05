<?php

namespace PropstackLite\Frontend;

use PropstackLite\Domain\Property;
use PropstackLite\Routing\UrlGenerator;
use PropstackLite\Settings;
use PropstackLite\Storage\ListCriteria;
use PropstackLite\Storage\PropertyStore;

/**
 * Shortcode `[propstack_list]` – liest ausschließlich aus dem lokalen Bestand,
 * löst also nie einen Propstack-Request aus.
 *
 * Attribute schränken nur ein; die Sichtbarkeit (öffentliche Status) erzwingt der Store.
 * Ein `status`-Attribut o. Ä. wird bewusst nicht unterstützt.
 */
final class ListShortcode {

	public const TAG          = 'propstack_list';
	public const STYLE_HANDLE = 'propstack-lite-list';

	private const DEFAULTS = [
		'per'            => '12',
		'limit'          => '',
		'page'           => '1',
		'marketing_type' => '',
		'rs_type'        => '',
		'city'           => '',
		'zip_code'       => '',
		'price_from'     => '',
		'price_to'       => '',
		'min_price'      => '',
		'max_price'      => '',
		'sort_by'        => 'created_at',
		'order'          => 'desc',
		'heading'        => 'h3',
	];

	public function __construct(
		private PropertyStore $store,
		private Settings $settings,
		private TemplateLoader $templates,
		private UrlGenerator $urls
	) {}

	public function register(): void {
		add_shortcode( self::TAG, [ $this, 'render' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'registerAssets' ] );
	}

	public function registerAssets(): void {
		wp_register_style( self::STYLE_HANDLE, PSL_URL . 'assets/css/psl-list.css', [], PSL_VERSION );
	}

	public function render( mixed $atts ): string {
		$a = shortcode_atts( self::DEFAULTS, is_array( $atts ) ? $atts : [], self::TAG );

		$criteria = ListCriteria::fromInput(
			[
				'marketing_type' => $a['marketing_type'],
				'rs_type'        => $a['rs_type'],
				'city'           => $a['city'],
				'zip_code'       => $a['zip_code'],
				'price_from'     => '' !== $a['price_from'] ? $a['price_from'] : $a['min_price'],
				'price_to'       => '' !== $a['price_to'] ? $a['price_to'] : $a['max_price'],
				'sort_by'        => $a['sort_by'],
				'order'          => $a['order'],
				'per_page'       => '' !== $a['limit'] ? $a['limit'] : $a['per'],
				'page'           => $a['page'],
			]
		);

		try {
			$result = $this->store->queryPublic( $this->settings->publicStatusIds(), $criteria );
		} catch ( \Throwable ) {
			return $this->message( 'Die Immobilien können derzeit nicht geladen werden. Bitte versuchen Sie es später erneut.' );
		}

		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			$this->registerAssets(); // Rendering außerhalb von wp_enqueue_scripts (REST, AJAX, Builder-Vorschau)
		}
		wp_enqueue_style( self::STYLE_HANDLE );

		if ( [] === $result['items'] ) {
			return $this->message( 'Derzeit sind keine passenden Immobilien verfügbar.' );
		}

		$reserved = $this->settings->reservedStatusIds();
		$heading  = in_array( $a['heading'], [ 'h2', 'h3', 'h4' ], true ) ? $a['heading'] : 'h3';
		$cards    = array_map( fn ( Property $p ) => $this->card( $p, $reserved ), $result['items'] );

		return $this->templates->render(
			'list.php',
			[
				'cards'   => $cards,
				'total'   => $result['total'],
				'heading' => $heading,
			]
		);
	}

	/** View-Daten einer Karte (unescaped; Escaping im Template). */
	private function card( Property $p, array $reservedStatusIds ): array {
		$image = $p->mainImage();
		$area  = $p->mainArea();
		$title = Formatter::title( $p );

		return [
			'id'        => $p->id,
			'title'     => $title,
			'url'       => $this->urls->detailUrl( $p ),
			'image'     => $image?->src( 'medium' ),
			'imageAlt'  => $image?->title ?? $title,
			'location'  => $p->address->publicLabel(),
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
