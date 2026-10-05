<?php

namespace PropstackLite\Seo\Sitemap;

use PropstackLite\Routing\UrlGenerator;
use PropstackLite\Settings;
use PropstackLite\Storage\PropertyStore;

/**
 * Datenquelle aller Sitemap-Provider (Core, Yoast, Rank Math): indexierbare Objekte aus dem lokalen
 * Store, URL identisch mit dem Canonical (UrlGenerator). Keine API-Requests.
 */
final class SitemapSource {

	public const TYPE = 'propstack';

	public function __construct(
		private PropertyStore $store,
		private Settings $settings,
		private UrlGenerator $urls
	) {}

	public function count(): int {
		return $this->store->countSitemap( $this->settings->publicStatusIds() );
	}

	public function maxPages( int $perPage ): int {
		$count = $this->count();
		return 0 === $count ? 0 : (int) ceil( $count / max( 1, $perPage ) );
	}

	/** @return list<array{loc: string, lastmod: string}> lastmod als UTC „Y-m-d H:i:s“ */
	public function entries( int $page, int $perPage ): array {
		$page = max( 1, $page );
		$rows = $this->store->sitemapRows( $this->settings->publicStatusIds(), ( $page - 1 ) * $perPage, $perPage );
		$out  = [];
		foreach ( $rows as $row ) {
			$out[] = [ 'loc' => $this->urls->detailUrlFor( $row['id'], $row['slug'] ), 'lastmod' => $row['lastmod'] ];
		}
		return $out;
	}

	public function lastModified(): ?string {
		return $this->store->sitemapLastModified( $this->settings->publicStatusIds() );
	}

	/** UTC-MySQL-Datum → W3C/ISO 8601 („2026-10-06T08:00:00+00:00“). */
	public static function w3c( string $mysqlUtc ): string {
		$ts = strtotime( $mysqlUtc . ' UTC' );
		return false === $ts ? '' : gmdate( 'c', $ts );
	}
}
