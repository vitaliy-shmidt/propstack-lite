/*!
 * Propstack Listings Lite – Attribution (seitenweit, nur bei aktivierter Attribution/dataLayer und
 * Consent-Provider ≠ „none“). Vanilla JS, keine Abhängigkeiten, keine externen Requests.
 *
 * - Speichert nur mit Marketing-Consent einen First-Party-Cookie (Standard 90 Tage):
 *   First Touch (bleibt) und Last Non-Direct Touch (Direktaufrufe überschreiben ihn nicht).
 * - Keine personenbezogenen Daten, keine vollständigen URLs/Querystrings, kein Fingerprinting.
 * - API: window.PSLTracking.setConsent(bool), .hasConsent(), .attribution()
 */
( function ( root, factory ) {
	var core = factory();
	if ( typeof module === 'object' && module.exports ) {
		module.exports = core; // Node-Tests
	} else {
		core.boot( root, root.document, root.pslTrackingConfig || {} );
	}
}( typeof window !== 'undefined' ? window : this, function () {
	'use strict';

	var SEARCH = [ 'google', 'bing', 'duckduckgo', 'ecosia', 'yahoo', 'qwant', 'startpage', 'yandex', 'baidu' ];
	var SOCIAL = [ 'facebook', 'fb', 'instagram', 'linkedin', 'lnkd', 'twitter', 'x', 't', 'pinterest', 'youtube', 'tiktok', 'xing', 'threads', 'meta' ];
	var PAID = [ 'cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'sea', 'cpm' ];
	var PAID_SOCIAL = [ 'paid_social', 'paidsocial', 'social_paid', 'paid-social', 'social-paid' ];
	var ORGANIC_SOCIAL = [ 'social', 'organic_social', 'social-network', 'social_network', 'sm' ];

	/* Bereinigung – identisch mit Tracking\Touch (PHP). */
	function text( v ) {
		return String( v == null ? '' : v ).replace( /[\u0000-\u001F\u007F<>"'`\\]+/g, '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 100 );
	}
	function clickId( v ) {
		return typeof v === 'string' && /^[A-Za-z0-9_.\-]{1,255}$/.test( v ) ? v : '';
	}
	function path( v ) {
		return typeof v === 'string' && /^\/[A-Za-z0-9\/_\-.~%]{0,199}$/.test( v ) ? v : '';
	}
	function host( v ) {
		v = String( v || '' ).toLowerCase().replace( /^www\./, '' );
		return /^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/.test( v ) && v.length <= 253 ? v : '';
	}
	/** Zweitletztes Label: „google“ aus google.de, „facebook“ aus l.facebook.com. */
	function brand( h ) {
		var parts = h.split( '.' );
		return parts.length >= 2 ? parts[ parts.length - 2 ] : h;
	}
	function inList( list, v ) {
		return list.indexOf( v ) !== -1;
	}

	function channel( t ) {
		var m = t.m;
		var s = brand( t.s.indexOf( '.' ) > -1 ? t.s : t.s + '.x' );
		if ( inList( PAID_SOCIAL, m ) || ( inList( PAID, m ) && inList( SOCIAL, s ) ) ) {
			return 'paid_social';
		}
		if ( inList( PAID, m ) || ( ! m && ( t.g || t.gb || t.wb ) ) ) {
			return 'paid_search';
		}
		if ( inList( ORGANIC_SOCIAL, m ) ) {
			return 'organic_social';
		}
		if ( m === 'organic' ) {
			return 'organic_search';
		}
		if ( m === 'referral' ) {
			return 'referral';
		}
		return 'other';
	}

	/** Liest einen Query-Parameter ohne URLSearchParams-Abhängigkeit. */
	function param( search, name ) {
		var re = new RegExp( '[?&]' + name + '=([^&#]*)' );
		var m = re.exec( search || '' );
		if ( ! m ) {
			return '';
		}
		try {
			return decodeURIComponent( m[ 1 ].replace( /\+/g, ' ' ) );
		} catch ( e ) {
			return '';
		}
	}

	/**
	 * Touch des aktuellen Seitenaufrufs oder null (= direkt bzw. interne Navigation).
	 * UTM-Werte haben Vorrang vor Klick-IDs und Referrer.
	 */
	function touchFrom( loc, referrer, now ) {
		var search = loc.search || '';
		var t = {
			s: text( param( search, 'utm_source' ) ).toLowerCase(),
			m: text( param( search, 'utm_medium' ) ).toLowerCase(),
			c: text( param( search, 'utm_campaign' ) ),
			ct: text( param( search, 'utm_content' ) ),
			t: text( param( search, 'utm_term' ) ),
			g: clickId( param( search, 'gclid' ) ),
			gb: clickId( param( search, 'gbraid' ) ),
			wb: clickId( param( search, 'wbraid' ) )
		};
		var ref = '';
		try {
			ref = referrer ? host( new URL( referrer ).hostname ) : '';
		} catch ( e ) {
			ref = '';
		}
		var own = host( loc.hostname );
		if ( ref && ref === own ) {
			ref = ''; // interne Navigation
		}
		var hasUtm = !! ( t.s || t.m || t.c );
		var hasClick = !! ( t.g || t.gb || t.wb );

		if ( ! hasUtm && ! hasClick && ! ref ) {
			return null; // direkt
		}
		if ( ! hasUtm && hasClick ) {
			t.s = 'google';
			t.m = 'cpc';
		} else if ( ! hasUtm && ref ) {
			var b = brand( ref );
			if ( inList( SEARCH, b ) ) {
				t.s = b;
				t.m = 'organic';
			} else if ( inList( SOCIAL, b ) ) {
				t.s = b;
				t.m = 'social';
			} else {
				t.s = ref;
				t.m = 'referral';
			}
		} else if ( ! t.s ) {
			t.s = '(not set)';
		}
		var out = { s: t.s, m: t.m, c: t.c, ct: t.ct, t: t.t, g: t.g, gb: t.gb, wb: t.wb };
		out.ch = channel( out );
		out.lp = path( loc.pathname ) || '/';
		out.rh = ref;
		out.ts = now;
		// leere Felder weglassen (Cookie-Größe)
		Object.keys( out ).forEach( function ( k ) {
			if ( out[ k ] === '' ) {
				delete out[ k ];
			}
		} );
		return out;
	}

	function fresh( t, now, ttl ) {
		return t && typeof t === 'object' && typeof t.ts === 'number' && t.ts > now - ttl && t.ts <= now + 300 ? t : null;
	}

	/** Neuer Zustand: abgelaufene Touches verwerfen, First bleibt, Last = letzter nicht-direkter Touch. */
	function merge( state, touch, now, ttl ) {
		var f = fresh( state && state.f, now, ttl );
		var l = fresh( state && state.l, now, ttl );
		if ( touch ) {
			f = f || touch;
			l = touch;
		}
		return f || l ? { v: 1, f: f || l, l: l || f } : null;
	}

	function parse( cookieString, name ) {
		var parts = String( cookieString || '' ).split( /;\s*/ );
		for ( var i = 0; i < parts.length; i++ ) {
			if ( parts[ i ].indexOf( name + '=' ) === 0 ) {
				try {
					var data = JSON.parse( decodeURIComponent( parts[ i ].slice( name.length + 1 ) ) );
					return data && data.v === 1 ? data : null;
				} catch ( e ) {
					return null;
				}
			}
		}
		return null;
	}

	function boot( w, d, cfg ) {
		var name = cfg.cookie || 'psl_attr';
		var ttl = ( cfg.ttlDays || 90 ) * 86400;
		var consentType = ( cfg.consent && cfg.consent.type ) || 'none';
		var consent = consentType === 'api' && !! ( w.pslConsent && w.pslConsent.marketing === true );
		var captured = false;
		var now = function () {
			return Math.floor( Date.now() / 1000 );
		};
		var cookieAttrs = '; Path=' + ( cfg.path || '/' ) + '; SameSite=Lax' + ( cfg.secure ? '; Secure' : '' );

		function write( state ) {
			var value = encodeURIComponent( JSON.stringify( state ) );
			if ( value.length > 3000 ) {
				return; // Sicherheitsgrenze
			}
			d.cookie = name + '=' + value + '; Max-Age=' + ttl + cookieAttrs;
		}
		function remove() {
			d.cookie = name + '=; Max-Age=0' + cookieAttrs;
		}
		function capture() {
			if ( captured || ! consent || ! cfg.attribution ) {
				return;
			}
			captured = true;
			var t = now();
			var state = merge( parse( d.cookie, name ), touchFrom( w.location, d.referrer, t ), t, ttl );
			if ( state ) {
				write( state );
			}
		}
		function setConsent( value ) {
			consent = consentType === 'api' && value === true;
			if ( consent ) {
				capture();
			} else {
				remove(); // Widerruf: vorhandene Attribution wird gelöscht
			}
			try {
				d.dispatchEvent( new w.CustomEvent( 'psl:consent-changed', { detail: { marketing: consent } } ) );
			} catch ( e ) {}
		}

		d.addEventListener( 'psl:consent', function ( e ) {
			setConsent( !! ( e.detail && e.detail.marketing === true ) );
		} );

		w.PSLTracking = {
			version: 1,
			setConsent: setConsent,
			hasConsent: function () {
				return consent;
			},
			/** Gültige Attribution (nur mit Consent), sonst null. */
			attribution: function () {
				if ( ! consent ) {
					return null;
				}
				var t = now();
				return merge( parse( d.cookie, name ), null, t, ttl );
			}
		};
		if ( consentType === 'none' ) {
			remove(); // kein Consent-System: nie Marketingdaten speichern
		}
		capture();
	}

	return { text: text, clickId: clickId, path: path, host: host, channel: channel, touchFrom: touchFrom, merge: merge, parse: parse, boot: boot };
} ) );
