<?php
/**
 * Immobilienkarte in der Liste.
 *
 * Überschreibbar unter {theme}/propstack-lite/parts/card.php.
 * Alle Werte sind unescaped – jede Ausgabe hier escapen.
 *
 * @var array $vars card, heading, index (Position in der Liste, ab 0)
 */

defined( 'ABSPATH' ) || exit;

$card    = $vars['card'];
$heading = $vars['heading'];
// Erste Reihe (bis zu 3 Karten) sofort laden – meist LCP-Element der Übersicht; übrige lazy.
$loading = ( $vars['index'] ?? 99 ) < 3 ? 'eager' : 'lazy';
$badges  = array_filter( [ $card['type'], $card['marketing'] ] );
?>
<article class="psl-card">
	<?php if ( $card['image'] ) : ?>
		<a class="psl-card__media" href="<?php echo esc_url( $card['url'] ); ?>" tabindex="-1" aria-hidden="true">
			<img class="psl-card__img" src="<?php echo esc_url( $card['image'] ); ?>" alt="<?php echo esc_attr( $card['imageAlt'] ); ?>" loading="<?php echo esc_attr( $loading ); ?>" decoding="async" width="640" height="480">
		</a>
	<?php else : ?>
		<div class="psl-card__media psl-card__media--empty" aria-hidden="true"></div>
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
			<div class="psl-card__fact">
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

		<a class="psl-card__link" href="<?php echo esc_url( $card['url'] ); ?>" aria-label="<?php echo esc_attr( 'Mehr Informationen: ' . $card['title'] ); ?>">Mehr Informationen</a>
	</div>
</article>
