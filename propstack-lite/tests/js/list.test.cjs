// Tests des Such-Skripts (assets/js/psl-list.js) – ohne Abhängigkeiten:
//   node --test tests/js/
'use strict';
const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const list = require( '../../assets/js/psl-list.js' );

test( 'leere Felder werden nicht gesendet', () => {
	assert.equal( list.shouldSubmit( '', '' ), false );
	assert.equal( list.shouldSubmit( '   ', '' ), false );
	assert.equal( list.shouldSubmit( null, '' ), false );
	assert.equal( list.shouldSubmit( undefined, null ), false );
} );

test( 'Standardwerte (Sortierung, Seitengröße) werden nicht gesendet', () => {
	assert.equal( list.shouldSubmit( 'newest', 'newest' ), false );
	assert.equal( list.shouldSubmit( '12', '12' ), false );
} );

test( 'gesetzte Werte werden gesendet', () => {
	assert.equal( list.shouldSubmit( 'Berlin', '' ), true );
	assert.equal( list.shouldSubmit( 'price_asc', 'newest' ), true );
	assert.equal( list.shouldSubmit( '24', '12' ), true );
	assert.equal( list.shouldSubmit( 0, '' ), true, '0 ist ein Wert – der Server verwirft ihn' );
} );
