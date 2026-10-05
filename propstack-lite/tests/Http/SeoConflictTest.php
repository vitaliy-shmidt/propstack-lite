<?php

namespace PropstackLite\Tests\Http;

use PropstackLite\Admin\Notices;
use PropstackLite\Plugin;
use PropstackLite\Seo\SeoPlugins;

/**
 * Yoast UND Rank Math aktiv (Fehlkonfiguration der Website): Das Plugin integriert nur Yoast
 * (Priorität Yoast > Rank Math > Core), gibt nichts selbst aus und zeigt einen Admin-Hinweis.
 * Doppelte Tags der beiden SEO-Plugins untereinander liegen außerhalb des Plugins.
 */
final class SeoConflictTest extends SeoHttpTestCase {

	protected static function mode(): string {
		return 'conflict';
	}

	public function test_mode_is_yoast(): void {
		$this->assertSame( [ SeoPlugins::YOAST, SeoPlugins::RANKMATH ], SeoPlugins::active() );
		$this->assertSame( SeoPlugins::YOAST, SeoPlugins::mode() );
	}

	public function test_only_yoast_receives_property_values(): void {
		$canonical = $this->url( self::ID_ACTIVE );
		$head      = self::head( $this->get( $canonical )['body'] );
		$this->assertStringNotContainsString( 'Propstack Listings Lite SEO', $head, 'kein Core-Output' );

		$nodes = self::schemaNodes( $head );
		$this->assertCount( 1, self::nodesOfType( $nodes, 'RealEstateListing' ), 'Listing nur einmal (im Yoast-Graph)' );
		$this->assertCount( 1, self::nodesOfType( $nodes, 'RealEstateAgent' ) );

		preg_match( '#<script[^>]+yoast-schema-graph[^>]*>(.*?)</script>#s', $head, $yoast );
		$this->assertStringContainsString( 'RealEstateListing', $yoast[1] ?? '', 'Werte gehen an Yoast' );
		if ( preg_match( '#<script[^>]+rank-math-schema[^>]*>(.*?)</script>#s', $head, $rm ) ) {
			$this->assertStringNotContainsString( 'RealEstateListing', $rm[1], 'Rank Math wird nicht integriert' );
		}

		$brand = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$this->assertStringContainsString( '<title>3-Zimmer-Wohnung kaufen in Berlin-Prenzlauer Berg | ' . $brand . '</title>', $head );
		$this->assertStringContainsString( 'href="' . $canonical . '"', $head );
	}

	public function test_admin_notice_names_integrated_plugin(): void {
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		$this->assertNotEmpty( $admins, 'Testinstanz benötigt einen Administrator' );
		$previous = get_current_user_id();
		wp_set_current_user( (int) $admins[0] );
		try {
			ob_start();
			( new Notices( Plugin::instance()->settings() ) )->render();
			$html = (string) ob_get_clean();
		} finally {
			wp_set_current_user( $previous );
		}
		$this->assertStringContainsString( 'Mehrere SEO-Plugins aktiv. Für Propstack-Detailseiten wird nur Yoast SEO integriert.', $html );
	}

	/** Sicherheitsnetz: Jede Robots-Angabe (auch des nicht integrierten Plugins) sagt noindex. */
	public function test_noindex_pages_never_say_index(): void {
		foreach ( [ self::ID_REMOVED => 410, self::ID_SOLD => 200 ] as $id => $status ) {
			$r    = $this->get( $this->url( $id ) );
			$head = self::head( $r['body'] );
			$this->assertSame( $status, $r['status'] );
			$this->assertSame( 'noindex, follow', $r['headers']['x-robots-tag'] ?? null );
			preg_match_all( '#<meta\s+name=["\']robots["\']\s+content=["\']([^"\']*)["\']#i', $head, $m );
			$this->assertNotEmpty( $m[1] );
			foreach ( $m[1] as $content ) {
				$this->assertStringContainsString( 'noindex', $content, "Objekt {$id}: {$content}" );
			}
		}
		$this->assertStringNotContainsString( 'RealEstateListing', self::head( $this->get( $this->url( self::ID_REMOVED ) )['body'] ) );
	}

	public function test_no_propstack_requests(): void {
		$this->assertNoPropstackRequests();
	}
}
