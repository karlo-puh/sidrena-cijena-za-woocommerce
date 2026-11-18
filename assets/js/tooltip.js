/**
 * Sidrena cijena – "(?)" next to the struck-through 30-day low.
 *
 * CSS alone shows the tip on hover/focus; this adds what CSS cannot:
 * - tap/click/Enter/Space toggle it without following the product link the price often sits in;
 * - Escape or a tap elsewhere closes it; the pointer can move onto the tip without it closing;
 * - the open tip moves to <body> and is placed in the viewport (position: fixed), so neither a card's
 *   overflow: hidden nor a theme's faded (opacity) struck-through price affects it;
 * - a "(?)" whose tabindex was stripped by a sanitizer (some product-grid blocks) is focusable again.
 */
( function () {
	'use strict';

	var root = document.documentElement;
	var open = null;
	var closeTimer = null;
	var GAP = 6;
	var EDGE = 8;

	root.classList.add( 'scwc-tip-js' );

	function tipOf( info ) {
		if ( ! info.scwcTip ) {
			info.scwcTip = info.querySelector( '.scwc-info__tip' );
			if ( info.scwcTip ) {
				info.scwcTip.scwcOwner = info;
			}
		}
		return info.scwcTip;
	}

	function inside( info, node ) {
		var tip = tipOf( info );
		return !! node && ( info.contains( node ) || ( !! tip && tip.contains( node ) ) );
	}

	function place( info ) {
		var tip = tipOf( info );
		if ( ! tip ) {
			return;
		}
		var icon = info.getBoundingClientRect();
		var width = tip.offsetWidth;
		var height = tip.offsetHeight;
		var left = icon.left + icon.width / 2 - width / 2;
		left = Math.max( EDGE, Math.min( left, window.innerWidth - width - EDGE ) );
		var top = icon.top - height - GAP;
		if ( top < EDGE ) {
			top = icon.bottom + GAP; // No room above: open below.
		}
		tip.style.left = Math.round( left ) + 'px';
		tip.style.top = Math.round( top ) + 'px';
	}

	function show( info ) {
		clearTimeout( closeTimer );
		if ( open && open !== info ) {
			hide( open );
		}
		if ( ! info.classList.contains( 'is-open' ) ) {
			info.scwcShownAt = Date.now();
		}
		open = info;
		info.classList.add( 'is-open' );
		info.setAttribute( 'aria-expanded', 'true' );
		var tip = tipOf( info );
		if ( tip ) {
			tip.classList.add( 'is-open' );
			document.body.appendChild( tip );
		}
		place( info );
	}

	function hide( info ) {
		info.classList.remove( 'is-open' );
		info.setAttribute( 'aria-expanded', 'false' );
		var tip = tipOf( info );
		if ( tip ) {
			tip.classList.remove( 'is-open' );
			info.appendChild( tip );
		}
		if ( open === info ) {
			open = null;
		}
	}

	function hideLater( info ) {
		clearTimeout( closeTimer );
		closeTimer = setTimeout( function () {
			// Still focused (keyboard) or hovered: keep it open.
			if ( inside( info, document.activeElement ) || info.matches( ':hover' ) || ( tipOf( info ) && tipOf( info ).matches( ':hover' ) ) ) {
				return;
			}
			hide( info );
		}, 200 );
	}

	function infoFrom( target ) {
		if ( ! target || ! target.closest ) {
			return null;
		}
		var tip = target.closest( '.scwc-info__tip' );
		return tip && tip.scwcOwner ? tip.scwcOwner : target.closest( '.scwc-info' );
	}

	function prepare() {
		// The price this tip belongs to was replaced (AJAX filter, infinite scroll): drop the orphaned tip.
		if ( open && ! open.isConnected ) {
			var orphan = tipOf( open );
			if ( orphan && orphan.parentNode ) {
				orphan.parentNode.removeChild( orphan );
			}
			open = null;
		}
		var all = document.querySelectorAll( '.scwc-info' );
		for ( var i = 0; i < all.length; i++ ) {
			if ( ! all[ i ].hasAttribute( 'tabindex' ) ) {
				all[ i ].setAttribute( 'tabindex', '0' );
			}
			if ( ! all[ i ].hasAttribute( 'aria-expanded' ) ) {
				all[ i ].setAttribute( 'aria-expanded', 'false' );
			}
		}
	}

	// Capture phase: runs before the product link (or a theme's card click handler) acts on the tap.
	document.addEventListener(
		'click',
		function ( event ) {
			var info = infoFrom( event.target );
			if ( ! info ) {
				if ( open ) {
					hide( open );
				}
				return;
			}
			if ( event.target.closest( '.scwc-info__tip' ) && info.classList.contains( 'is-open' ) ) {
				return; // A tap on the open tip's text keeps it open.
			}
			event.preventDefault();
			event.stopPropagation();
			// A tap or click first fires hover/focus, which already opened it: keep it open then.
			if ( info.classList.contains( 'is-open' ) && Date.now() - ( info.scwcShownAt || 0 ) > 400 ) {
				hide( info );
			} else {
				show( info );
			}
		},
		true
	);

	document.addEventListener(
		'keydown',
		function ( event ) {
			if ( 'Escape' === event.key && open ) {
				hide( open );
				return;
			}
			var info = infoFrom( event.target );
			if ( info && ( 'Enter' === event.key || ' ' === event.key ) ) {
				event.preventDefault();
				event.stopPropagation();
				if ( info.classList.contains( 'is-open' ) ) {
					hide( info );
				} else {
					show( info );
				}
			}
		},
		true
	);

	document.addEventListener( 'focusin', function ( event ) {
		var info = infoFrom( event.target );
		if ( info ) {
			show( info );
		}
	} );
	document.addEventListener( 'focusout', function ( event ) {
		var info = infoFrom( event.target );
		if ( info && ! inside( info, event.relatedTarget ) ) {
			hideLater( info );
		}
	} );

	// Hover: the tip itself counts as part of the "(?)", so moving onto it keeps it open.
	document.addEventListener( 'mouseover', function ( event ) {
		var info = infoFrom( event.target );
		if ( info ) {
			show( info );
		}
	} );
	document.addEventListener( 'mouseout', function ( event ) {
		var info = infoFrom( event.target );
		if ( info && ! inside( info, event.relatedTarget ) ) {
			hideLater( info );
		}
	} );

	window.addEventListener(
		'scroll',
		function () {
			if ( open ) {
				place( open );
			}
		},
		true
	);
	window.addEventListener( 'resize', function () {
		if ( open ) {
			place( open );
		}
	} );

	prepare();
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', prepare );
	}
	// Prices added later (AJAX filters, infinite scroll, variation switches).
	if ( window.MutationObserver ) {
		var queued = false;
		new MutationObserver( function () {
			if ( ! queued ) {
				queued = true;
				setTimeout( function () {
					queued = false;
					prepare();
				}, 50 );
			}
		} ).observe( document.documentElement, { childList: true, subtree: true } );
	}
}() );
