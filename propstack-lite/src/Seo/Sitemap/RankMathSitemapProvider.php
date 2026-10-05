<?php

namespace PropstackLite\Seo\Sitemap;

/**
 * Rank-Math-Sitemap: /propstack-sitemap.xml, registriert über `rank_math/sitemap/providers`.
 * Datei wird nur geladen, wenn Rank Maths Interface existiert.
 */
final class RankMathSitemapProvider implements \RankMath\Sitemap\Providers\Provider {

	public function __construct( private SitemapSource $source ) {}

	public function handles_type( $type ) {
		return SitemapSource::TYPE === $type;
	}

	public function get_index_links( $max_entries ) {
		$pages = $this->source->maxPages( (int) $max_entries );
		$links = [];
		for ( $i = 1; $i <= $pages; $i++ ) {
			$links[] = [
				'loc'     => \RankMath\Sitemap\Router::get_base_url( SitemapSource::TYPE . '-sitemap' . ( $pages > 1 ? $i : '' ) . '.xml' ),
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
