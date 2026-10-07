<?php

namespace PropstackLite\Seo;

use PropstackLite\Seo\Sitemap\CoreSitemapProvider;
use PropstackLite\Seo\Sitemap\RankMathSitemapProvider;
use PropstackLite\Seo\Sitemap\SitemapCache;
use PropstackLite\Seo\Sitemap\SitemapSource;
use PropstackLite\Seo\Sitemap\YoastSitemapProvider;

/**
 * Registriert genau einen Head-Adapter (Yoast > Rank Math > Core) und die Sitemap-Provider.
 * Sitemaps: Core-Provider immer (greift nur, wenn WordPress-Sitemaps aktiv sind), Yoast/Rank Math
 * zusätzlich über deren Extension Points, wenn das jeweilige Plugin aktiv ist.
 */
final class SeoIntegration {

	public function __construct(
		private SeoContext $context,
		private SitemapSource $sitemap
	) {}

	public function register(): void {
		match ( SeoPlugins::mode() ) {
			SeoPlugins::YOAST    => ( new YoastAdapter( $this->context ) )->register(),
			SeoPlugins::RANKMATH => ( new RankMathAdapter( $this->context ) )->register(),
			default              => ( new CoreAdapter( $this->context ) )->register(),
		};

		// Sicherheitsnetz bei mehreren SEO-Plugins: Das nicht integrierte Plugin erhält keine Werte,
		// darf aber auf noindex-Seiten (Verkauft-Phase, 410) nie „index“ ausgeben.
		if ( SeoPlugins::YOAST === SeoPlugins::mode() && in_array( SeoPlugins::RANKMATH, SeoPlugins::active(), true ) ) {
			add_filter( 'rank_math/frontend/robots', [ $this, 'enforceNoindex' ], 99 );
			// Übersicht ist eine normale Seite: Rank Math gäbe dort sonst den Seiten-Permalink als
			// zweiten, widersprüchlichen Canonical aus (z. B. auf Seite 2).
			add_filter( 'rank_math/frontend/canonical', [ $this, 'enforceListingCanonical' ], 99 );
		}

		add_action( 'template_redirect', [ $this, 'listingHeaders' ], 5 );

		add_action( 'wp_sitemaps_init', [ $this, 'registerCoreSitemap' ] );
		add_filter( 'wpseo_sitemaps_providers', [ $this, 'yoastProviders' ] );
		add_filter( 'rank_math/sitemap/providers', [ $this, 'rankMathProviders' ] );
		( new SitemapCache() )->register();
	}

	/**
	 * Gefilterte/sortierte Übersicht: noindex zusätzlich als HTTP-Header – wirkt auch mit SEO-Plugins,
	 * die das Plugin nicht kennt (wie bei Detailseiten in der Verkauft-Phase).
	 */
	public function listingHeaders(): void {
		$listing = $this->context->listing();
		if ( null !== $listing && ! $listing->isIndexable() && ! headers_sent() ) {
			header( 'X-Robots-Tag: ' . $listing->robotsString() );
		}
	}

	public function enforceListingCanonical( mixed $canonical ): mixed {
		return $this->context->listing()?->canonical ?? $canonical;
	}

	public function enforceNoindex( mixed $robots ): mixed {
		$data = $this->context->data() ?? $this->context->listing();
		if ( null === $data || $data->isIndexable() ) {
			return $robots;
		}
		return ( new RankMathAdapter( $this->context ) )->robots( $robots );
	}

	public function registerCoreSitemap(): void {
		wp_register_sitemap_provider( SitemapSource::TYPE, new CoreSitemapProvider( $this->sitemap ) );
	}

	public function yoastProviders( mixed $providers ): mixed {
		if ( is_array( $providers ) && interface_exists( 'WPSEO_Sitemap_Provider' ) ) {
			$providers[] = new YoastSitemapProvider( $this->sitemap );
		}
		return $providers;
	}

	public function rankMathProviders( mixed $providers ): mixed {
		if ( is_array( $providers ) && interface_exists( '\RankMath\Sitemap\Providers\Provider' ) ) {
			$providers[] = new RankMathSitemapProvider( $this->sitemap );
		}
		return $providers;
	}
}
