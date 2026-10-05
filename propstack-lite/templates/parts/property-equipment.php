<?php
/**
 * Ausstattung: positive Merkmale als Liste + Ausstattungsbeschreibung.
 * Überschreibbar unter {theme}/propstack-lite/parts/property-equipment.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$view = $vars['view'];
if ( ! $view['hasEquipmentSection'] ) {
	return;
}
?>
<section class="psl-section psl-section--equipment" aria-labelledby="psl-equipment-title">
	<h2 id="psl-equipment-title" class="psl-section__title">Ausstattung</h2>
	<?php if ( [] !== $view['features'] ) : ?>
		<ul class="psl-features">
			<?php foreach ( $view['features'] as $feature ) : ?>
				<li class="psl-features__item"><?php echo esc_html( $feature ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( ! empty( $view['texts']['furnishing'] ) ) : ?>
		<div class="psl-prose"><?php echo wp_kses_post( wpautop( (string) $view['texts']['furnishing'] ) ); ?></div>
	<?php endif; ?>
</section>
