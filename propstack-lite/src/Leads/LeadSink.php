<?php

namespace PropstackLite\Leads;

/**
 * Ausgabekanal für verifizierte Immobilienanfragen.
 *
 * Version 1: Cf7MailLeadSink (Contact Form 7 versendet die Mail im Propstack-Format).
 * Geplant/optional: PropstackApiLeadSink (POST /v1/contacts, /v1/activities) – nicht implementiert.
 */
interface LeadSink {

	/** @throws LeadException wenn die Anfrage nicht weitergeleitet werden kann */
	public function deliver( LeadContext $lead ): void;
}
