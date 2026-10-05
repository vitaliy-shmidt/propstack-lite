<?php
/**
 * Grundrisse (eigene Lightbox-Gruppe). Nur öffentliche Grundrisse.
 * Überschreibbar unter {theme}/propstack-lite/parts/property-floorplans.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$plans = $vars['view']['floorplans'];
$count = count( $plans );
if ( 0 === $count ) {
	return;
}
?>
<section class="psl-section psl-section--floorplans" aria-labelledby="psl-floorplans-title" data-psl-gallery="floorplans">
	<h2 id="psl-floorplans-title" class="psl-section__title">Grundrisse</h2>
	<ul class="psl-floorplans">
		<?php foreach ( $plans as $i => $plan ) : ?>
			<li class="psl-floorplans__item">
				<a class="psl-gallery__link psl-floorplans__link" href="<?php echo esc_url( $plan['full'] ); ?>"
					data-psl-index="<?php echo esc_attr( (string) $i ); ?>"
					data-psl-full="<?php echo esc_url( $plan['full'] ); ?>"
					data-psl-srcset="<?php echo esc_attr( $plan['fullSrcset'] ); ?>"
					data-psl-alt="<?php echo esc_attr( $plan['alt'] ); ?>"
					data-psl-caption="<?php echo esc_attr( (string) $plan['caption'] ); ?>"
					aria-label="<?php echo esc_attr( sprintf( 'Grundriss %d von %d vergrößern', $i + 1, $count ) ); ?>">
					<img src="<?php echo esc_url( $plan['src'] ); ?>" srcset="<?php echo esc_attr( $plan['srcset'] ); ?>" sizes="(min-width: 768px) 50vw, 100vw"
						alt="<?php echo esc_attr( $plan['alt'] ); ?>" loading="lazy" decoding="async">
				</a>
				<?php if ( $plan['caption'] ) : ?>
					<p class="psl-floorplans__caption"><?php echo esc_html( $plan['caption'] ); ?></p>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
