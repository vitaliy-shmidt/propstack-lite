<?php
/**
 * Seitennavigation der Immobilienübersicht. Links behalten alle aktiven Filter/Sortierungen.
 *
 * Überschreibbar unter {theme}/propstack-lite/parts/list-pagination.php. Werte sind unescaped.
 *
 * @var array $vars pagination: prev, next (URL|null), items (list<{number, url, current}|null>; null = Auslassung)
 */

defined( 'ABSPATH' ) || exit;

$psl_p = $vars['pagination'];
?>
<nav class="psl-pagination" aria-label="Seitennavigation Immobilien">
	<ul class="psl-pagination__list">
		<?php if ( $psl_p['prev'] ) : ?>
			<li><a class="psl-pagination__link psl-pagination__prev" href="<?php echo esc_url( $psl_p['prev'] ); ?>" rel="prev">Zurück<span class="psl-sr-only"> zur vorherigen Seite</span></a></li>
		<?php endif; ?>
		<?php foreach ( $psl_p['items'] as $psl_item ) : ?>
			<?php if ( null === $psl_item ) : ?>
				<li class="psl-pagination__gap" aria-hidden="true">…</li>
			<?php elseif ( $psl_item['current'] ) : ?>
				<li><span class="psl-pagination__link psl-pagination__link--current" aria-current="page"><span class="psl-sr-only">Seite </span><?php echo esc_html( (string) $psl_item['number'] ); ?></span></li>
			<?php else : ?>
				<li><a class="psl-pagination__link" href="<?php echo esc_url( $psl_item['url'] ); ?>"><span class="psl-sr-only">Seite </span><?php echo esc_html( (string) $psl_item['number'] ); ?></a></li>
			<?php endif; ?>
		<?php endforeach; ?>
		<?php if ( $psl_p['next'] ) : ?>
			<li><a class="psl-pagination__link psl-pagination__next" href="<?php echo esc_url( $psl_p['next'] ); ?>" rel="next">Weiter<span class="psl-sr-only"> zur nächsten Seite</span></a></li>
		<?php endif; ?>
	</ul>
</nav>
