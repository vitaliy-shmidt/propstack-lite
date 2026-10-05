<?php
/**
 * Eckdaten in Gruppen (Preise & Kosten, Flächen & Räume, Objekt & Zustand).
 * Leere Werte/Gruppen sind bereits im ViewModel entfernt.
 * Überschreibbar unter {theme}/propstack-lite/parts/property-facts.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$groups = $vars['view']['factGroups'];
if ( [] === $groups ) {
	return;
}
?>
<section class="psl-section psl-section--facts" aria-labelledby="psl-facts-title">
	<h2 id="psl-facts-title" class="psl-section__title">Eckdaten</h2>
	<div class="psl-fact-groups">
		<?php foreach ( $groups as $group ) : ?>
			<div class="psl-fact-group psl-fact-group--<?php echo esc_attr( $group['id'] ); ?>">
				<h3 class="psl-fact-group__title"><?php echo esc_html( $group['title'] ); ?></h3>
				<dl class="psl-fact-list">
					<?php foreach ( $group['items'] as $item ) : ?>
						<div class="psl-fact-list__row">
							<dt><?php echo esc_html( $item['label'] ); ?></dt>
							<dd><?php echo esc_html( $item['value'] ); ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>
			</div>
		<?php endforeach; ?>
	</div>
</section>
