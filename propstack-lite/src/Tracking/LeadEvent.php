<?php

namespace PropstackLite\Tracking;

use PropstackLite\Leads\LeadContext;

/**
 * Serverseitig bestätigte Daten für das Conversion-Event `property_lead`.
 *
 * Wird nur nach erfolgreichem Versand (CF7 `mail_sent`) an die CF7-REST-Antwort gehängt
 * (`psl_lead`) und von assets/js/psl-lead-event.js in den dataLayer übernommen.
 * Enthält ausschließlich öffentliche Objektdaten und die Lead-ID – keine Formulardaten,
 * keine Adresse, keine Maklerdaten, keine Klick-IDs.
 */
final class LeadEvent {

	public const EVENT     = 'property_lead';
	public const LEAD_TYPE = 'property_inquiry';

	/** @return array{lead_id: string, property_id: int, marketing_type: string, property_type: string, property_city: string, lead_type: string} */
	public static function payload( LeadContext $lead ): array {
		$p = $lead->property;
		return [
			'lead_id'        => $lead->leadId,
			'property_id'    => $p->id,
			'marketing_type' => (string) ( $p->marketingType ?? '' ),
			'property_type'  => (string) ( $p->rsType ?? '' ),
			'property_city'  => (string) ( $p->address->city ?? '' ),
			'lead_type'      => self::LEAD_TYPE,
		];
	}
}
