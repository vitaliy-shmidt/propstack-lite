<?php

namespace PropstackLite\Theme;

use PropstackLite\Routing\Router;

/**
 * Optionaler Adapter für das Theme Avada.
 *
 * NOCH NICHT GEGEN EINE REALE AVADA-INSTALLATION VERIFIZIERT.
 *
 * Bewusst minimal: Es werden keine Avada-Interna (Klassen, Container-Markup, Optionen) verwendet,
 * die nicht geprüft werden konnten. Der Adapter markiert die Seite nur mit eigenen Klassen, damit
 * das Plugin-CSS auf Avadas eigene Container Rücksicht nehmen kann. Datenlogik und Routing kennen
 * Avada nicht; ohne Avada wird dieser Adapter nicht geladen.
 */
final class AvadaAdapter {

	/** Erkennung über das Eltern-Theme-Verzeichnis bzw. die Theme-Klasse (nach after_setup_theme). */
	public static function isActive(): bool {
		return 'avada' === strtolower( (string) get_template() ) || class_exists( 'Avada', false );
	}

	public function register(): void {
		add_filter( 'body_class', [ $this, 'bodyClass' ] );
		add_filter( 'psl_detail_container_classes', [ $this, 'containerClasses' ] );
	}

	public function bodyClass( array $classes ): array {
		if ( Router::isPropertyRequest() ) {
			$classes[] = 'psl-theme-avada';
		}
		return $classes;
	}

	/** Avada stellt Inhaltscontainer selbst bereit → Plugin-Wrapper ohne eigene Maximalbreite (siehe CSS). */
	public function containerClasses( array $classes ): array {
		$classes[] = 'psl-detail-wrap--theme-container';
		return $classes;
	}
}
