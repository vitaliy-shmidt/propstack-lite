<?php

namespace PropstackLite\Seo\Sitemap;

/**
 * Yoast-Sitemap: /propstack-sitemap.xml (bzw. propstack-sitemap2.xml …), registriert über
 * `wpseo_sitemaps_providers`. Datei wird nur geladen, wenn Yoasts Interface existiert.
 */
final class YoastSitemapProvider implements \WPSEO_Sitemap_Provider {

	public function __construct( private SitemapSource $source ) {}

	public function handles_type( $type ) {
		return SitemapSource::TYPE === $type;
	}

	public function get_index_links( $max_entries ) {
		$pages = $this->source->maxPages( (int) $max_entries );
		$links = [];
		for ( $i = 1; $i <= $pages; $i++ ) {
			$links[] = [
				'loc'     => \WPSEO_Sitemaps_Router::get_base_url( SitemapSource::TYPE . '-sitemap' . ( 1 === $i ? '' : $i ) . '.xml' ), // Yoast: Seite 1 ohne Nummer (…sitemap1.xml leitet um)
				'lastmod' => (string) $this->source->lastModified(),
			];
		}
		return $links;
	}

	public function get_sitemap_links( $type, $max_entries, $current_page ) {
		$links = [];
		foreach ( $this->source->entries( (int) $current_page, (int) $max_entries ) as $entry ) {
			$links[] = [ 'loc' => $entry['loc'], 'mod' => $entry['lastmod'] ];
		}
		return $links;
	}
}
