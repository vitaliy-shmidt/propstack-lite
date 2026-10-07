<?php
/**
 * Such- und Sortierformular der Immobilienübersicht – normales GET-Formular, ohne JavaScript nutzbar.
 *
 * Überschreibbar unter {theme}/propstack-lite/parts/list-filters.php. Alle Werte sind unescaped.
 * Feldnamen müssen den Parametern aus ListingRequest::PARAMS entsprechen (Server-Whitelist).
 * Mobil: <details> ist ohne JavaScript immer geöffnet; psl-list.js klappt es auf kleinen
 * Bildschirmen ohne aktive Filter ein (natives, zugängliches Auf-/Zuklappen über <summary>).
 *
 * @var array $vars form, id
 */

defined( 'ABSPATH' ) || exit;

$psl_form = $vars['form'];
$psl_id   = $vars['id'];
?>
<form class="psl-search" method="get" action="<?php echo esc_url( $psl_form['action'] ); ?>" role="search" aria-label="Immobiliensuche" data-psl-search>
	<?php foreach ( $psl_form['hidden'] as $psl_name => $psl_value ) : ?>
		<input type="hidden" name="<?php echo esc_attr( $psl_name ); ?>" value="<?php echo esc_attr( $psl_value ); ?>">
	<?php endforeach; ?>

	<?php if ( ! empty( $psl_form['fields'] ) ) : ?>
		<details class="psl-search__panel" open data-psl-filter-panel data-active="<?php echo esc_attr( (string) $psl_form['activeCount'] ); ?>">
			<summary class="psl-search__toggle">
				Filter
				<?php if ( $psl_form['activeCount'] > 0 ) : ?>
					<span class="psl-search__active">(<?php echo esc_html( 1 === $psl_form['activeCount'] ? '1 aktiv' : $psl_form['activeCount'] . ' aktiv' ); ?>)</span>
				<?php endif; ?>
			</summary>

			<div class="psl-search__fields">
				<?php foreach ( $psl_form['fields'] as $psl_field ) : ?>
					<div class="psl-field psl-field--<?php echo esc_attr( $psl_field['name'] ); ?>">
						<label class="psl-field__label" for="<?php echo esc_attr( $psl_field['id'] ); ?>"><?php echo esc_html( $psl_field['label'] ); ?></label>
						<?php if ( 'select' === $psl_field['type'] ) : ?>
							<select class="psl-field__control" id="<?php echo esc_attr( $psl_field['id'] ); ?>" name="<?php echo esc_attr( $psl_field['name'] ); ?>" data-default="">
								<?php foreach ( $psl_field['options'] as $psl_value => $psl_label ) : ?>
									<option value="<?php echo esc_attr( (string) $psl_value ); ?>"<?php selected( (string) $psl_value, $psl_field['value'] ); ?>><?php echo esc_html( $psl_label ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<span class="psl-field__input">
								<input class="psl-field__control" type="number" id="<?php echo esc_attr( $psl_field['id'] ); ?>" name="<?php echo esc_attr( $psl_field['name'] ); ?>" value="<?php echo esc_attr( $psl_field['value'] ); ?>" min="1" step="1" inputmode="numeric" data-default=""<?php echo $psl_field['describedBy'] ? ' aria-describedby="' . esc_attr( $psl_field['describedBy'] ) . '"' : ''; ?>>
								<span class="psl-field__unit" aria-hidden="true"><?php echo esc_html( $psl_field['unit'] ); ?></span>
							</span>
							<?php if ( $psl_field['hint'] ) : ?>
								<span class="psl-field__hint" id="<?php echo esc_attr( $psl_field['describedBy'] ); ?>"><?php echo esc_html( $psl_field['hint'] ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="psl-search__actions">
				<button type="submit" class="psl-button psl-search__submit">Immobilien anzeigen</button>
				<?php if ( $psl_form['resetUrl'] ) : ?>
					<a class="psl-search__reset" href="<?php echo esc_url( $psl_form['resetUrl'] ); ?>">Filter zurücksetzen</a>
				<?php endif; ?>
			</div>
		</details>
	<?php endif; ?>

	<?php if ( ! empty( $psl_form['sort'] ) ) : ?>
		<div class="psl-search__sort">
			<?php foreach ( $psl_form['sort'] as $psl_select ) : ?>
				<div class="psl-field psl-field--<?php echo esc_attr( $psl_select['name'] ); ?>">
					<label class="psl-field__label" for="<?php echo esc_attr( $psl_select['id'] ); ?>"><?php echo esc_html( $psl_select['label'] ); ?></label>
					<select class="psl-field__control" id="<?php echo esc_attr( $psl_select['id'] ); ?>" name="<?php echo esc_attr( $psl_select['name'] ); ?>" data-default="<?php echo esc_attr( $psl_select['default'] ); ?>">
						<?php foreach ( $psl_select['options'] as $psl_value => $psl_label ) : ?>
							<option value="<?php echo esc_attr( (string) $psl_value ); ?>"<?php selected( (string) $psl_value, $psl_select['value'] ); ?>><?php echo esc_html( $psl_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endforeach; ?>
			<button type="submit" class="psl-button psl-button--secondary psl-search__apply">Sortieren</button>
			<?php if ( empty( $psl_form['fields'] ) && $psl_form['resetUrl'] ) : ?>
				<a class="psl-search__reset" href="<?php echo esc_url( $psl_form['resetUrl'] ); ?>">Filter zurücksetzen</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</form>
