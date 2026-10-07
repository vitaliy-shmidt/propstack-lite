<?php
/**
 * Immobilienkarte in der Liste.
 *
 * Überschreibbar unter {theme}/propstack-lite/parts/card.php.
 * Alle Werte sind unescaped – jede Ausgabe hier escapen.
 *
 * Bild: Propstack-Größe „medium“ (Rahmen 600 × 450). Bewusst ohne `big` im srcset – die nächste
 * Größe ist 1920 px und würde auf HiDPI-Smartphones ein Vielfaches laden (docs/listing.md).
 * Festes Seitenverhältnis 4:3 (CSS + width/height) verhindert Layoutsprünge.
 *
 * @var array $vars card, heading, index (Position in der Liste, ab 0)
 */

defined( 'ABSPATH' ) || exit;

$card    = $vars['card'];
$heading = $vars['heading'];
$index   = (int) ( $vars['index'] ?? 99 );
// Erste Reihe (bis zu 3 Karten) sofort laden – meist LCP-Element der Übersicht; übrige lazy.
$loading = $index < 3 ? 'eager' : 'lazy';
$badges  = array_filter( [ $card['type'], $card['marketing'] ] );
?>
<article class="psl-card<?php echo $card['reserved'] ? ' psl-card--reserved' : ''; ?>">
	<?php if ( $card['image'] ) : ?>
		<a class="psl-card__media" href="<?php echo esc_url( $card['url'] ); ?>" tabindex="-1" aria-hidden="true">
			<img class="psl-card__img" src="<?php echo esc_url( $card['image'] ); ?>" alt="<?php echo esc_attr( $card['imageAlt'] ); ?>" loading="<?php echo esc_attr( $loading ); ?>" decoding="async"<?php echo 0 === $index ? ' fetchpriority="high"' : ''; ?> width="600" height="450">
		</a>
	<?php else : ?>
		<div class="psl-card__media psl-card__media--empty" aria-hidden="true">
			<svg class="psl-card__placeholder" viewBox="0 0 64 48" width="64" height="48" focusable="false"><path d="M8 24 32 6l24 18v20a2 2 0 0 1-2 2H40V32H24v14H10a2 2 0 0 1-2-2V24z" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round"/></svg>
			<span class="psl-card__placeholder-text">Kein Bild verfügbar</span>
		</div>
	<?php endif; ?>

	<div class="psl-card__body">
		<p class="psl-card__badges">
			<?php foreach ( $badges as $badge ) : ?>
				<span class="psl-badge"><?php echo esc_html( $badge ); ?></span>
			<?php endforeach; ?>
			<?php if ( $card['reserved'] ) : ?>
				<span class="psl-badge psl-badge--reserved">Reserviert</span>
			<?php endif; ?>
		</p>

		<<?php echo tag_escape( $heading ); ?> class="psl-card__title"><?php echo esc_html( $card['title'] ); ?></<?php echo tag_escape( $heading ); ?>>

		<?php if ( '' !== $card['location'] ) : ?>
			<p class="psl-card__location"><?php echo esc_html( $card['location'] ); ?></p>
		<?php endif; ?>

		<dl class="psl-card__facts">
			<div class="psl-card__fact psl-card__fact--price">
				<dt><?php echo esc_html( $card['price']['label'] ); ?></dt>
				<dd><?php echo esc_html( $card['price']['value'] ); ?></dd>
			</div>
			<?php if ( $card['area'] ) : ?>
				<div class="psl-card__fact">
					<dt><?php echo esc_html( $card['areaLabel'] ); ?></dt>
					<dd><?php echo esc_html( $card['area'] ); ?></dd>
				</div>
			<?php endif; ?>
			<?php if ( $card['rooms'] ) : ?>
				<div class="psl-card__fact">
					<dt>Zimmer</dt>
					<dd><?php echo esc_html( $card['rooms'] ); ?></dd>
				</div>
			<?php endif; ?>
		</dl>

		<a class="psl-card__link" href="<?php echo esc_url( $card['url'] ); ?>" aria-label="<?php echo esc_attr( 'Details ansehen: ' . $card['title'] ); ?>">Details ansehen</a>
	</div>
</article>
