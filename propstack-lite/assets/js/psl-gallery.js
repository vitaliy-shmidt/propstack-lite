/**
 * Propstack Listings Lite – Galerie-Lightbox (Vanilla JS, natives <dialog>, keine Abhängigkeiten).
 *
 * Progressive Enhancement: Ohne JavaScript (oder ohne <dialog>-Unterstützung) verlinken die
 * Vorschaubilder auf die große Bildversion. Mit JavaScript öffnet sich eine Lightbox:
 * Pfeiltasten/Buttons vor/zurück, ESC schließt (nativ), Wischgesten, Fokus kehrt zum Auslöser zurück.
 * Gruppen: [data-psl-gallery="main"] (Galerie) und [data-psl-gallery="floorplans"] (Grundrisse).
 */
( function () {
	'use strict';

	function init() {
		var dialog = document.querySelector( '[data-psl-lightbox]' );
		if ( ! dialog || typeof dialog.showModal !== 'function' ) {
			return; // Fallback: normale Links auf die großen Bilder.
		}

		var image = dialog.querySelector( '[data-psl-image]' );
		var caption = dialog.querySelector( '[data-psl-caption]' );
		var counter = dialog.querySelector( '[data-psl-counter]' );
		var prev = dialog.querySelector( '[data-psl-prev]' );
		var next = dialog.querySelector( '[data-psl-next]' );
		var close = dialog.querySelector( '[data-psl-close]' );

		var items = [];
		var index = 0;
		var opener = null;

		function collect( group ) {
			return Array.prototype.map.call( group.querySelectorAll( 'a[data-psl-index]' ), function ( link ) {
				return {
					src: link.getAttribute( 'data-psl-full' ),
					srcset: link.getAttribute( 'data-psl-srcset' ) || '',
					alt: link.getAttribute( 'data-psl-alt' ) || '',
					caption: link.getAttribute( 'data-psl-caption' ) || ''
				};
			} );
		}

		function preload( i ) {
			var item = items[ ( i + items.length ) % items.length ];
			if ( item ) {
				var img = new Image();
				img.srcset = item.srcset;
				img.src = item.src;
			}
		}

		function show( i ) {
			if ( ! items.length ) {
				return;
			}
			index = ( i + items.length ) % items.length;
			var item = items[ index ];
			image.removeAttribute( 'srcset' );
			image.src = item.src;
			if ( item.srcset ) {
				image.srcset = item.srcset;
				image.sizes = '100vw';
			}
			image.alt = item.alt;
			caption.textContent = item.caption;
			caption.hidden = ! item.caption;
			counter.textContent = 'Bild ' + ( index + 1 ) + ' von ' + items.length;
			var single = items.length < 2;
			prev.hidden = single;
			next.hidden = single;
			if ( ! single ) {
				preload( index + 1 );
				preload( index - 1 );
			}
		}

		function open( group, i, trigger ) {
			items = collect( group );
			opener = trigger || null;
			show( i );
			dialog.showModal();
			document.documentElement.classList.add( 'psl-lightbox-open' );
			close.focus();
		}

		dialog.addEventListener( 'close', function () {
			document.documentElement.classList.remove( 'psl-lightbox-open' );
			image.removeAttribute( 'src' );
			image.removeAttribute( 'srcset' );
			if ( opener && typeof opener.focus === 'function' ) {
				opener.focus();
			}
		} );

		// Klick auf den abgedunkelten Hintergrund schließt.
		dialog.addEventListener( 'click', function ( event ) {
			if ( event.target === dialog ) {
				dialog.close();
			}
		} );

		prev.addEventListener( 'click', function () {
			show( index - 1 );
		} );
		next.addEventListener( 'click', function () {
			show( index + 1 );
		} );
		close.addEventListener( 'click', function () {
			dialog.close();
		} );

		dialog.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'ArrowLeft' ) {
				event.preventDefault();
				show( index - 1 );
			} else if ( event.key === 'ArrowRight' ) {
				event.preventDefault();
				show( index + 1 );
			} else if ( event.key === 'Tab' ) {
				// Fokus im Dialog halten (Schließen → Zurück → Weiter → Schließen …).
				var focusable = Array.prototype.filter.call( dialog.querySelectorAll( 'button' ), function ( el ) {
					return ! el.hidden;
				} );
				if ( ! focusable.length ) {
					return;
				}
				var first = focusable[ 0 ];
				var last = focusable[ focusable.length - 1 ];
				if ( event.shiftKey && document.activeElement === first ) {
					event.preventDefault();
					last.focus();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					event.preventDefault();
					first.focus();
				}
			}
		} );

		// Wischgesten (Touch/Stift/Maus). Das Bild ist nicht ziehbar, damit kein pointercancel entsteht.
		image.setAttribute( 'draggable', 'false' );
		var startX = null;
		dialog.addEventListener( 'pointerdown', function ( event ) {
			startX = event.clientX;
		} );
		dialog.addEventListener( 'pointercancel', function () {
			startX = null;
		} );
		dialog.addEventListener( 'pointerup', function ( event ) {
			if ( startX === null ) {
				return;
			}
			var dx = event.clientX - startX;
			startX = null;
			if ( Math.abs( dx ) > 50 ) {
				show( dx < 0 ? index + 1 : index - 1 );
			}
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '[data-psl-gallery]' ), function ( group ) {
			group.addEventListener( 'click', function ( event ) {
				var link = event.target.closest( 'a[data-psl-index]' );
				if ( link && group.contains( link ) ) {
					event.preventDefault();
					open( group, parseInt( link.getAttribute( 'data-psl-index' ), 10 ) || 0, link );
					return;
				}
				var all = event.target.closest( '[data-psl-open]' );
				if ( all && group.contains( all ) ) {
					open( group, parseInt( all.getAttribute( 'data-psl-open' ), 10 ) || 0, all );
				}
			} );
			Array.prototype.forEach.call( group.querySelectorAll( '[data-psl-open]' ), function ( button ) {
				button.hidden = false; // „Alle Bilder“ nur mit funktionierender Lightbox anzeigen.
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
