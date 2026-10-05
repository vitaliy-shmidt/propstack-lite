<?php
/**
 * Freitext-Abschnitt (z. B. Objektbeschreibung). Rich Text wurde beim Speichern mit
 * wp_kses_post bereinigt und wird hier erneut gefiltert; Zeilenumbrüche → Absätze (wpautop).
 * Überschreibbar unter {theme}/propstack-lite/parts/property-text.php.
 *
 * @var array $vars title, text, modifier
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $vars['text'] ) ) {
	return;
}
$id = 'psl-section-' . sanitize_html_class( (string) $vars['modifier'] );
?>
<section class="psl-section psl-section--<?php echo esc_attr( (string) $vars['modifier'] ); ?>" aria-labelledby="<?php echo esc_attr( $id ); ?>">
	<h2 id="<?php echo esc_attr( $id ); ?>" class="psl-section__title"><?php echo esc_html( (string) $vars['title'] ); ?></h2>
	<div class="psl-prose"><?php echo wp_kses_post( wpautop( (string) $vars['text'] ) ); ?></div>
</section>
