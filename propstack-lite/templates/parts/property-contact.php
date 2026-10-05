<?php
/**
 * Kontaktbereich (Sprungziel von „Anfrage senden“). Nur bei anfragbaren Objekten (aktiv/reserviert).
 *
 * Ist ein Anfrageformular verfügbar (Contact Form 7 aktiv und vollständig konfiguriert), rendert die
 * Integration es über den Hook `psl_property_contact`. Sonst erscheint ein neutraler Hinweis auf die
 * Kontaktdaten – nie ein technischer Fehlertext. Kein eigenes Formular-Markup.
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
	<?php if ( ! empty( $view['contact']['formAvailable'] ) ) : ?>
		<?php do_action( 'psl_property_contact', $view ); ?>
	<?php else : ?>
		<p class="psl-contact__fallback">
			<?php echo esc_html( null !== $view['agent'] ? 'Bitte kontaktieren Sie uns telefonisch oder per E-Mail – die Kontaktdaten finden Sie oben.' : 'Bitte kontaktieren Sie uns telefonisch oder per E-Mail.' ); ?>
		</p>
	<?php endif; ?>
</section>
