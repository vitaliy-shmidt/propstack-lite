<?php

namespace PropstackLite\Seo;

use PropstackLite\Frontend\DetailController;
use PropstackLite\Frontend\ListingContext;
use PropstackLite\Routing\Router;
use PropstackLite\Routing\UrlGenerator;

/**
 * SEO-Daten des laufenden Requests (einmal berechnet, von allen Adaptern geteilt).
 *
 * - Detailseite 200 → vollständige SeoData
 * - 410             → SeoData::forGone() (noindex, kein Canonical, kein OG/Schema)
 * - 404 / andere    → null (WordPress bzw. SEO-Plugin verhalten sich normal)
 */
final class SeoContext {

	private bool $resolved = false;
	private ?SeoData $data = null;
	private bool $listingResolved = false;
	private ?ListingSeoData $listing = null;

	public function __construct( private UrlGenerator $urls, private ?ListingContext $listingContext = null ) {}

	/**
	 * SEO-Werte einer Immobilienübersicht (Seite mit interaktivem `[propstack_list]`), sonst null.
	 * Gilt nie auf Detailseiten (dort data()).
	 */
	public function listing(): ?ListingSeoData {
		if ( $this->listingResolved ) {
			return $this->listing;
		}
		if ( null === $this->listingContext || ! did_action( 'wp' ) ) {
			return null;
		}
		$this->listingResolved = true;
		if ( Router::isPropertyRequest() ) {
			return null;
		}
		$request = $this->listingContext->request();
		$pageUrl = $this->listingContext->pageUrl();
		$result  = $this->listingContext->result();
		if ( null === $request || null === $pageUrl || null === $result ) {
			return null;
		}
		$data          = self::service()->forListing(
			$this->urls->listingUrl( $pageUrl, $request->queryArgs() ),
			$request->page,
			$request->isCustomized(),
			$result->isOutOfRange()
		);
		$filtered      = apply_filters( 'psl_listing_seo_data', $data, $request );
		$this->listing = $filtered instanceof ListingSeoData ? $filtered : $data;
		return $this->listing;
	}

	public static function service(): SeoService {
		$brand = (string) apply_filters( 'psl_seo_brand', wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		return new SeoService( $brand, home_url( '/' ), 'de_DE' );
	}

	public function data(): ?SeoData {
		if ( $this->resolved ) {
			return $this->data;
		}
		if ( ! Router::isPropertyRequest() || Router::isLegacyRequest() || null === DetailController::current() ) {
			return null; // vor dem Routing nicht cachen
		}
		$this->resolved = true;

		$decision = DetailController::current()->decision();
		$service  = self::service();
		if ( 410 === $decision->httpStatus ) {
			$data = $service->forGone();
		} elseif ( $decision->isRenderable() && null !== $decision->stored && null !== $decision->stored->property ) {
			$data = $service->forProperty(
				$decision->stored->property,
				$decision,
				$this->urls->canonicalUrl( $decision->stored ),
				$this->urls->overviewUrl()
			);
		} else {
			return $this->data = null;
		}

		$filtered   = apply_filters( 'psl_seo_data', $data, $decision );
		$this->data = $filtered instanceof SeoData ? $filtered : $data;
		return $this->data;
	}
}
