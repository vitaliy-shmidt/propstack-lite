<?php
/**
 * Lightbox (natives <dialog>), gesteuert von assets/js/psl-gallery.js.
 * Wird nur ausgegeben, wenn es Bilder oder Grundrisse gibt.
 * Überschreibbar unter {theme}/propstack-lite/parts/lightbox.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$view = $vars['view'];
if ( [] === $view['gallery'] && [] === $view['floorplans'] ) {
	return;
}
?>
<dialog class="psl-lightbox" data-psl-lightbox aria-label="Bildansicht">
	<div class="psl-lightbox__inner">
		<button type="button" class="psl-lightbox__close" data-psl-close aria-label="Bildansicht schließen">
			<span aria-hidden="true">&times;</span>
		</button>
		<figure class="psl-lightbox__figure">
			<img class="psl-lightbox__image" data-psl-image alt="" decoding="async">
			<figcaption class="psl-lightbox__caption" data-psl-caption></figcaption>
		</figure>
		<button type="button" class="psl-lightbox__nav psl-lightbox__nav--prev" data-psl-prev aria-label="Vorheriges Bild">
			<span aria-hidden="true">&#8249;</span>
		</button>
		<button type="button" class="psl-lightbox__nav psl-lightbox__nav--next" data-psl-next aria-label="Nächstes Bild">
			<span aria-hidden="true">&#8250;</span>
		</button>
		<p class="psl-lightbox__counter" data-psl-counter aria-live="polite"></p>
	</div>
</dialog>
