<?php
/**
 * Kontaktbereich (Sprungziel von „Anfrage senden“). Nur bei verfügbaren Objekten.
 * Das Formular hängt sich in Phase 4 über `psl_property_contact` ein – hier bewusst kein eigenes Formular.
 * Überschreibbar unter {theme}/propstack-lite/parts/property-contact.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$view = $vars['view'];
?>
<section class="psl-section psl-section--contact" id="<?php echo esc_attr( $view['contact']['anchor'] ); ?>" aria-labelledby="psl-contact-title" tabindex="-1">
	<h2 id="psl-contact-title" class="psl-section__title"><?php echo esc_html( $view['contact']['heading'] ); ?></h2>
	<p class="psl-contact__context"><?php echo esc_html( sprintf( 'Ihre Anfrage zu: %s', $view['title'] ) ); ?></p>
	<?php do_action( 'psl_property_contact', $view ); ?>
</section>
