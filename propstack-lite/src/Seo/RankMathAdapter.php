<?php

namespace PropstackLite\Seo;

/**
 * Rank Math aktiv: Rank Math gibt alle Tags aus, das Plugin liefert die Werte über offizielle Filter
 * (Präfix `rank_math/`). Rank Math entfernt WordPress' eigenen Title-/Canonical-/Robots-Output selbst.
 *
 * - `frontend/title`, `frontend/description`, `frontend/robots`, `frontend/canonical`
 * - `opengraph/facebook/og_title|og_description|og_locale`, `opengraph/url`, `opengraph/type`
 * - `opengraph/{facebook|twitter}/add_images` (Action), `opengraph/twitter/card_type`,
 *   `opengraph/twitter/twitter_title|twitter_description`
 * - `json_ld`: RealEstateListing, Place/Apartment/House, RealEstateAgent, ggf. BreadcrumbList
 * - Immobilienübersicht (Phase 7): nur `frontend/canonical`, `frontend/robots`, `frontend/title` (Seitenzusatz)
 * - `frontend/breadcrumb/items`: Pfad Startseite → Immobilien → Ort → Objekt (sonst nur „Home“)
 */
final class RankMathAdapter {

	public function __construct( private SeoContext $context ) {}

	public function register(): void {
		add_filter( 'rank_math/frontend/title', [ $this, 'title' ], 20 );
		add_filter( 'rank_math/frontend/description', [ $this, 'description' ], 20 );
		add_filter( 'rank_math/frontend/robots', [ $this, 'robots' ], 20 );
		add_filter( 'rank_math/frontend/canonical', [ $this, 'canonical' ], 20 );
		add_filter( 'rank_math/opengraph/facebook/og_title', [ $this, 'ogTitle' ], 20 );
		add_filter( 'rank_math/opengraph/facebook/og_description', [ $this, 'ogDescription' ], 20 );
		add_filter( 'rank_math/opengraph/facebook/og_locale', [ $this, 'ogLocale' ], 20 );
		add_filter( 'rank_math/opengraph/url', [ $this, 'ogUrl' ], 20 );
		add_filter( 'rank_math/opengraph/type', [ $this, 'ogType' ], 20 );
		add_action( 'rank_math/opengraph/facebook/add_images', [ $this, 'addImages' ], 20 );
		add_action( 'rank_math/opengraph/twitter/add_images', [ $this, 'addImages' ], 20 );
		add_filter( 'rank_math/opengraph/twitter/card_type', [ $this, 'twitterCard' ], 20 );
		add_filter( 'rank_math/opengraph/twitter/twitter_title', [ $this, 'twitterTitle' ], 20 );
		add_filter( 'rank_math/opengraph/twitter/twitter_description', [ $this, 'twitterDescription' ], 20 );
		add_filter( 'rank_math/json_ld', [ $this, 'jsonLd' ], 99, 2 );
		add_filter( 'rank_math/frontend/breadcrumb/items', [ $this, 'breadcrumbs' ], 20 );
	}

	/**
	 * Rank Math kennt die Route nicht und liefert nur „Home“: Pfad vollständig aus dem SeoService
	 * übernehmen (Format: [ Name, URL, 'hide_in_schema' => bool ]).
	 */
	public function breadcrumbs( mixed $crumbs ): mixed {
		$data = $this->context->data();
		if ( null === $data || ! is_array( $crumbs ) ) {
			return $crumbs;
		}
		$trail = null;
		foreach ( $data->schemaNodes() as $node ) {
			if ( 'BreadcrumbList' === $node['@type'] ) {
				$trail = $node['itemListElement'];
			}
		}
		if ( null === $trail ) {
			return $crumbs;
		}
		$out = [];
		foreach ( $trail as $item ) {
			$out[] = [ (string) $item['name'], (string) ( $item['item'] ?? '' ), 'hide_in_schema' => false ];
		}
		return $out;
	}

	public function title( mixed $title ): mixed {
		$data = $this->context->data();
		if ( null === $data ) {
			$listing = $this->context->listing();
			return null !== $listing && is_string( $title ) ? $listing->title( $title ) : $title;
		}
		return $data->title;
	}

	public function description( mixed $description ): mixed {
		$data = $this->context->data();
		return null === $data ? $description : (string) $data->description;
	}

	/** Rank-Math-Format: [ 'index' => 'index', 'follow' => 'follow', … ]. */
	public function robots( mixed $robots ): mixed {
		$data = $this->context->data() ?? $this->listingNoindex();
		if ( null === $data ) {
			return $robots;
		}
		$robots = is_array( $robots ) ? $robots : [];
		unset( $robots['index'], $robots['noindex'], $robots['follow'], $robots['nofollow'] );
		$index  = $data->robots['index'] ? 'index' : 'noindex';
		$follow = $data->robots['follow'] ? 'follow' : 'nofollow';
		return [ $index => $index, $follow => $follow ] + $robots;
	}

	/** 410: leer → Rank Math gibt keinen Canonical aus. */
	public function canonical( mixed $canonical ): mixed {
		$data = $this->context->data();
		if ( null === $data ) {
			return $this->context->listing()?->canonical ?? $canonical; // Übersicht: selbstreferenzierend
		}
		return (string) $data->canonical;
	}

	public function ogTitle( mixed $value ): mixed {
		$data = $this->context->data();
		return null === $data ? $value : ( $data->openGraph['title'] ?? $data->title );
	}

	public function ogDescription( mixed $value ): mixed {
		$data = $this->context->data();
		return null === $data ? $value : ( $data->openGraph['description'] ?? (string) $data->description );
	}

	public function ogLocale( mixed $value ): mixed {
		$data = $this->context->data();
		return $data?->openGraph['locale'] ?? $value;
	}

	public function ogUrl( mixed $value ): mixed {
		$data = $this->context->data();
		return null === $data ? $value : (string) $data->canonical;
	}

	public function ogType( mixed $value ): mixed {
		$data = $this->context->data();
		return $data?->openGraph['type'] ?? $value;
	}

	/** @param object $image RankMath\OpenGraph\Image */
	public function addImages( mixed $image ): void {
		$data = $this->context->data();
		$url  = $data?->openGraph['image'] ?? null;
		if ( null !== $url && is_object( $image ) && method_exists( $image, 'add_image' ) ) {
			$image->add_image( [ 'url' => $url, 'alt' => $data->openGraph['image:alt'] ?? '' ] );
		}
	}

	public function twitterCard( mixed $value ): mixed {
		$data = $this->context->data();
		return $data?->twitter['card'] ?? $value;
	}

	public function twitterTitle( mixed $value ): mixed {
		$data = $this->context->data();
		return $data?->twitter['title'] ?? $value;
	}

	public function twitterDescription( mixed $value ): mixed {
		$data = $this->context->data();
		return $data?->twitter['description'] ?? $value;
	}

	/** Eigene Knoten ergänzen (Rank Math sammelt Entitäten in einem assoziativen Array). */
	public function jsonLd( mixed $entities, mixed $jsonld = null ): mixed {
		$data = $this->context->data();
		if ( null === $data || ! is_array( $entities ) ) {
			return $entities;
		}
		$breadcrumbId = null;
		$webPageId    = null;
		foreach ( $entities as $entity ) {
			$types = is_array( $entity ) ? (array) ( $entity['@type'] ?? [] ) : [];
			if ( in_array( 'BreadcrumbList', $types, true ) ) {
				$breadcrumbId = $entity['@id'] ?? null;
			}
			if ( in_array( 'WebPage', $types, true ) ) {
				$webPageId = $entity['@id'] ?? null;
			}
		}
		foreach ( $data->schemaNodes() as $node ) {
			if ( 'BreadcrumbList' === $node['@type'] && null !== $breadcrumbId ) {
				continue; // Rank Maths Breadcrumbs (falls aktiviert) bleiben die einzigen
			}
			if ( 'RealEstateListing' === $node['@type'] ) {
				if ( null !== $breadcrumbId ) {
					$node['breadcrumb'] = [ '@id' => $breadcrumbId ];
				}
				if ( null !== $webPageId ) {
					$node['mainEntityOfPage'] = [ '@id' => $webPageId ];
				}
			}
			$entities[ 'psl' . $node['@type'] ] = $node;
		}
		return $entities;
	}

	/**
	 * Übersicht: nur herabstufen (noindex bei Filtern/Sortierung/ungültiger Seite), nie eine
	 * redaktionelle noindex-Einstellung der Seite oder „Suchmaschinen abhalten“ auf index heben.
	 */
	private function listingNoindex(): ?ListingSeoData {
		$listing = $this->context->listing();
		return null !== $listing && ! $listing->isIndexable() ? $listing : null;
	}
}
