<?php

namespace PropstackLite\Leads;

/**
 * Erzeugt den von Propstack dokumentierten HTML-Block `<div id="ps-kontaktanfrage">` mit
 * `<span id="…">`-Feldern (siehe docs/leads.md). Alle Werte werden HTML-escaped.
 *
 * - `property_id`/`project_id` stammen aus dem verifizierten Property (LeadContext).
 * - `client_accept_contact` nur bei tatsächlicher Zustimmung ("ja").
 * - Keine Newsletter-/Mailing-Einwilligungen.
 * - `client_cf_*` nur für ausdrücklich zugeordnete Attributionsschlüssel (Standard: keine).
 *
 * Ohne WordPress testbar.
 */
final class InquiryMailFormatter {

	/**
	 * @param array<string, string> $customFieldMap Attributionsschlüssel => Propstack-Feldname (ohne client_cf_)
	 */
	public function format( LeadContext $lead, array $customFieldMap = [], ?string $locale = null ): string {
		$d     = $lead->data;
		$p     = $lead->property;
		$lines = [];

		if ( null !== $d->salutation ) {
			$lines[] = self::row( 'Anrede', 'client_salutation', $d->salutation );
		}
		$lines[] = self::row( 'Vorname', 'client_first_name', $d->firstName );
		$lines[] = self::row( 'Nachname', 'client_last_name', $d->lastName );
		$lines[] = self::row( 'E-Mail', 'client_email', $d->email );
		if ( '' !== $d->phone ) {
			$lines[] = self::row( 'Telefon', 'client_phone', $d->phone );
		}
		if ( $d->consent ) {
			$lines[] = self::row( 'Kontakterlaubnis', 'client_accept_contact', 'ja' );
		}
		if ( null !== $locale && in_array( $locale, [ 'de', 'en', 'es' ], true ) ) {
			$lines[] = self::row( 'Sprache', 'client_locale', $locale );
		}
		$lines[] = self::row( 'Objekt-ID', 'property_id', (string) $p->id );
		if ( null !== $p->projectId ) {
			$lines[] = self::row( 'Projekt-ID', 'project_id', (string) $p->projectId );
		}
		foreach ( $customFieldMap as $key => $field ) {
			$value = $lead->attribution[ $key ] ?? '';
			if ( '' !== $value && preg_match( '/^[a-z0-9_]{1,64}$/', $field ) ) {
				$lines[] = self::row( $field, 'client_cf_' . $field, $value );
			}
		}
		$lines[] = '<p>Nachricht:</p>';
		$lines[] = '<p><span id="body">' . nl2br( self::e( $d->message ), false ) . '</span></p>';

		return "<div id=\"ps-kontaktanfrage\">\n" . implode( "\n", $lines ) . "\n</div>\n"
			. '<p>Anfrage zur Immobilie: ' . self::e( (string) ( $p->title ?? ( '#' . $p->id ) ) ) . '<br>'
			. '<a href="' . self::e( $lead->propertyUrl ) . '">' . self::e( $lead->propertyUrl ) . '</a></p>' . "\n";
	}

	private static function row( string $label, string $id, string $value ): string {
		return '<p>' . self::e( $label ) . ': <span id="' . self::e( $id ) . '">' . self::e( $value ) . '</span></p>';
	}

	private static function e( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
