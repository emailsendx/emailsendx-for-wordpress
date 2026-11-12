/**
 * EmailSendX for WordPress — abandoned-cart capture (checkout only).
 *
 * Loaded only when the merchant turned "Recover abandoned carts" on and
 * chose a mode that involves guests. It does two things:
 *
 *   1. Shows, under the checkout email field, either a short notice with
 *      a "No thanks" link (mode "notice") or an unticked consent box
 *      (mode "checkbox").
 *   2. Tells the site the shopper's email once it is typed — and only
 *      while they are allowed to get reminders — so the cart can be
 *      saved. Nothing is sent to EmailSendX from the browser.
 *
 * Works with the classic checkout (#billing_email) and the block checkout
 * (#email). No dependencies. ShaonPro.
 */
( function () {
	'use strict';

	var cfg = window.emailsendxCart;
	if ( ! cfg || ! cfg.ajaxUrl || ! window.fetch || ! window.URLSearchParams ) {
		return;
	}

	var BOX_ID  = 'esx-cart-consent';
	var consent = cfg.consent === 'yes' || cfg.consent === 'no' ? cfg.consent : '';
	var text    = cfg.text || {};
	var lastSent = '';
	var timer    = null;

	function emailField() {
		return document.querySelector( '#billing_email' ) ||
			document.querySelector( '.wc-block-checkout #email, .wp-block-woocommerce-checkout #email' );
	}

	function value( selectors ) {
		for ( var i = 0; i < selectors.length; i++ ) {
			var el = document.querySelector( selectors[ i ] );
			if ( el && el.value ) {
				return String( el.value ).trim();
			}
		}
		return '';
	}

	function validEmail( email ) {
		return /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test( email );
	}

	// Whether this shopper may be saved, by the merchant's chosen mode.
	function allowed() {
		if ( consent === 'no' ) {
			return false;
		}
		return cfg.mode === 'checkbox' ? consent === 'yes' : true;
	}

	function post( fields ) {
		var body = new URLSearchParams();
		body.append( 'action', 'emailsendx_cart_capture' );
		body.append( 'nonce', cfg.nonce || '' );
		Object.keys( fields ).forEach( function ( key ) {
			if ( fields[ key ] ) {
				body.append( key, fields[ key ] );
			}
		} );
		var key = body.toString();
		if ( key === lastSent ) {
			return;
		}
		lastSent = key;
		try {
			window.fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				body: body
			} ).catch( function () {} );
		} catch ( e ) {
			// Never let reminder capture get in the way of checkout.
		}
	}

	function report() {
		var field = emailField();
		var email = field ? String( field.value || '' ).trim() : '';
		if ( ! allowed() ) {
			// Only the refusal itself is reported, so the site can forget them.
			if ( consent === 'no' ) {
				post( { consent: 'no', email: validEmail( email ) ? email : '' } );
			}
			return;
		}
		if ( ! validEmail( email ) ) {
			return;
		}
		post( {
			email: email,
			consent: consent,
			first_name: value( [ '#billing_first_name', '#billing-first_name', '#shipping-first_name' ] ),
			last_name: value( [ '#billing_last_name', '#billing-last_name', '#shipping-last_name' ] )
		} );
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = window.setTimeout( report, 600 );
	}

	function setConsent( next ) {
		consent = next;
		report();
	}

	function buildBox() {
		// A <span>, so it is valid inside the classic checkout's <p> field row.
		var box = document.createElement( 'span' );
		box.id = BOX_ID;
		box.style.cssText = 'display:block;margin:6px 0 12px;font-size:0.875em;line-height:1.5;opacity:0.85;';

		if ( cfg.mode === 'checkbox' ) {
			var label = document.createElement( 'label' );
			label.style.cssText = 'display:flex;gap:8px;align-items:flex-start;cursor:pointer;';
			var input = document.createElement( 'input' );
			input.type = 'checkbox';
			input.checked = consent === 'yes';
			input.style.cssText = 'margin-top:0.25em;flex-shrink:0;';
			input.addEventListener( 'change', function () {
				setConsent( input.checked ? 'yes' : 'no' );
			} );
			var span = document.createElement( 'span' );
			span.textContent = text.checkbox || '';
			label.appendChild( input );
			label.appendChild( span );
			box.appendChild( label );
			return box;
		}

		if ( consent === 'no' ) {
			box.textContent = text.declined || '';
			return box;
		}
		box.appendChild( document.createTextNode( ( text.notice || '' ) + ' ' ) );
		var link = document.createElement( 'a' );
		link.href = '#';
		link.setAttribute( 'role', 'button' );
		link.textContent = text.decline || '';
		link.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			setConsent( 'no' );
			box.textContent = text.declined || '';
		} );
		box.appendChild( link );
		return box;
	}

	// The block checkout renders (and re-renders) its fields itself, so the
	// box is put back whenever it goes missing.
	function ensureBox() {
		if ( document.getElementById( BOX_ID ) ) {
			return;
		}
		var field = emailField();
		if ( ! field ) {
			return;
		}
		// Classic checkout: inside the email field's own row. WooCommerce
		// re-sorts the rows when the country changes, and anything placed
		// between them is left behind at the top of the form.
		var row = field.closest( '.form-row' );
		if ( row ) {
			row.appendChild( buildBox() );
			return;
		}
		// Block checkout: right after the email input.
		var anchor = field.closest( '.wc-block-components-text-input' ) || field;
		if ( anchor.parentNode ) {
			anchor.parentNode.insertBefore( buildBox(), anchor.nextSibling );
		}
	}

	function isWatched( target ) {
		return !! ( target && target.matches && target.matches(
			'#billing_email, #email, #billing_first_name, #billing_last_name, #billing-first_name, #billing-last_name, #shipping-first_name, #shipping-last_name'
		) );
	}

	document.addEventListener( 'input', function ( event ) {
		if ( isWatched( event.target ) ) {
			schedule();
		}
	}, true );
	document.addEventListener( 'change', function ( event ) {
		if ( isWatched( event.target ) ) {
			report();
		}
	}, true );
	document.addEventListener( 'focusout', function ( event ) {
		if ( isWatched( event.target ) ) {
			report();
		}
	}, true );

	function start() {
		ensureBox();
		if ( window.MutationObserver && document.body ) {
			new window.MutationObserver( ensureBox ).observe( document.body, { childList: true, subtree: true } );
		}
		// A returning shopper's browser may have filled the field already.
		schedule();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
