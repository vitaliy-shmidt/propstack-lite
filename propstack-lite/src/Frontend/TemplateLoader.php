<?php

namespace PropstackLite\Frontend;

/**
 * Lädt Templates mit Theme-Override.
 *
 * Suchreihenfolge: {child-theme}/propstack-lite/{name}, {theme}/propstack-lite/{name},
 * danach {plugin}/templates/{name}. Templates müssen alle Ausgaben selbst escapen.
 */
final class TemplateLoader {

	public const THEME_DIR = 'propstack-lite';

	public function locate( string $name ): string {
		$name = ltrim( str_replace( [ '..', '\\' ], [ '', '/' ], $name ), '/' );
		$theme = locate_template( [ self::THEME_DIR . '/' . $name ] );
		if ( '' !== $theme ) {
			return $theme;
		}
		return PSL_DIR . 'templates/' . $name;
	}

	/** Rendert ein Template und gibt das HTML zurück. Variablen stehen als `$vars` bereit. */
	public function render( string $name, array $vars = [] ): string {
		$file = $this->locate( $name );
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$vars = apply_filters( 'psl_template_vars', $vars, $name );
		ob_start();
		( static function ( string $__file, array $vars, TemplateLoader $loader ): void {
			include $__file;
		} )( $file, $vars, $this );
		return (string) ob_get_clean();
	}
}
