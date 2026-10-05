<?php
/**
 * Ansprechpartner (nur öffentliche Propstack-Maklerfelder).
 * Überschreibbar unter {theme}/propstack-lite/parts/property-agent.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$view  = $vars['view'];
$agent = $view['agent'];
if ( null === $agent || ( null === $agent['name'] && null === $agent['email'] && null === $agent['phone'] && null === $agent['cell'] ) ) {
	return;
}
$tel = static fn ( string $number ): string => 'tel:' . preg_replace( '/[^0-9+]/', '', $number );
?>
<section class="psl-detail__section psl-detail__agent">
	<h2>Ihr Ansprechpartner</h2>
	<div class="psl-agent">
		<?php if ( $agent['avatarUrl'] ) : ?>
			<img class="psl-agent__photo" src="<?php echo esc_url( $agent['avatarUrl'] ); ?>" alt="<?php echo esc_attr( (string) $agent['name'] ); ?>" width="96" height="96" loading="lazy" decoding="async">
		<?php endif; ?>
		<div class="psl-agent__body">
			<?php if ( $agent['name'] ) : ?>
				<p class="psl-agent__name"><?php echo esc_html( $agent['name'] ); ?></p>
			<?php endif; ?>
			<?php if ( $agent['position'] ) : ?>
				<p class="psl-agent__position"><?php echo esc_html( $agent['position'] ); ?></p>
			<?php endif; ?>
			<?php if ( $view['allowContact'] ) : ?>
				<?php if ( $agent['phone'] ) : ?>
					<p><a href="<?php echo esc_url( $tel( $agent['phone'] ), [ 'tel' ] ); ?>"><?php echo esc_html( $agent['phone'] ); ?></a></p>
				<?php endif; ?>
				<?php if ( $agent['cell'] ) : ?>
					<p><a href="<?php echo esc_url( $tel( $agent['cell'] ), [ 'tel' ] ); ?>"><?php echo esc_html( $agent['cell'] ); ?></a></p>
				<?php endif; ?>
				<?php if ( $agent['email'] ) : ?>
					<p><a href="<?php echo esc_url( 'mailto:' . $agent['email'], [ 'mailto' ] ); ?>"><?php echo esc_html( $agent['email'] ); ?></a></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</section>
