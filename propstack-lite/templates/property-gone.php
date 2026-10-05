<?php
/**
 * Antwortseite für HTTP 410 (Objekt dauerhaft nicht mehr verfügbar).
 *
 * Überschreibbar unter {theme}/propstack-lite/property-gone.php.
 * Enthält bewusst keine Objektdaten.
 */

defined( 'ABSPATH' ) || exit;

$psl_controller = \PropstackLite\Frontend\DetailController::current();
if ( null === $psl_controller ) {
	return;
}
$psl_loader   = $psl_controller->templates();
$psl_overview = ( new \PropstackLite\Routing\UrlGenerator() )->overviewUrl();
$psl_view     = [ 'state' => 'gone', 'overviewUrl' => $psl_overview ];
$psl_classes  = (array) apply_filters( 'psl_detail_container_classes', [ 'psl-detail-wrap' ], $psl_view );

$psl_loader->header();
?>
<div class="<?php echo esc_attr( implode( ' ', array_map( 'sanitize_html_class', $psl_classes ) ) ); ?>">
	<?php do_action( 'psl_before_property_content', $psl_view ); ?>

	<section class="psl-detail psl-detail--gone">
		<h1 class="psl-detail__title">Diese Immobilie ist nicht mehr verfügbar</h1>
		<p>Das Angebot wurde inzwischen vermarktet oder zurückgezogen. Aktuelle Angebote finden Sie in unserer Immobilienübersicht.</p>
		<p><a class="psl-button" href="<?php echo esc_url( $psl_overview ); ?>">Zur Immobilienübersicht</a></p>

		<?php do_action( 'psl_property_similar', $psl_view ); ?>
	</section>

	<?php do_action( 'psl_after_property_content', $psl_view ); ?>
</div>
<?php
$psl_loader->footer();
