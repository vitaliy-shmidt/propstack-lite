<?php
/**
 * Kopfbereich: Status-Badge (mit Text), Objektart, Kauf/Miete, H1, Ort.
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
