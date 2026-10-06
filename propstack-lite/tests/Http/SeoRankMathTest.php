<?php

namespace PropstackLite\Tests\Http;

/**
 * Rank Math aktiv (Yoast inaktiv): Rank Math gibt aus, Werte kommen über `rank_math/*`-Filter.
 * Voraussetzung: Rank-Math-Registrierung abgeschlossen oder übersprungen (sonst lädt Rank Math kein
 * Frontend und das Plugin bleibt im Core-Modus – siehe test_unregistered_rank_math_falls_back_to_core).
 */
final class SeoRankMathTest extends SeoHttpTestCase {

	protected static function mode(): string {
		return 'rankmath';
	}

	public function test_active_head_via_rank_math_without_duplicates(): void {
		$this->assertActiveHead();
		$head = self::head( $this->get( $this->url( self::ID_ACTIVE ) )['body'] );
		$this->assertStringContainsString( 'Rank Math', $head );
		$this->assertStringNotContainsString( 'Propstack Listings Lite SEO', $head, 'kein eigener Core-Output' );
		$this->assertStringContainsString( 'rank-math-schema', $head );
		$nodes   = self::schemaNodes( $head );
		$listing = self::nodesOfType( $nodes, 'RealEstateListing' )[0];
		$webpage = self::nodesOfType( $nodes, 'WebPage' );
		$this->assertCount( 1, $webpage );
		$this->assertSame( [ '@id' => $webpage[0]['@id'] ], $listing['mainEntityOfPage'] );
		$this->assertSame( $this->url( self::ID_ACTIVE ), $webpage[0]['url'], 'WebPage-URL = Canonical' );
	}

	/** Rank Math liefert für die Route sonst nur „Home“; der Plugin-Pfad wird über frontend/breadcrumb/items ergänzt. */
	public function test_rank_math_breadcrumb_contains_trail(): void {
		$canonical = $this->url( self::ID_ACTIVE );
		$crumbs    = self::nodesOfType( self::schemaNodes( self::head( $this->get( $canonical )['body'] ) ), 'BreadcrumbList' );
		$this->assertCount( 1, $crumbs );
		$ids = array_map( static fn ( $i ) => is_array( $i['item'] ?? null ) ? $i['item']['@id'] : ( $i['item'] ?? null ), $crumbs[0]['itemListElement'] );
		$this->assertContains( $this->base . '/immobilien/', $ids );
		$this->assertSame( $canonical, end( $ids ), 'letzter Eintrag = Objekt' );
	}

	public function test_states(): void {
		$this->assertReservedIndexable();
		$this->assertSoldNoindex( false );
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

	public function test_rank_math_sitemap(): void {
		$index = array_column( $this->sitemapUrls( $this->base . '/sitemap_index.xml' ), 'loc' );
		$this->assertContains( $this->base . '/propstack-sitemap.xml', $index );
		$this->assertSitemapContent( $this->sitemapUrls( $this->base . '/propstack-sitemap.xml' ) );
	}

	public function test_rank_math_sitemap_pagination(): void {
		$options = get_option( 'rank-math-options-sitemap' );
		update_option( 'rank-math-options-sitemap', array_merge( (array) $options, [ 'items_per_page' => 3 ] ) );
		\PropstackLite\Seo\Sitemap\SitemapCache::flush();
		try {
			$index = array_column( $this->sitemapUrls( $this->base . '/sitemap_index.xml' ), 'loc' );
			$this->assertContains( $this->base . '/propstack-sitemap1.xml', $index );
			$this->assertContains( $this->base . '/propstack-sitemap2.xml', $index );
			$page1 = $this->sitemapUrls( $this->base . '/propstack-sitemap1.xml' );
			$page2 = $this->sitemapUrls( $this->base . '/propstack-sitemap2.xml' );
			$this->assertCount( 3, $page1 );
			$this->assertCount( 1, $page2 );
			$this->assertSitemapContent( array_merge( $page1, $page2 ) );
		} finally {
			update_option( 'rank-math-options-sitemap', $options );
			\PropstackLite\Seo\Sitemap\SitemapCache::flush();
		}
	}

	public function test_unregistered_rank_math_falls_back_to_core(): void {
		$skip = get_option( 'rank_math_registration_skip' );
		update_option( 'rank_math_registration_skip', 0 );
		try {
			$head = self::head( $this->get( $this->url( self::ID_ACTIVE ) )['body'] );
			$this->assertStringContainsString( 'Propstack Listings Lite SEO', $head, 'Core-Modus, wenn Rank Math kein Frontend lädt' );
			$counts = self::tagCounts( $head );
			$this->assertSame( 1, $counts['canonical'] );
			$this->assertSame( 1, $counts['title'] );
		} finally {
			update_option( 'rank_math_registration_skip', $skip );
		}
	}

	public function test_no_propstack_requests(): void {
		$this->assertNoPropstackRequests( [ $this->base . '/sitemap_index.xml', $this->base . '/propstack-sitemap.xml' ] );
	}
}
