<?php

namespace PropstackLite\Seo;

use PropstackLite\Frontend\DetailController;
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

	public function __construct( private UrlGenerator $urls ) {}

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
