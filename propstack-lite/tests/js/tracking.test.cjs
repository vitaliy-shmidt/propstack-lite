// Tests der Attributionslogik (assets/js/psl-tracking.js) – ohne Abhängigkeiten:
//   node --test tests/js/
'use strict';
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const core = require( '../../assets/js/psl-tracking.js' );

const DAY = 86400;
const TTL = 90 * DAY;
const NOW = 1_800_000_000;
const loc = ( search = '', pathname = '/immobilien/', hostname = 'www.picaflor.example' ) => ( { search, pathname, hostname } );

/** Simuliert eine Besuchsfolge: [[search, referrer, ts], …] → Endzustand. */
function visits( list, ttl = TTL ) {
	let state = null;
	for ( const [ search, ref, ts ] of list ) {
		state = core.merge( state, core.touchFrom( loc( search ), ref, ts ), ts, ttl );
	}
	return state;
}

test( 'Direktaufruf erzeugt keinen Touch', () => {
	assert.equal( core.touchFrom( loc(), '', NOW ), null );
	assert.equal( visits( [ [ '', '', NOW ] ] ), null );
} );

test( 'erster UTM-Besuch setzt First und Last', () => {
	const s = visits( [ [ '?utm_source=Google&utm_medium=CPC&utm_campaign=wohnung_berlin&utm_term=3+zimmer', '', NOW ] ] );
	assert.deepEqual( [ s.f.s, s.f.m, s.f.c, s.f.t, s.f.ch, s.f.lp ], [ 'google', 'cpc', 'wohnung_berlin', '3 zimmer', 'paid_search', '/immobilien/' ] );
	assert.deepEqual( s.l, s.f );
} );

test( 'späterer Direktaufruf ändert nichts (Last Non-Direct bleibt)', () => {
	const first = visits( [ [ '?utm_source=google&utm_medium=cpc', '', NOW ] ] );
	const after = visits( [ [ '?utm_source=google&utm_medium=cpc', '', NOW ], [ '', '', NOW + 3600 ] ] );
	assert.deepEqual( after, first );
} );

test( 'anderer UTM-Besuch: First bleibt, Last wechselt (Beispiel Google Ads → Facebook → Direct)', () => {
	const s = visits( [
		[ '?utm_source=google&utm_medium=cpc&utm_campaign=wohnung_berlin', '', NOW ],
		[ '?utm_source=facebook&utm_medium=paid_social&utm_campaign=retargeting', '', NOW + DAY ],
		[ '', '', NOW + 2 * DAY ],
	] );
	assert.equal( s.f.s, 'google' );
	assert.equal( s.f.c, 'wohnung_berlin' );
	assert.equal( s.l.s, 'facebook' );
	assert.equal( s.l.ch, 'paid_social' );
	assert.equal( s.l.c, 'retargeting' );
} );

test( 'Klick-IDs ohne UTM: gclid, gbraid, wbraid → google/cpc/paid_search', () => {
	for ( const p of [ 'gclid', 'gbraid', 'wbraid' ] ) {
		const t = core.touchFrom( loc( `?${ p }=Cj0KCQ_test-123.x` ), '', NOW );
		assert.equal( t.s, 'google', p );
		assert.equal( t.m, 'cpc', p );
		assert.equal( t.ch, 'paid_search', p );
		assert.equal( t[ { gclid: 'g', gbraid: 'gb', wbraid: 'wb' }[ p ] ], 'Cj0KCQ_test-123.x', p );
	}
} );

test( 'UTM hat Vorrang vor Klick-ID und Referrer', () => {
	const t = core.touchFrom( loc( '?utm_source=newsletter&utm_medium=email&gclid=abc' ), 'https://www.google.de/', NOW );
	assert.equal( t.s, 'newsletter' );
	assert.equal( t.m, 'email' );
	assert.equal( t.ch, 'other' );
	assert.equal( t.g, 'abc' );
	assert.equal( t.rh, 'google.de' );
} );

test( 'Referrer: Suchmaschine, Social, Referral, nur Domain gespeichert', () => {
	const g = core.touchFrom( loc(), 'https://www.google.de/search?q=wohnung+berlin+privat', NOW );
	assert.deepEqual( [ g.s, g.m, g.ch, g.rh ], [ 'google', 'organic', 'organic_search', 'google.de' ] );
	assert.ok( ! JSON.stringify( g ).includes( 'search?q' ), 'kein Pfad/Query des Referrers' );
	const f = core.touchFrom( loc(), 'https://l.facebook.com/l.php?u=x', NOW );
	assert.deepEqual( [ f.s, f.m, f.ch ], [ 'facebook', 'social', 'organic_social' ] );
	const r = core.touchFrom( loc(), 'https://www.immo-portal.example/liste?id=5', NOW );
	assert.deepEqual( [ r.s, r.m, r.ch, r.rh ], [ 'immo-portal.example', 'referral', 'referral', 'immo-portal.example' ] );
} );

test( 'interne Navigation (eigener Host) gilt als direkt', () => {
	assert.equal( core.touchFrom( loc(), 'https://www.picaflor.example/immobilien/', NOW ), null );
} );

test( 'TTL: abgelaufener First Touch wird ersetzt, abgelaufene Daten verworfen', () => {
	const s = visits( [
		[ '?utm_source=google&utm_medium=cpc', '', NOW ],
		[ '?utm_source=bing&utm_medium=cpc', '', NOW + 91 * DAY ],
	] );
	assert.equal( s.f.s, 'bing', 'First Touch nach Ablauf neu' );
	const expired = core.merge( visits( [ [ '?utm_source=google', '', NOW ] ] ), null, NOW + 91 * DAY, TTL );
	assert.equal( expired, null );
	const within = core.merge( visits( [ [ '?utm_source=google', '', NOW ] ] ), null, NOW + 89 * DAY, TTL );
	assert.equal( within.f.s, 'google' );
} );

test( 'ungültige Werte werden verworfen bzw. bereinigt', () => {
	const t = core.touchFrom( loc( '?utm_source=%20%20&utm_medium=cpc&gclid=abc%3Cdef&wbraid=' + 'x'.repeat( 300 ) ), '', NOW );
	assert.equal( t.s, '(not set)', 'leere Quelle' );
	assert.equal( t.g, undefined, 'gclid mit ungültigen Zeichen' );
	assert.equal( t.wb, undefined, 'zu lange Klick-ID' );
	assert.equal( core.touchFrom( loc( '?utm_source=%E0%A4%A' ), '', NOW ), null, 'kaputte Kodierung → kein Wert' );
	assert.equal( core.text( 'a'.repeat( 150 ) ).length, 100 );
	assert.equal( core.path( '/a?b=1' ), '' );
	assert.equal( core.host( 'evil.example/path' ), '' );
} );

test( 'XSS-Payload in UTM wird entschärft', () => {
	const t = core.touchFrom( loc( '?utm_source=%3Cscript%3Ealert(1)%3C%2Fscript%3E&utm_campaign=%22onload%3D%22x%27%60' ), '', NOW );
	const json = JSON.stringify( t );
	for ( const bad of [ '<', '>', '\\"onload', "'", '`' ] ) {
		assert.ok( ! json.includes( bad ), bad );
	}
	assert.equal( t.s, 'scriptalert(1)/script' );
} );

test( 'keine personenbezogenen oder Geräte-Daten im Touch', () => {
	const t = core.touchFrom( loc( '?utm_source=google&email=max%40example.com&name=Max' ), 'https://www.google.de/', NOW );
	assert.deepEqual( Object.keys( t ).sort(), [ 'ch', 'lp', 'rh', 's', 'ts' ] );
} );

/* ------------------------------------------------- boot(): Consent und Cookie */

function fakeBrowser( search = '', referrer = '', preset = null ) {
	const jar = {};
	const listeners = {};
	const document = {
		referrer,
		get cookie() {
			return Object.entries( jar ).map( ( [ k, v ] ) => `${ k }=${ v }` ).join( '; ' );
		},
		set cookie( str ) {
			const [ pair, ...attrs ] = str.split( /;\s*/ );
			const [ k, v ] = pair.split( '=' );
			const maxAge = attrs.find( a => a.startsWith( 'Max-Age=' ) );
			if ( maxAge && Number( maxAge.split( '=' )[ 1 ] ) <= 0 ) {
				delete jar[ k ];
			} else {
				jar[ k ] = v;
			}
			document.lastSet = str;
		},
		addEventListener( type, fn ) {
			( listeners[ type ] ||= [] ).push( fn );
		},
		dispatchEvent( e ) {
			( listeners[ e.type ] || [] ).forEach( fn => fn( e ) );
		},
	};
	class CustomEvent {
		constructor( type, init ) {
			this.type = type;
			this.detail = init?.detail;
		}
	}
	const window = { location: loc( search ), document, CustomEvent, pslConsent: preset };
	return { window, document, jar };
}
const CFG = { cookie: 'psl_attr', ttlDays: 90, path: '/', secure: true, attribution: true };

test( 'Consent-Provider „none“: kein Cookie, vorhandener wird gelöscht', () => {
	const b = fakeBrowser( '?utm_source=google&utm_medium=cpc' );
	b.jar.psl_attr = 'alt';
	core.boot( b.window, b.document, { ...CFG, consent: { type: 'none' } } );
	assert.equal( b.jar.psl_attr, undefined );
	b.window.PSLTracking.setConsent( true );
	assert.equal( b.jar.psl_attr, undefined, 'none ignoriert setConsent' );
	assert.equal( b.window.PSLTracking.attribution(), null );
} );

test( 'JavaScript-API ohne Meldung: kein Consent, kein Cookie', () => {
	const b = fakeBrowser( '?utm_source=google&utm_medium=cpc' );
	core.boot( b.window, b.document, { ...CFG, consent: { type: 'api' } } );
	assert.equal( b.jar.psl_attr, undefined );
	assert.equal( b.window.PSLTracking.hasConsent(), false );
} );

test( 'Consent erteilt → Cookie (First-Party, SameSite=Lax, Secure, Max-Age = TTL); Widerruf löscht', () => {
	const b = fakeBrowser( '?utm_source=google&utm_medium=cpc&gclid=TEST123' );
	core.boot( b.window, b.document, { ...CFG, consent: { type: 'api' } } );
	b.document.dispatchEvent( new b.window.CustomEvent( 'psl:consent', { detail: { marketing: true } } ) );
	assert.ok( b.jar.psl_attr );
	assert.match( b.document.lastSet, /Max-Age=7776000; Path=\/; SameSite=Lax; Secure$/ );
	const a = b.window.PSLTracking.attribution();
	assert.equal( a.f.s, 'google' );
	assert.equal( a.f.g, 'TEST123' );
	b.window.PSLTracking.setConsent( false );
	assert.equal( b.jar.psl_attr, undefined, 'Widerruf löscht die Attribution' );
	assert.equal( b.window.PSLTracking.attribution(), null );
} );

test( 'vorab gesetztes window.pslConsent wird beim Laden berücksichtigt', () => {
	const b = fakeBrowser( '?utm_source=bing&utm_medium=organic', '', { marketing: true } );
	core.boot( b.window, b.document, { ...CFG, consent: { type: 'api' } } );
	assert.equal( b.window.PSLTracking.attribution().f.s, 'bing' );
} );

test( 'Attribution deaktiviert (nur dataLayer): kein Cookie trotz Consent', () => {
	const b = fakeBrowser( '?utm_source=google', '', { marketing: true } );
	core.boot( b.window, b.document, { ...CFG, attribution: false, consent: { type: 'api' } } );
	assert.equal( b.jar.psl_attr, undefined );
	assert.equal( b.window.PSLTracking.hasConsent(), true );
} );
