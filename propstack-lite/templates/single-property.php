<?php
/**
 * Immobilien-Detailseite (Basis, Phase 2).
 *
 * Überschreibbar unter {theme}/propstack-lite/single-property.php, Teile unter
 * {theme}/propstack-lite/parts/…. Erhält ausschließlich das vorbereitete ViewModel
 * (siehe Frontend\PropertyViewModel) – keine Rohdaten. Alle Ausgaben escapen.
 *
 * Hooks:
 *  psl_before_property_content / psl_after_property_content  – um den Inhaltsbereich (Theme-Wrapper)
 *  psl_before_property / psl_after_property                  – innerhalb des Artikels
 *  psl_property_contact                                      – Platzhalter Kontaktformular (Phase 4)
 *  psl_property_similar                                      – Platzhalter ähnliche Immobilien
 */

defined( 'ABSPATH' ) || exit;

$psl_controller = \PropstackLite\Frontend\DetailController::current();
$psl_view       = $psl_controller ? $psl_controller->view() : null;
if ( null === $psl_view ) {
	return;
}
$psl_loader  = $psl_controller->templates();
$psl_classes = (array) apply_filters( 'psl_detail_container_classes', [ 'psl-detail-wrap' ], $psl_view );

$psl_loader->header();
?>
<div class="<?php echo esc_attr( implode( ' ', array_map( 'sanitize_html_class', $psl_classes ) ) ); ?>">
	<?php do_action( 'psl_before_property_content', $psl_view ); ?>

	<article id="psl-property-<?php echo esc_attr( (string) $psl_view['id'] ); ?>" class="psl-detail psl-detail--<?php echo esc_attr( $psl_view['state'] ); ?>">
		<?php do_action( 'psl_before_property', $psl_view ); ?>

		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Teil-Templates escapen selbst.
		echo $psl_loader->render( 'parts/property-header.php', [ 'view' => $psl_view ] );

		if ( $psl_view['isSold'] ) {
			echo $psl_loader->render( 'parts/property-sold-notice.php', [ 'view' => $psl_view ] );
		}

		echo $psl_loader->render( 'parts/property-facts.php', [ 'view' => $psl_view ] );
		echo $psl_loader->render( 'parts/property-description.php', [ 'view' => $psl_view ] );
		echo $psl_loader->render( 'parts/property-agent.php', [ 'view' => $psl_view ] );
		// phpcs:enable
		?>

		<?php if ( $psl_view['allowContact'] ) : ?>
			<section class="psl-detail__contact" id="psl-contact">
				<?php do_action( 'psl_property_contact', $psl_view ); ?>
			</section>
		<?php else : ?>
			<?php do_action( 'psl_property_similar', $psl_view ); ?>
		<?php endif; ?>

		<?php do_action( 'psl_after_property', $psl_view ); ?>
	</article>

	<?php do_action( 'psl_after_property_content', $psl_view ); ?>
</div>
<?php
$psl_loader->footer();
