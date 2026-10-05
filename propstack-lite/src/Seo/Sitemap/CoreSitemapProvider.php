<?php

namespace PropstackLite\Seo\Sitemap;

/**
 * WordPress-Core-Sitemap: /wp-sitemap-propstack-1.xml (paginiert nach wp_sitemaps_get_max_urls()).
 * Nur wirksam, wenn die Core-Sitemaps aktiv sind (Yoast/Rank Math deaktivieren sie).
 */
final class CoreSitemapProvider extends \WP_Sitemaps_Provider {

	public function __construct( private SitemapSource $source ) {
		$this->name        = SitemapSource::TYPE;
		$this->object_type = SitemapSource::TYPE;
	}

	/** @return list<array{loc: string, lastmod?: string}> */
	public function get_url_list( $page_num, $object_subtype = '' ) {
		$list = [];
		foreach ( $this->source->entries( (int) $page_num, wp_sitemaps_get_max_urls( $this->object_type ) ) as $entry ) {
			$item    = [ 'loc' => $entry['loc'] ];
			$lastmod = SitemapSource::w3c( $entry['lastmod'] );
			if ( '' !== $lastmod ) {
				$item['lastmod'] = $lastmod;
			}
			$list[] = $item;
		}
		return $list;
	}

	public function get_max_num_pages( $object_subtype = '' ) {
		return $this->source->maxPages( wp_sitemaps_get_max_urls( $this->object_type ) );
	}
}
