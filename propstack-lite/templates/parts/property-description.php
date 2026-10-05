<?php
/**
 * Objektbeschreibung. Rich Text wurde beim Speichern mit wp_kses_post bereinigt
 * und wird hier erneut gefiltert (Defense in Depth).
 * Überschreibbar unter {theme}/propstack-lite/parts/property-description.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$view = $vars['view'];
if ( empty( $view['description'] ) ) {
	return;
}
?>
<section class="psl-detail__section psl-detail__description">
	<h2>Beschreibung</h2>
	<?php echo wp_kses_post( wpautop( $view['description'] ) ); ?>
</section>
