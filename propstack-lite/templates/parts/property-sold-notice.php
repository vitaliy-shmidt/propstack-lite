<?php
/**
 * Hinweis während der 30-tägigen Verkauft/Vermietet-Phase (HTTP 200, noindex).
 * Überschreibbar unter {theme}/propstack-lite/parts/property-sold-notice.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$view  = $vars['view'];
$label = $view['statusBadge']['label'] ?? 'Verkauft';
?>
<div class="psl-notice psl-notice--sold" role="status">
	<p><strong><?php echo esc_html( sprintf( 'Diese Immobilie ist bereits %s.', mb_strtolower( $label ) ) ); ?></strong>
	Gerne informieren wir Sie über vergleichbare Angebote.</p>
	<p><a class="psl-button" href="<?php echo esc_url( $view['overviewUrl'] ); ?>">Aktuelle Immobilien ansehen</a></p>
</div>
