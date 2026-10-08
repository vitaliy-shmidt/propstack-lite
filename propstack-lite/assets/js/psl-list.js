/**
 * Propstack Listings Lite – Immobiliensuche (Progressive Enhancement, optional).
 *
 * Ohne JavaScript funktioniert alles über das normale GET-Formular. Dieses Skript:
 * 1. schickt leere bzw. Standardwerte nicht mit → kurze, teilbare URLs („?city=Berlin“ statt
 *    „?marketing_type=&property_type=&city=Berlin&…“). Der Server ignoriert solche Werte ohnehin.
 * (Das mobile Einklappen des Filterpanels erledigt ein Inline-Skript im Template vor dem ersten Rendern –
 *  hier per defer wäre es ein Layout-Sprung, gemessen CLS 0,28.)
 * Kein Auto-Submit bei Auswahländerung (WCAG 3.2.2), keine Requests, keine Speicherung.
 */
(function (root) {
	'use strict';

	/** Soll das Feld mitgesendet werden? Leere und Standardwerte nicht. */
	function shouldSubmit(value, defaultValue) {
		var v = String(value == null ? '' : value).trim();
		return v !== '' && v !== String(defaultValue == null ? '' : defaultValue);
	}

	var api = { shouldSubmit: shouldSubmit };

	if (typeof module === 'object' && module.exports) {
		module.exports = api; // Node-Tests
		return;
	}

	var doc = root.document;
	if (!doc || !doc.querySelectorAll) {
		return;
	}

	function enhance(form) {
		form.addEventListener('submit', function () {
			var fields = form.querySelectorAll('[data-default]');
			for (var i = 0; i < fields.length; i++) {
				if (!shouldSubmit(fields[i].value, fields[i].getAttribute('data-default'))) {
					fields[i].disabled = true;
				}
			}
			// Nach Zurück-Navigation (bfcache) wieder aktivieren.
			root.setTimeout(function () {
				for (var j = 0; j < fields.length; j++) {
					fields[j].disabled = false;
				}
			}, 0);
		});
	}

	var forms = doc.querySelectorAll('form[data-psl-search]');
	for (var k = 0; k < forms.length; k++) {
		enhance(forms[k]);
	}
})(typeof window !== 'undefined' ? window : this);
