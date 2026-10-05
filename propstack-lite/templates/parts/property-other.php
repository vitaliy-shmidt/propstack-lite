<?php
/**
 * Sonstige Angaben und Provisionshinweis.
 * Überschreibbar unter {theme}/propstack-lite/parts/property-other.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$view = $vars['view'];
if ( ! $view['hasOtherSection'] ) {
	return;
}
?>
<section class="psl-section psl-section--other" aria-labelledby="psl-other-title">
	<h2 id="psl-other-title" class="psl-section__title">Sonstige Angaben</h2>
	<?php if ( ! empty( $view['texts']['other'] ) ) : ?>
		<div class="psl-prose"><?php echo wp_kses_post( wpautop( (string) $view['texts']['other'] ) ); ?></div>
	<?php endif; ?>
	<?php if ( isset( $view['commission']['note'] ) ) : ?>
		<h3 class="psl-section__subtitle">Provisionshinweis</h3>
		<p class="psl-prose"><?php echo esc_html( $view['commission']['note'] ); ?></p>
	<?php endif; ?>
</section>
