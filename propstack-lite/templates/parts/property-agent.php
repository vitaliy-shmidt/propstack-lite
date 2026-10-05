<?php
/**
 * Ansprechpartner – nur öffentliche Propstack-Maklerfelder (public_email/public_phone/public_cell)
 * bzw. ein Fallback über den Filter `psl_property_fallback_agent`.
 * Kontaktdaten nur bei verfügbaren Objekten. Überschreibbar unter {theme}/propstack-lite/parts/property-agent.php.
 *
 * @var array $vars view
 */

defined( 'ABSPATH' ) || exit;

$view  = $vars['view'];
$agent = $view['agent'];
if ( null === $agent ) {
	return;
}
$tel = static fn ( string $number ): string => 'tel:' . preg_replace( '/[^0-9+]/', '', $number );
?>
<section class="psl-section psl-section--agent" aria-labelledby="psl-agent-title">
	<h2 id="psl-agent-title" class="psl-section__title">Ihr Ansprechpartner</h2>
	<div class="psl-agent">
		<?php if ( ! empty( $agent['avatarUrl'] ) ) : ?>
			<img class="psl-agent__photo" src="<?php echo esc_url( $agent['avatarUrl'] ); ?>" alt="<?php echo esc_attr( (string) ( $agent['name'] ?? '' ) ); ?>" width="96" height="96" loading="lazy" decoding="async">
		<?php endif; ?>
		<div class="psl-agent__body">
			<?php if ( ! empty( $agent['name'] ) ) : ?>
				<p class="psl-agent__name"><?php echo esc_html( $agent['name'] ); ?></p>
			<?php endif; ?>
			<?php if ( ! empty( $agent['position'] ) ) : ?>
				<p class="psl-agent__position"><?php echo esc_html( $agent['position'] ); ?></p>
			<?php endif; ?>
			<?php if ( $view['allowContact'] ) : ?>
				<ul class="psl-agent__channels">
					<?php if ( ! empty( $agent['phone'] ) ) : ?>
						<li><span class="psl-agent__channel-label">Telefon</span> <a href="<?php echo esc_url( $tel( $agent['phone'] ), [ 'tel' ] ); ?>"><?php echo esc_html( $agent['phone'] ); ?></a></li>
					<?php endif; ?>
					<?php if ( ! empty( $agent['cell'] ) ) : ?>
						<li><span class="psl-agent__channel-label">Mobil</span> <a href="<?php echo esc_url( $tel( $agent['cell'] ), [ 'tel' ] ); ?>"><?php echo esc_html( $agent['cell'] ); ?></a></li>
					<?php endif; ?>
					<?php if ( ! empty( $agent['email'] ) ) : ?>
						<li><span class="psl-agent__channel-label">E-Mail</span> <a href="<?php echo esc_url( 'mailto:' . $agent['email'], [ 'mailto' ] ); ?>"><?php echo esc_html( $agent['email'] ); ?></a></li>
					<?php endif; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</section>
