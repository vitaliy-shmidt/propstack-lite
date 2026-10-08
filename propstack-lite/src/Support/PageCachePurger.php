<?php

namespace PropstackLite\Support;

use PropstackLite\Settings;
use PropstackLite\Sync\SyncResult;

/**
 * Leert Full-Page-Caches, wenn sich der sichtbare Bestand ändert (Sync mit Änderungen) oder die
 * Sichtbarkeits-Einstellungen gespeichert werden. Sonst blieben z. B. verkaufte Objekte bis zum
 * Cache-Ablauf mit Kontaktformular und `index` in Übersicht, Detailseite und Sitemap.
 *
 * Nur offizielle, öffentlich dokumentierte Funktionen/Actions der jeweiligen Cache-Plugins; jeder
 * Aufruf ist durch function_exists/has_action abgesichert. Eigene Lösungen (Server-Cache, CDN):
 * Action `psl_purge_page_cache`.
 */
final class PageCachePurger {

	/** Zähler, deren Änderung Übersicht, Detailseiten oder Sitemap beeinflusst. */
	public const RELEVANT = [ 'inserted', 'updated', 'sold', 'removed', 'purged', 'reconciled' ];

	public function register(): void {
		add_action( 'psl_sync_finished', [ $this, 'afterSync' ] );
		add_action( 'update_option_' . Settings::OPTION, [ self::class, 'purge' ] );
		add_action( 'add_option_' . Settings::OPTION, [ self::class, 'purge' ] ); // erstes Speichern einer Neuinstallation
	}

	public function afterSync( mixed $result ): void {
		if ( self::hasRelevantChanges( $result ) ) {
			self::purge();
		}
	}

	public static function hasRelevantChanges( mixed $result ): bool {
		if ( ! $result instanceof SyncResult || ! $result->isOk() ) {
			return false;
		}
		foreach ( self::RELEVANT as $counter ) {
			if ( ( $result->counts[ $counter ] ?? 0 ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	/** @return list<string> Namen der angesprochenen Caches (für Tests/Diagnose). */
	public static function purge(): array {
		$done = [];
		if ( function_exists( 'wp_cache_clear_cache' ) ) { // WP Super Cache
			wp_cache_clear_cache();
			$done[] = 'wp-super-cache';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) { // W3 Total Cache
			w3tc_flush_all();
			$done[] = 'w3-total-cache';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) { // WP Rocket
			rocket_clean_domain();
			$done[] = 'wp-rocket';
		}
		if ( has_action( 'litespeed_purge_all' ) ) { // LiteSpeed Cache
			do_action( 'litespeed_purge_all' );
			$done[] = 'litespeed';
		}
		if ( function_exists( 'wpfc_clear_all_cache' ) ) { // WP Fastest Cache
			wpfc_clear_all_cache( true );
			$done[] = 'wp-fastest-cache';
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) { // SiteGround Optimizer
			sg_cachepress_purge_cache();
			$done[] = 'sg-optimizer';
		}
		do_action( 'psl_purge_page_cache', $done );
		return $done;
	}
}
