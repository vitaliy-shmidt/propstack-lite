/*!
 * Propstack Listings Lite – Immobilienanfrage: Attribution ans Formular übergeben und Conversion-Event.
 * Nur auf Detailseiten mit Anfrageformular. Setzt psl-tracking.js (window.PSLTracking) voraus.
 *
 * - Attribution: Hidden Field psl_attr wird nur bei aktuellem Marketing-Consent befüllt (beim Laden,
 *   bei Consent-Änderung und direkt vor dem Absenden); sonst leer.
 * - Event `property_lead` nur nach CF7 `wpcf7mailsent` des konfigurierten Formulars UND nur mit
 *   serverseitig bestätigten Lead-Daten (apiResponse.psl_lead), Consent und aktivierter Option.
 *   Pro Lead-ID höchstens einmal (sessionStorage + Speicher). Keine personenbezogenen Daten.
 * - Die Anfrage selbst hängt nie von diesem Skript ab.
 */
( function ( w, d ) {
	'use strict';

	var cfg = w.pslLeadConfig || {};
	var FIELD = 'psl_attr';
	var STORE_KEY = 'psl_lead_events';
	var UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
	var memory = {};

	function api() {
		return w.PSLTracking || null;
	}
	function consent() {
		var a = api();
		return !! ( a && a.hasConsent() );
	}
	function isOurForm( form ) {
		var input = form && form.querySelector && form.querySelector( 'input[name="_wpcf7"]' );
		return !! input && parseInt( input.value, 10 ) === parseInt( cfg.formId, 10 );
	}
	function attributionJson() {
		if ( ! cfg.attribution || ! consent() ) {
			return '';
		}
		var a = api().attribution();
		return a ? JSON.stringify( a ) : '';
	}
	function fill() {
		var value = attributionJson();
		var forms = d.querySelectorAll( 'form.wpcf7-form' );
		for ( var i = 0; i < forms.length; i++ ) {
			var input = isOurForm( forms[ i ] ) && forms[ i ].querySelector( 'input[name="' + FIELD + '"]' );
			if ( input ) {
				input.value = value;
			}
		}
	}

	function alreadySent( id ) {
		if ( memory[ id ] ) {
			return true;
		}
		try {
			return JSON.parse( w.sessionStorage.getItem( STORE_KEY ) || '[]' ).indexOf( id ) !== -1;
		} catch ( e ) {
			return false;
		}
	}
	function markSent( id ) {
		memory[ id ] = true;
		try {
			var list = JSON.parse( w.sessionStorage.getItem( STORE_KEY ) || '[]' );
			list.push( id );
			w.sessionStorage.setItem( STORE_KEY, JSON.stringify( list.slice( -20 ) ) );
		} catch ( e ) {}
	}

	function str( v ) {
		return typeof v === 'string' ? v.slice( 0, 100 ) : '';
	}

	function onMailSent( e ) {
		var detail = e.detail || {};
		if ( parseInt( detail.contactFormId, 10 ) !== parseInt( cfg.formId, 10 ) ) {
			return; // anderes CF7-Formular
		}
		var lead = detail.apiResponse && detail.apiResponse.psl_lead;
		if ( ! lead || ! UUID.test( lead.lead_id ) || ! ( lead.property_id > 0 ) ) {
			return; // keine serverseitige Bestätigung
		}
		if ( ! cfg.dataLayer || ! consent() || alreadySent( lead.lead_id ) ) {
			return;
		}
		markSent( lead.lead_id );

		var event = {
			event: 'property_lead',
			lead_id: lead.lead_id,
			property_id: lead.property_id,
			marketing_type: str( lead.marketing_type ),
			property_type: str( lead.property_type ),
			property_city: str( lead.property_city ),
			lead_type: 'property_inquiry'
		};
		var a = cfg.attribution ? api().attribution() : null;
		if ( a && a.f && a.l ) {
			event.attribution = {
				first_source: a.f.s || '',
				first_medium: a.f.m || '',
				first_campaign: a.f.c || '',
				first_channel: a.f.ch || '',
				last_source: a.l.s || '',
				last_medium: a.l.m || '',
				last_campaign: a.l.c || '',
				last_channel: a.l.ch || ''
			};
		}
		var name = cfg.dataLayerName || 'dataLayer';
		w[ name ] = w[ name ] || [];
		w[ name ].push( event );
	}

	d.addEventListener( 'psl:consent-changed', fill );
	// Capture-Phase: läuft vor dem Submit-Handler von CF7, der die FormData erzeugt.
	d.addEventListener( 'submit', function ( e ) {
		if ( isOurForm( e.target ) ) {
			fill();
		}
	}, true );
	d.addEventListener( 'wpcf7mailsent', onMailSent );
	fill();
}( window, document ) );
