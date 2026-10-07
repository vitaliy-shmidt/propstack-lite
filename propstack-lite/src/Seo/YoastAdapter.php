<?php

namespace PropstackLite\Seo;

/**
 * Yoast SEO aktiv: Yoast gibt alle Tags aus, das Plugin liefert nur die Werte über offizielle Filter.
 * Kein eigener Canonical/Title/OG/Robots-Output. Yoast erkennt die Route als Seitentyp „Fallback“.
 *
 * - `wpseo_frontend_presentation`: canonical/permalink der Presentation auf die kanonische URL setzen,
 *   damit Yoasts Schema-IDs (WebPage, Breadcrumb) und og:url konsistent sind; OG-Bilder exakt setzen
 *   (Objektbild oder – ohne öffentliches Bild – keines, auch nicht Yoasts Website-Standardbild)
 * - `wpseo_title`, `wpseo_metadesc`, `wpseo_canonical`, `wpseo_robots_array`
 * - `wpseo_opengraph_*`, `wpseo_twitter_*`
 * - Immobilienübersicht (Phase 7): nur `wpseo_canonical`, `wpseo_robots_array`, `wpseo_title` (Seitenzusatz)
 * - `wpseo_schema_graph`: RealEstateListing, Place/Apartment/House, RealEstateAgent ergänzen
 *   (Yoasts eigene WebPage/WebSite/BreadcrumbList bleiben – keine zweite BreadcrumbList)
 */
final class YoastAdapter {

	public function __construct( private SeoContext $context ) {}

	public function register(): void {
		add_filter( 'wpseo_frontend_presentation', [ $this, 'presentation' ], 20, 2 );
		add_filter( 'wpseo_title', [ $this, 'title' ], 20 );
		add_filter( 'wpseo_metadesc', [ $this, 'description' ], 20 );
		add_filter( 'wpseo_canonical', [ $this, 'canonical' ], 20 );
		add_filter( 'wpseo_robots_array', [ $this, 'robots' ], 20 );
		add_filter( 'wpseo_opengraph_title', [ $this, 'ogTitle' ], 20 );
		add_filter( 'wpseo_opengraph_desc', [ $this, 'ogDescription' ], 20 );
		add_filter( 'wpseo_opengraph_url', [ $this, 'ogUrl' ], 20 );
		add_filter( 'wpseo_opengraph_type', [ $this, 'ogType' ], 20 );
		add_filter( 'wpseo_og_locale', [ $this, 'ogLocale' ], 20 );
		add_filter( 'wpseo_twitter_card_type', [ $this, 'twitterCard' ], 20 );
		add_filter( 'wpseo_twitter_title', [ $this, 'twitterTitle' ], 20 );
		add_filter( 'wpseo_twitter_description', [ $this, 'twitterDescription' ], 20 );
		add_filter( 'wpseo_twitter_image', [ $this, 'twitterImage' ], 20 );
		add_filter( 'wpseo_schema_graph', [ $this, 'schemaGraph' ], 20, 2 );
	}

	public function presentation( mixed $presentation, mixed $context = null ): mixed {
		$data = $this->context->data();
		if ( null !== $data && is_object( $presentation ) && null !== $data->canonical ) {
			$presentation->canonical = $data->canonical;
			$presentation->permalink = $data->canonical;
		}
		if ( null !== $data && is_object( $presentation ) && null !== $data->openGraph ) {
			$url = $data->openGraph['image'] ?? null;
			$presentation->open_graph_images = null === $url ? [] : [ $url => [ 'url' => $url, 'alt' => $data->openGraph['image:alt'] ?? '' ] ];
			if ( null === $url ) {
				$presentation->twitter_image = '';
			}
		}
		return $presentation;
	}

	public function title( mixed $title ): mixed {
		$data = $this->context->data();
		if ( null === $data ) {
			$listing = $this->context->listing();
			return null !== $listing && is_string( $title ) ? $listing->title( $title ) : $title;
		}
		return $data->title; // Yoast escaped selbst
	}

	public function description( mixed $description ): mixed {
		$data = $this->context->data();
		return null === $data ? $description : (string) $data->description;
	}

	/** 410: leerer Canonical → Yoast gibt keinen aus. */
	public function canonical( mixed $canonical ): mixed {
		$data = $this->context->data();
		if ( null === $data ) {
			return $this->context->listing()?->canonical ?? $canonical; // Übersicht: selbstreferenzierend
		}
		return (string) $data->canonical;
	}

	public function robots( mixed $robots ): mixed {
		$data = $this->context->data() ?? $this->listingNoindex();
		if ( null === $data || ! is_array( $robots ) ) {
			return $robots;
		}
		$robots['index']  = $data->robots['index'] ? 'index' : 'noindex';
		$robots['follow'] = $data->robots['follow'] ? 'follow' : 'nofollow';
		if ( ! $data->robots['index'] ) {
			unset( $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );
		}
		return $robots;
	}

	public function ogTitle( mixed $value ): mixed {
		return $this->og( 'title', $value );
	}

	public function ogDescription( mixed $value ): mixed {
		return $this->og( 'description', $value );
	}

	public function ogUrl( mixed $value ): mixed {
		$data = $this->context->data();
		return null === $data ? $value : (string) $data->canonical;
	}

	public function ogType( mixed $value ): mixed {
		return $this->og( 'type', $value );
	}

	public function ogLocale( mixed $value ): mixed {
		return $this->og( 'locale', $value );
	}

	public function twitterCard( mixed $value ): mixed {
		return $this->tw( 'card', $value );
	}

	public function twitterTitle( mixed $value ): mixed {
		return $this->tw( 'title', $value );
	}

	public function twitterDescription( mixed $value ): mixed {
		return $this->tw( 'description', $value );
	}

	public function twitterImage( mixed $value ): mixed {
		return $this->tw( 'image', $value );
	}

	/**
	 * Eigene Knoten ergänzen. Hat Yoast bereits eine BreadcrumbList bzw. WebPage (je nach Yoast-Version
	 * und Seitentyp; für die Route meist „Fallback“ ohne beide), wird darauf verwiesen statt dupliziert.
	 */
	public function schemaGraph( mixed $graph, mixed $context = null ): mixed {
		$data = $this->context->data();
		if ( null === $data || ! is_array( $graph ) ) {
			return $graph;
		}
		$breadcrumbId = null;
		$webPageId    = null;
		foreach ( $graph as $piece ) {
			$types = is_array( $piece ) ? (array) ( $piece['@type'] ?? [] ) : [];
			if ( in_array( 'BreadcrumbList', $types, true ) ) {
				$breadcrumbId = $piece['@id'] ?? null;
			}
			if ( in_array( 'WebPage', $types, true ) ) {
				$webPageId = $piece['@id'] ?? null;
			}
		}
		foreach ( $data->schemaNodes() as $node ) {
			if ( 'BreadcrumbList' === $node['@type'] && null !== $breadcrumbId ) {
				continue;
			}
			if ( 'RealEstateListing' === $node['@type'] ) {
				if ( null !== $breadcrumbId ) {
					$node['breadcrumb'] = [ '@id' => $breadcrumbId ];
				}
				if ( null !== $webPageId ) {
					$node['mainEntityOfPage'] = [ '@id' => $webPageId ];
				}
			}
			$graph[] = $node;
		}
		return $graph;
	}

	private function og( string $key, mixed $fallback ): mixed {
		$data = $this->context->data();
		if ( null === $data ) {
			return $fallback;
		}
		return $data->openGraph[ $key ] ?? ( in_array( $key, [ 'title', 'description' ], true ) ? (string) ( 'title' === $key ? $data->title : $data->description ) : $fallback );
	}

	private function tw( string $key, mixed $fallback ): mixed {
		$data = $this->context->data();
		if ( null === $data || null === $data->twitter ) {
			return $fallback;
		}
		return $data->twitter[ $key ] ?? $fallback;
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
