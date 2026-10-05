<?php

namespace PropstackLite\Tests\Http;

/** Yoast SEO aktiv (Rank Math inaktiv): Yoast gibt aus, Werte kommen vom Plugin über Yoast-Filter. */
final class SeoYoastTest extends SeoHttpTestCase {

	protected static function mode(): string {
		return 'yoast';
	}

	public function test_active_head_via_yoast_without_duplicates(): void {
		$this->assertActiveHead();
		$head = self::head( $this->get( $this->url( self::ID_ACTIVE ) )['body'] );
		$this->assertStringContainsString( 'Yoast SEO plugin', $head );
		$this->assertStringNotContainsString( 'Propstack Listings Lite SEO', $head, 'kein eigener Core-Output' );
		$this->assertStringContainsString( 'yoast-schema-graph', $head, 'Schema im Yoast-Graph' );
	}

	public function test_states(): void {
		$this->assertReservedIndexable();
		$this->assertSoldNoindex( false ); // Yoast gibt bei noindex grundsätzlich keinen Canonical aus
		$this->assertGoneHead();
		$this->assert404Normal();
		$this->assertLegacyStill301();
	}

	public function test_canonical_ignores_campaign_params(): void {
		$this->assertCanonicalIgnoresCampaignParams();
	}

	public function test_price_on_request_and_no_image(): void {
		$this->assertPriceOnRequestHasNoOffer();
	}

	public function test_head_is_xss_safe(): void {
		$this->assertHeadXssSafe();
	}

	public function test_yoast_sitemap(): void {
		$index = array_column( $this->sitemapUrls( $this->base . '/sitemap_index.xml' ), 'loc' );
		$this->assertContains( $this->base . '/propstack-sitemap.xml', $index );
		$this->assertSitemapContent( $this->sitemapUrls( $this->base . '/propstack-sitemap.xml' ) );
		$this->assertSame( 404, $this->get( $this->base . '/wp-sitemap.xml' )['status'], 'Core-Sitemaps sind unter Yoast deaktiviert' );
	}

	public function test_yoast_sitemap_pagination(): void {
		update_option( 'psl_test_sitemap_max_urls', 3 );
		$index = array_column( $this->sitemapUrls( $this->base . '/sitemap_index.xml' ), 'loc' );
		$this->assertContains( $this->base . '/propstack-sitemap.xml', $index, 'Yoast: Seite 1 ohne Nummer' );
		$this->assertContains( $this->base . '/propstack-sitemap2.xml', $index );
		$this->assertNotContains( $this->base . '/propstack-sitemap3.xml', $index );
		$page1 = $this->sitemapUrls( $this->base . '/propstack-sitemap.xml' );
		$page2 = $this->sitemapUrls( $this->base . '/propstack-sitemap2.xml' );
		$this->assertCount( 3, $page1 );
		$this->assertCount( 1, $page2 );
		$this->assertSitemapContent( array_merge( $page1, $page2 ) );
	}

	public function test_no_propstack_requests(): void {
		$this->assertNoPropstackRequests( [ $this->base . '/sitemap_index.xml', $this->base . '/propstack-sitemap.xml' ] );
	}
}
