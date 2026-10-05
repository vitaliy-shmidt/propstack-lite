<?php

namespace PropstackLite\Seo\Sitemap;

/**
 * Invalidiert gecachte Sitemaps der SEO-Plugins, wenn sich der Bestand ändert (Sync) oder die
 * Sichtbarkeits-Einstellungen geändert werden. WordPress-Core-Sitemaps cachen nicht.
 *
 * - Rank Math: Datei-/Transient-Cache, standardmäßig aktiv → RankMath\Sitemap\Cache::invalidate_storage()
 * - Yoast:     Transient-Cache nur bei aktiviertem `wpseo_enable_xml_sitemap_transient_caching`
 *              → WPSEO_Sitemaps_Cache::clear() (Index wird automatisch mit invalidiert)
 */
final class SitemapCache {

	/** Zähler, deren Änderung die Sitemap beeinflusst. */
	private const RELEVANT = [ 'inserted', 'updated', 'sold', 'removed', 'purged', 'reconciled' ];

	public function register(): void {
		add_action( 'psl_sync_finished', [ $this, 'afterSync' ] );
		add_action( 'update_option_' . \PropstackLite\Settings::OPTION, [ self::class, 'flush' ] );
	}

	public function afterSync( mixed $result ): void {
		if ( ! $result instanceof \PropstackLite\Sync\SyncResult || ! $result->isOk() ) {
			return;
		}
		foreach ( self::RELEVANT as $counter ) {
			if ( ( $result->counts[ $counter ] ?? 0 ) > 0 ) {
				self::flush();
				return;
			}
		}
	}

	public static function flush(): void {
		if ( class_exists( '\RankMath\Sitemap\Cache' ) && method_exists( '\RankMath\Sitemap\Cache', 'invalidate_storage' ) ) {
			\RankMath\Sitemap\Cache::invalidate_storage();
		}
		if ( class_exists( '\WPSEO_Sitemaps_Cache' ) && method_exists( '\WPSEO_Sitemaps_Cache', 'clear' ) ) {
			\WPSEO_Sitemaps_Cache::clear( [ SitemapSource::TYPE ] );
		}
	}
}
