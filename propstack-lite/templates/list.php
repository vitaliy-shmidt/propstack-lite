<?php
/**
 * Immobilienliste.
 *
 * Überschreibbar unter {theme}/propstack-lite/list.php.
 *
 * @var array                                   $vars   cards, total, heading
 * @var \PropstackLite\Frontend\TemplateLoader  $loader
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="psl-list">
	<div class="psl-grid">
		<?php
		foreach ( $vars['cards'] as $card ) {
			echo $loader->render( 'parts/card.php', [ 'card' => $card, 'heading' => $vars['heading'] ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template escaped selbst.
		}
		?>
	</div>
</div>
