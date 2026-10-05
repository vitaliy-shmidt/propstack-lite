<?php
/**
 * Galerie: Hauptbild + Vorschaubilder. Ohne JavaScript verlinken die Bilder auf die große Version;
 * mit JavaScript öffnet sich die Lightbox (assets/js/psl-gallery.js).
 * Enthält nur öffentliche Nicht-Grundriss-Bilder (Filter im Mapper/ViewModel).
 * Überschreibbar unter {theme}/propstack-lite/parts/property-gallery.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$images = $vars['view']['gallery'];
$count  = count( $images );

if ( 0 === $count ) : ?>
	<div class="psl-gallery psl-gallery--empty">
		<div class="psl-gallery__placeholder">
			<svg class="psl-gallery__placeholder-icon" viewBox="0 0 24 24" width="48" height="48" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 3 2 11h3v9h5v-6h4v6h5v-9h3L12 3z"/></svg>
			<p>Für diese Immobilie sind noch keine Bilder verfügbar.</p>
		</div>
	</div>
	<?php
	return;
endif;

$main = $images[0];
?>
<section class="psl-gallery" data-psl-gallery="main" aria-label="Bildergalerie">
	<figure class="psl-gallery__main">
		<a class="psl-gallery__link psl-gallery__link--main" href="<?php echo esc_url( $main['full'] ); ?>"
			data-psl-index="0"
			data-psl-full="<?php echo esc_url( $main['full'] ); ?>"
			data-psl-srcset="<?php echo esc_attr( $main['fullSrcset'] ); ?>"
			data-psl-alt="<?php echo esc_attr( $main['alt'] ); ?>"
			data-psl-caption="<?php echo esc_attr( (string) $main['caption'] ); ?>"
			aria-label="<?php echo esc_attr( sprintf( 'Bild 1 von %d vergrößern', $count ) ); ?>">
			<img class="psl-gallery__image" src="<?php echo esc_url( $main['src'] ); ?>"
				srcset="<?php echo esc_attr( $main['srcset'] ); ?>"
				sizes="(min-width: 1024px) min(66vw, 780px), calc(100vw - 32px)"
				alt="<?php echo esc_attr( $main['alt'] ); ?>" fetchpriority="high" decoding="async">
		</a>
		<?php if ( $count > 1 ) : ?>
			<button type="button" class="psl-gallery__all" data-psl-open="0" hidden>
				<?php echo esc_html( sprintf( 'Alle %d Bilder ansehen', $count ) ); ?>
			</button>
		<?php endif; ?>
	</figure>

	<?php if ( $count > 1 ) : ?>
		<ul class="psl-gallery__thumbs">
			<?php foreach ( array_slice( $images, 1, null, true ) as $i => $image ) : ?>
				<li class="psl-gallery__thumb">
					<a class="psl-gallery__link" href="<?php echo esc_url( $image['full'] ); ?>"
						data-psl-index="<?php echo esc_attr( (string) $i ); ?>"
						data-psl-full="<?php echo esc_url( $image['full'] ); ?>"
						data-psl-srcset="<?php echo esc_attr( $image['fullSrcset'] ); ?>"
						data-psl-alt="<?php echo esc_attr( $image['alt'] ); ?>"
						data-psl-caption="<?php echo esc_attr( (string) $image['caption'] ); ?>"
						aria-label="<?php echo esc_attr( sprintf( 'Bild %d von %d vergrößern: %s', $i + 1, $count, $image['alt'] ) ); ?>">
						<img src="<?php echo esc_url( $image['thumb'] ); ?>"
							<?php if ( '' !== $image['thumbSrcset'] ) : ?>srcset="<?php echo esc_attr( $image['thumbSrcset'] ); ?>" sizes="(min-width: 1024px) 140px, 25vw"<?php endif; ?>
							<?php if ( $image['thumbSize'] ) : ?>width="<?php echo esc_attr( (string) $image['thumbSize'] ); ?>" height="<?php echo esc_attr( (string) $image['thumbSize'] ); ?>"<?php endif; ?>
							alt="" loading="lazy" decoding="async">
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
