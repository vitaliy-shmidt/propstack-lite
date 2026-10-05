<?php
/**
 * Lage: öffentliche Adresse (bei verborgener Adresse nur PLZ/Ort/Ortsteil) + Lagebeschreibung.
 * Keine Karte, keine Koordinaten. Überschreibbar unter {theme}/propstack-lite/parts/property-location.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$view = $vars['view'];
if ( ! $view['hasLocationSection'] ) {
	return;
}
?>
<section class="psl-section psl-section--location" aria-labelledby="psl-location-title">
	<h2 id="psl-location-title" class="psl-section__title">Lage</h2>
	<?php if ( '' !== $view['location'] ) : ?>
		<p class="psl-location__address"><?php echo esc_html( $view['location'] ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $view['texts']['location'] ) ) : ?>
		<div class="psl-prose"><?php echo wp_kses_post( wpautop( (string) $view['texts']['location'] ) ); ?></div>
	<?php endif; ?>
</section>
