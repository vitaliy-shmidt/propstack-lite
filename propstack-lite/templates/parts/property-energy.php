<?php
/**
 * Energieangaben – nur tatsächlich vorhandene Propstack-Werte.
 * Überschreibbar unter {theme}/propstack-lite/parts/property-energy.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$items = $vars['view']['energy'];
if ( [] === $items ) {
	return;
}
?>
<section class="psl-section psl-section--energy" aria-labelledby="psl-energy-title">
	<h2 id="psl-energy-title" class="psl-section__title">Energie</h2>
	<dl class="psl-fact-list psl-fact-list--energy">
		<?php foreach ( $items as $item ) : ?>
			<div class="psl-fact-list__row">
				<dt><?php echo esc_html( $item['label'] ); ?></dt>
				<dd><?php echo esc_html( $item['value'] ); ?></dd>
			</div>
		<?php endforeach; ?>
	</dl>
</section>
