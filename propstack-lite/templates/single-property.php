<?php
/**
 * Immobilien-Detailseite.
 *
 * Überschreibbar unter {theme}/propstack-lite/single-property.php, Teile unter
 * {theme}/propstack-lite/parts/…. Erhält ausschließlich das vorbereitete ViewModel
 * (Frontend\PropertyViewModel) – keine Rohdaten. Alle Ausgaben escapen.
 * Bereiche ohne Daten werden von den Teil-Templates nicht ausgegeben.
 *
 * Hooks (siehe docs/frontend.md):
 *  psl_before_property_content / psl_after_property_content  – um den Inhaltsbereich (Theme-Wrapper)
 *  psl_before_property / psl_after_property                  – innerhalb des Artikels
 *  psl_property_contact                                      – Einhängepunkt Kontaktformular (Phase 4)
 *  psl_property_similar                                      – Einhängepunkt ähnliche Immobilien
 */

defined( 'ABSPATH' ) || exit;

$psl_controller = \PropstackLite\Frontend\DetailController::current();
$psl_view       = $psl_controller ? $psl_controller->view() : null;
if ( null === $psl_view ) {
	return;
}
$psl_loader  = $psl_controller->templates();
$psl_classes = (array) apply_filters( 'psl_detail_container_classes', [ 'psl-detail-wrap' ], $psl_view );
$psl_part    = static function ( string $name, array $vars = [] ) use ( $psl_loader, $psl_view ): void {
	echo $psl_loader->render( 'parts/' . $name . '.php', $vars + [ 'view' => $psl_view ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Teil-Templates escapen selbst.
};

$psl_loader->header();
?>
<div class="<?php echo esc_attr( implode( ' ', array_map( 'sanitize_html_class', $psl_classes ) ) ); ?>">
	<?php do_action( 'psl_before_property_content', $psl_view ); ?>

	<article id="psl-property-<?php echo esc_attr( (string) $psl_view['id'] ); ?>" class="psl-detail psl-detail--<?php echo esc_attr( $psl_view['state'] ); ?>">
		<?php do_action( 'psl_before_property', $psl_view ); ?>

		<?php
		$psl_part( 'breadcrumb' );
		$psl_part( 'property-header' );
		?>

		<div class="psl-detail__hero">
			<?php
			$psl_part( 'property-gallery' );
			$psl_part( 'property-summary' );
			?>
		</div>

		<?php
		if ( $psl_view['isSold'] ) {
			$psl_part( 'property-sold-notice' );
		}
		?>

		<div class="psl-detail__body">
			<?php
			$psl_part( 'property-facts' );
			$psl_part( 'property-text', [ 'title' => 'Objektbeschreibung', 'text' => $psl_view['texts']['description'], 'modifier' => 'description' ] );
			$psl_part( 'property-equipment' );
			$psl_part( 'property-location' );
			$psl_part( 'property-energy' );
			$psl_part( 'property-floorplans' );
			$psl_part( 'property-other' );
			$psl_part( 'property-agent' );
			if ( $psl_view['contact']['allowed'] ) {
				$psl_part( 'property-contact' );
			}
			?>
		</div>

		<?php do_action( 'psl_after_property', $psl_view ); ?>
	</article>

	<?php do_action( 'psl_property_similar', $psl_view ); ?>

	<?php do_action( 'psl_after_property_content', $psl_view ); ?>

	<?php $psl_part( 'lightbox' ); ?>
</div>
<?php
$psl_loader->footer();
