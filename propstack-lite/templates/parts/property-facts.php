<?php
/**
 * Kerndaten (Preis, Fläche, Zimmer, Grundstück).
 * Überschreibbar unter {theme}/propstack-lite/parts/property-facts.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$view = $vars['view'];
if ( [] === $view['facts'] ) {
	return;
}
?>
<section class="psl-detail__facts" aria-label="Eckdaten">
	<dl class="psl-facts">
		<?php foreach ( $view['facts'] as $fact ) : ?>
			<div class="psl-facts__item">
				<dt><?php echo esc_html( $fact['label'] ); ?></dt>
				<dd><?php echo esc_html( $fact['value'] ); ?></dd>
			</div>
		<?php endforeach; ?>
	</dl>
</section>
