<?php

namespace PropstackLite\Tests\Http;

/** Core-Modus (kein SEO-Plugin aktiv): Das Plugin gibt alle Head-Tags selbst aus. */
final class SeoCoreTest extends SeoHttpTestCase {

	protected static function mode(): string {
		return 'core';
	}

	public function test_active_head_without_duplicates(): void {
		$this->assertActiveHead();
		$head = self::head( $this->get( $this->url( self::ID_ACTIVE ) )['body'] );
		$this->assertStringContainsString( '<!-- Propstack Listings Lite SEO -->', $head );
		$this->assertMatchesRegularExpression( "#<meta name='robots' content='[^']*index, follow#", $head );
	}

	public function test_states(): void {
		$this->assertReservedIndexable();
		$this->assertSoldNoindex();
		$this->assertGoneHead();
		$this->assert404Normal();
		$this->assertLegacyStill301();
	}

	public function test_gone_title(): void {
		$head = self::head( $this->get( $this->url( self::ID_REMOVED ) )['body'] );
		$this->assertSame( 'Immobilie nicht mehr verfügbar | ' . wp_strip_all_tags( (string) get_bloginfo( 'name' ) ), self::title( $head ) );
		$this->assertSame( 0, self::tagCounts( $head )['description'] );
		$this->assertSame( 0, self::tagCounts( $head )['jsonld'] );
	}

	public function test_canonical_ignores_campaign_params(): void {
		$this->assertCanonicalIgnoresCampaignParams();
	}

	public function test_price_on_request_and_no_image(): void {
		$this->assertPriceOnRequestHasNoOffer();
		$head = self::head( $this->get( $this->url( self::ID_ON_REQUEST ) )['body'] );
		$this->assertSame( 'summary', self::meta( $head, 'name', 'twitter:card' ) );
	}

	public function test_head_is_xss_safe(): void {
		$this->assertHeadXssSafe();
		$head = self::head( $this->get( $this->url( self::ID_XSS ) )['body'] );
		// Ob „2-Zimmer-“ noch in die Titellänge passt, hängt von der Länge des Seitennamens ab (Kürzungsregel).
		$brand = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$this->assertMatchesRegularExpression( '#^(2-Zimmer-)?Wohnung kaufen in Ort alert\(1\) \| ' . preg_quote( $brand, '#' ) . '$#', (string) self::title( $head ), 'Markup wird zu Klartext' );
		$this->assertStringContainsString( '\u0022quote\u0027', $head, 'JSON-LD escaped Anführungszeichen (JSON_HEX_QUOT/APOS)' );
	}

	public function test_core_sitemap_index_and_content(): void {
		$index = $this->sitemapUrls( $this->base . '/wp-sitemap.xml' );
		$locs  = array_column( $index, 'loc' );
		$this->assertContains( $this->base . '/wp-sitemap-propstack-1.xml', $locs );

		$this->assertSitemapContent( $this->sitemapUrls( $this->base . '/wp-sitemap-propstack-1.xml' ) );
	}

	public function test_core_sitemap_pagination(): void {
		update_option( 'psl_test_sitemap_max_urls', 3 );
		$locs = array_column( $this->sitemapUrls( $this->base . '/wp-sitemap.xml' ), 'loc' );
		$this->assertContains( $this->base . '/wp-sitemap-propstack-2.xml', $locs );
		$this->assertNotContains( $this->base . '/wp-sitemap-propstack-3.xml', $locs );
		$page1 = $this->sitemapUrls( $this->base . '/wp-sitemap-propstack-1.xml' );
		$page2 = $this->sitemapUrls( $this->base . '/wp-sitemap-propstack-2.xml' );
		$this->assertCount( 3, $page1 );
		$this->assertCount( 1, $page2 );
		$this->assertSitemapContent( array_merge( $page1, $page2 ) );
		$this->assertSame( 404, $this->get( $this->base . '/wp-sitemap-propstack-3.xml' )['status'] );
	}

	public function test_no_propstack_requests(): void {
		$this->assertNoPropstackRequests( [ $this->base . '/wp-sitemap.xml', $this->base . '/wp-sitemap-propstack-1.xml' ] );
	}
}
