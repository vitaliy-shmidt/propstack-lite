<?php
/**
 * Brotkrümelnavigation (strukturierte Daten: BreadcrumbList im JSON-LD der SEO-Schicht, siehe docs/seo.md).
 * Überschreibbar unter {theme}/propstack-lite/parts/breadcrumb.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$items = $vars['view']['breadcrumb'];
$last  = count( $items ) - 1;
?>
<nav class="psl-breadcrumb" aria-label="Brotkrümelnavigation">
	<ol class="psl-breadcrumb__list">
		<?php foreach ( $items as $i => $item ) : ?>
			<li class="psl-breadcrumb__item">
				<?php if ( $i === $last ) : ?>
					<span aria-current="page"><?php echo esc_html( $item['label'] ); ?></span>
				<?php elseif ( null !== $item['url'] ) : ?>
					<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
				<?php else : ?>
					<span><?php echo esc_html( $item['label'] ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ol>
</nav>
