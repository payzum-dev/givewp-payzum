/**
 * Registers Payzum with GiveWP's v3 donation-form front end.
 *
 * Two jobs:
 *
 * 1. Existing at all. A gateway only appears on a v3 form if its PHP class implements
 *    enqueueScript() and that script registers a handler here. Without this file the gateway
 *    reports support for form version 2 only and never renders as an option on a modern form.
 *
 * 2. Modal / inline. GiveWP renders its form inside an iframe it owns and only navigates the top
 *    window for an off-site redirect, so the plugin cannot send the donor to a widget page of its
 *    own. Instead createPayment() answers with RespondToBrowser and afterCreatePayment() mounts the
 *    Payzum checkout right here, without the donor ever leaving the form.
 *
 * Redirect mode never reaches afterCreatePayment: the server returns RedirectOffsite and GiveWP
 * navigates before this runs.
 *
 * The widget's callbacks are UI hints only — whether the donation is actually paid is decided
 * solely by the signed IPN.
 */
( function () {
	'use strict';

	var CONTAINER_ID = 'payzum-give-checkout';

	function whenWidgetReady( callback, onGiveUp ) {
		if ( window.Payzum && window.Payzum.open ) {
			return callback();
		}
		var tries = 0;
		var timer = window.setInterval( function () {
			if ( window.Payzum && window.Payzum.open ) {
				window.clearInterval( timer );
				callback();
			} else if ( ++tries > 100 ) {
				window.clearInterval( timer );
				onGiveUp();
			}
		}, 100 );
	}

	/** A mount point inside the form, so the embedded checkout replaces the fields in place. */
	function mountPoint() {
		var el = document.getElementById( CONTAINER_ID );
		if ( el ) {
			return el;
		}
		el = document.createElement( 'div' );
		el.id = CONTAINER_ID;
		el.className = 'payzum-checkout';

		var form = document.querySelector( 'form' );
		if ( form && form.parentNode ) {
			form.parentNode.insertBefore( el, form );
			form.style.display = 'none';
		} else {
			document.body.appendChild( el );
		}
		return el;
	}

	var gateway = {
		id: 'payzum',

		initialize: function () {},

		// Offsite gateway: the donor picks the coin on the Payzum checkout, so there is nothing to
		// collect on the form. Returning null keeps the slot empty.
		Fields: function () {
			return null;
		},

		afterCreatePayment: function ( response ) {
			var data = ( response && response.data ) || {};
			if ( ! data.payzumPaymentId ) {
				return; // redirect mode, or an error GiveWP already surfaced
			}

			var leaveTo = function ( url ) {
				if ( ! url ) { return; }
				try {
					window.top.location.assign( url );
				} catch ( e ) {
					window.location.assign( url );
				}
			};

			whenWidgetReady(
				function () {
					var opts = {
						onSuccess: function () { leaveTo( data.payzumReturnUrl ); },
						onPartial: function () { leaveTo( data.payzumReturnUrl ); },
						onExpired: function () { leaveTo( data.payzumCancelUrl ); },
						onCancel:  function () { leaveTo( data.payzumCancelUrl ); }
					};
					if ( 'inline' === data.payzumMode ) {
						window.Payzum.openInline( data.payzumPaymentId, mountPoint(), opts );
					} else {
						window.Payzum.open( data.payzumPaymentId, opts );
					}
				},
				// Widget blocked (CSP, offline, ad blocker): fall back to the hosted checkout rather
				// than leaving the donor on a form that appears to have done nothing.
				function () {
					leaveTo( data.payzumInvoice );
				}
			);
		}
	};

	function register() {
		if ( window.givewp && window.givewp.gateways && window.givewp.gateways.register ) {
			window.givewp.gateways.register( gateway );
			return true;
		}
		return false;
	}

	// Register synchronously when possible: the form app reads the gateway list as it mounts, and a
	// handler that arrives afterwards is simply not shown. Poll only as a fallback.
	if ( ! register() ) {
		var tries = 0;
		var timer = window.setInterval( function () {
			if ( register() || ++tries > 100 ) {
				window.clearInterval( timer );
			}
		}, 50 );
	}
} )();
