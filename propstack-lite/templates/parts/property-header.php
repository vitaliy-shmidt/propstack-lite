<?php
/**
 * Kopfbereich: Badges, Titel, Ort, Hauptbild.
 * Überschreibbar unter {theme}/propstack-lite/parts/property-header.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$view   = $vars['view'];
$badges = array_filter( [ $view['type'], $view['marketing'] ] );
?>
<header class="psl-detail__header">
	<p class="psl-detail__badges">
		<?php if ( $view['statusBadge'] ) : ?>
			<span class="psl-badge psl-badge--<?php echo esc_attr( $view['statusBadge']['modifier'] ); ?>"><?php echo esc_html( $view['statusBadge']['label'] ); ?></span>
		<?php endif; ?>
		<?php foreach ( $badges as $badge ) : ?>
			<span class="psl-badge"><?php echo esc_html( $badge ); ?></span>
		<?php endforeach; ?>
	</p>

	<h1 class="psl-detail__title"><?php echo esc_html( $view['title'] ); ?></h1>

	<?php if ( '' !== $view['location'] ) : ?>
		<p class="psl-detail__location"><?php echo esc_html( $view['location'] ); ?></p>
	<?php endif; ?>
</header>

<?php if ( $view['image'] && $view['image']['src'] ) : ?>
	<figure class="psl-detail__media">
		<img class="psl-detail__image" src="<?php echo esc_url( $view['image']['src'] ); ?>" alt="<?php echo esc_attr( $view['image']['alt'] ); ?>" fetchpriority="high" decoding="async">
		<?php if ( $view['imageCount'] > 1 ) : ?>
			<figcaption class="psl-detail__image-count"><?php echo esc_html( sprintf( '%d Bilder', $view['imageCount'] ) ); ?></figcaption>
		<?php endif; ?>
	</figure>
<?php endif; ?>
