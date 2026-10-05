<?php

namespace PropstackLite\Leads;

use PropstackLite\Settings;

/**
 * LeadSink für Version 1: Contact Form 7 versendet die Anfrage als HTML-Mail an die konfigurierte
 * Propstack-Empfangsadresse.
 *
 * Eingriff (nur für das konfigurierte Formular, nur im Speicher für diese Anfrage – nichts wird gespeichert):
 * - Mail 1: Empfänger = Propstack-Adresse aus den Einstellungen, HTML-Modus an, optional BCC.
 * - Mail-Body: enthält den Spezial-Mail-Tag [_psl_propstack_block]; fehlt er, wird er vorangestellt.
 *   CF7 ersetzt Spezial-Tags in einem Durchgang ohne erneutes Tag-Parsing → Benutzereingaben können
 *   keine weiteren CF7-Tags auslösen. Escaping übernimmt der InquiryMailFormatter.
 * - Mail 2 (Autoresponder), Absender und weitere Header bleiben unverändert (CF7-Konfiguration).
 */
final class Cf7MailLeadSink implements LeadSink {

	public const MAIL_TAG = '_psl_propstack_block';

	private string $block = '';

	public function __construct(
		private \WPCF7_ContactForm $form,
		private Settings $settings,
		private InquiryMailFormatter $formatter,
		private ?string $locale = null
	) {}

	public function deliver( LeadContext $lead ): void {
		$to = $this->settings->inquiryEmail();
		if ( '' === $to || ! is_email( $to ) ) {
			throw new LeadException( LeadException::NOT_CONFIGURED );
		}

		$this->block = $this->formatter->format( $lead, $this->settings->customFieldMap(), $this->locale );

		$mail              = (array) $this->form->prop( 'mail' );
		$mail['recipient'] = $to;
		$mail['use_html']  = true;
		$body              = (string) ( $mail['body'] ?? '' );
		if ( ! str_contains( $body, '[' . self::MAIL_TAG . ']' ) ) {
			$body = '[' . self::MAIL_TAG . "]\n\n" . $body;
		}
		$mail['body'] = $body;

		$bcc = $this->settings->inquiryBcc();
		if ( '' !== $bcc && is_email( $bcc ) ) {
			$headers                    = trim( (string) ( $mail['additional_headers'] ?? '' ) );
			$mail['additional_headers'] = ( '' === $headers ? '' : $headers . "\n" ) . 'Bcc: ' . $bcc;
		}

		$this->form->set_properties( [ 'mail' => $mail ] );
	}

	/** HTML-Block für den Spezial-Mail-Tag (bereits escaped). */
	public function block(): string {
		return $this->block;
	}
}
