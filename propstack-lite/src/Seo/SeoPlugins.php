<?php

namespace PropstackLite\Seo;

/**
 * Erkennung aktiver SEO-Plugins. Priorität: Yoast SEO > Rank Math > Core (Plugin gibt selbst aus).
 * Es wird immer genau EIN Adapter registriert – nie mehrere parallel (keine doppelten Tags).
 */
final class SeoPlugins {

	public const YOAST    = 'yoast';
	public const RANKMATH = 'rankmath';
	public const CORE     = 'core';

	public const LABELS = [
		self::YOAST    => 'Yoast SEO',
		self::RANKMATH => 'Rank Math',
		self::CORE     => 'Propstack Listings Lite (ohne SEO-Plugin)',
	];

	/** @return list<string> aktive, unterstützte SEO-Plugins in Prioritätsreihenfolge */
	public static function active(): array {
		$active = [];
		if ( defined( 'WPSEO_VERSION' ) ) {
			$active[] = self::YOAST;
		}
		// Rank Math lädt sein Frontend nur mit gültiger bzw. übersprungener Registrierung – sonst
		// gibt es nichts aus und der Core-Modus muss greifen.
		if ( defined( 'RANK_MATH_VERSION' ) && class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'is_invalid_registration' ) && ! \RankMath\Helper::is_invalid_registration() ) {
			$active[] = self::RANKMATH;
		}
		return $active;
	}

	/** Integrierter Modus: höchstpriorisiertes aktives SEO-Plugin, sonst Core. */
	public static function mode(): string {
		return self::active()[0] ?? self::CORE;
	}
}
