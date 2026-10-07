<?php
/**
 * Immobilienübersicht: Such-/Sortierformular, Trefferanzahl, Karten, Leerzustand, Pagination.
 *
 * Überschreibbar unter {theme}/propstack-lite/list.php. Teile: parts/list-filters.php,
 * parts/card.php, parts/list-pagination.php (einzeln überschreibbar).
 *
 * @var array                                   $vars   id, cards, total, heading, form, count, pageInfo, empty, pagination
 * @var \PropstackLite\Frontend\TemplateLoader  $loader
 */

defined( 'ABSPATH' ) || exit;

$psl_id = $vars['id'] ?? 'psl-list';
?>
<div class="psl-list" id="<?php echo esc_attr( $psl_id ); ?>">
	<?php
	if ( ! empty( $vars['form'] ) ) {
		echo $loader->render( 'parts/list-filters.php', [ 'form' => $vars['form'], 'id' => $psl_id ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escaped selbst.
	}
	?>

	<?php if ( ! empty( $vars['count'] ) ) : ?>
		<div class="psl-results">
			<p class="psl-results__count" id="<?php echo esc_attr( $psl_id . '-count' ); ?>"><?php echo esc_html( $vars['count'] ); ?></p>
			<?php if ( ! empty( $vars['pageInfo'] ) ) : ?>
				<p class="psl-results__page"><?php echo esc_html( $vars['pageInfo'] ); ?></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $vars['empty'] ) ) : ?>
		<div class="psl-empty">
			<p class="psl-empty__message"><?php echo esc_html( $vars['empty']['message'] ); ?></p>
			<p><a class="psl-empty__link" href="<?php echo esc_url( $vars['empty']['url'] ); ?>"><?php echo esc_html( $vars['empty']['label'] ); ?></a></p>
		</div>
	<?php else : ?>
		<div class="psl-grid">
			<?php
			foreach ( $vars['cards'] as $i => $card ) {
				echo $loader->render( 'parts/card.php', [ 'card' => $card, 'heading' => $vars['heading'], 'index' => $i ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escaped selbst.
			}
			?>
		</div>
	<?php endif; ?>

	<?php
	if ( ! empty( $vars['pagination'] ) ) {
		echo $loader->render( 'parts/list-pagination.php', [ 'pagination' => $vars['pagination'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escaped selbst.
	}
	?>
</div>
