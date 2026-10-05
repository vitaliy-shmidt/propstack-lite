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

	private ?string $blockFooter = null;

	public function locate( string $name ): string {
		$name  = ltrim( str_replace( [ '..', '\\' ], [ '', '/' ], $name ), '/' );
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

	/**
	 * Seitenkopf des aktiven Themes.
	 * Klassische Themes (z. B. Avada): get_header(). Block-Themes besitzen kein header.php –
	 * dort werden die Template-Parts „header“/„footer“ gerendert (analog zu wp-includes/template-canvas.php).
	 */
	public function header(): void {
		if ( ! self::isBlockTheme() ) {
			get_header();
			return;
		}
		// Template-Parts vor wp_head() rendern, damit ihre Block-Styles im <head> landen.
		$header            = do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
		$this->blockFooter = do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' );
		?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
		<?php
		echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- gerenderte Theme-Blöcke.
		echo '<main class="wp-block-group">';
	}

	public function footer(): void {
		if ( ! self::isBlockTheme() ) {
			get_footer();
			return;
		}
		echo '</main>';
		echo $this->blockFooter ?? ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- gerenderte Theme-Blöcke.
		echo '</div>';
		wp_footer();
		echo "</body>\n</html>\n";
	}

	private static function isBlockTheme(): bool {
		return function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
	}
}
