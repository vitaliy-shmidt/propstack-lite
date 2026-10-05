<?php
/**
 * Kurzfakten neben der Galerie: Preis, Zimmer, Fläche, Grundstück, Handlungsaufforderung.
 * Überschreibbar unter {theme}/propstack-lite/parts/property-summary.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$view  = $vars['view'];
$price = $view['displayPrice'];
?>
<aside class="psl-summary" aria-label="Kurzfakten">
	<p class="psl-summary__price">
		<span class="psl-summary__price-label"><?php echo esc_html( $price['label'] ); ?></span>
		<span class="psl-summary__price-value"><?php echo esc_html( $price['value'] ); ?></span>
	</p>

	<?php if ( [] !== $view['keyFacts'] ) : ?>
		<dl class="psl-summary__facts">
			<?php foreach ( $view['keyFacts'] as $fact ) : ?>
				<div class="psl-summary__fact">
					<dt><?php echo esc_html( $fact['label'] ); ?></dt>
					<dd><?php echo esc_html( $fact['value'] ); ?></dd>
				</div>
			<?php endforeach; ?>
		</dl>
	<?php endif; ?>

	<?php if ( $view['contact']['allowed'] ) : ?>
		<a class="psl-button psl-button--primary" href="#<?php echo esc_attr( $view['contact']['anchor'] ); ?>">Anfrage senden</a>
	<?php else : ?>
		<a class="psl-button" href="<?php echo esc_url( $view['overviewUrl'] ); ?>">Aktuelle Immobilien ansehen</a>
	<?php endif; ?>
</aside>
