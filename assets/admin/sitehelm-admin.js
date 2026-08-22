/**
 * SiteHelm admin console behaviour.
 *
 * Every feature here is an enhancement of markup that already works without
 * it: the client-config blocks are all rendered and readable with scripting
 * off, the copy buttons are absent rather than inert, and the operations
 * filter hides nothing until it is able to show it again.
 */
( function () {
	'use strict';

	var strings = window.siteHelmAdmin || {};

	/**
	 * Announce a transient result on a button without moving focus.
	 *
	 * The label is restored rather than left changed, because a button whose
	 * name permanently reads "Copied" no longer describes what it does.
	 *
	 * @param {HTMLElement} button  The button to relabel.
	 * @param {string}      message The message to show.
	 */
	function flash( button, message ) {
		var label = button.querySelector( '[data-sitehelm-label]' );

		if ( ! label ) {
			return;
		}

		if ( ! button.dataset.sitehelmRest ) {
			button.dataset.sitehelmRest = label.textContent;
		}

		window.clearTimeout( Number( button.dataset.sitehelmTimer ) );
		label.textContent = message;

		// A button whose only label is for screen readers needs the result
		// shown some other way, or a sighted operator cannot tell whether the
		// press did anything. The class is harmless on buttons with a visible
		// label, which carry the message in their text as before.
		button.classList.add( 'is-flashed' );

		button.dataset.sitehelmTimer = String(
			window.setTimeout( function () {
				label.textContent = button.dataset.sitehelmRest;
				button.classList.remove( 'is-flashed' );
			}, 2000 )
		);
	}

	/**
	 * Copy the referenced element's text to the clipboard.
	 *
	 * @param {HTMLElement} button The button that was pressed.
	 */
	function copyFrom( button ) {
		var source = document.getElementById( button.getAttribute( 'data-sitehelm-copy' ) );

		if ( ! source ) {
			return;
		}

		var text = 'value' in source ? source.value : source.textContent;

		if ( ! navigator.clipboard || ! navigator.clipboard.writeText ) {
			flash( button, strings.copyUnavailable || 'Select and copy manually' );
			return;
		}

		navigator.clipboard.writeText( text ).then(
			function () {
				flash( button, strings.copied || 'Copied' );
			},
			function () {
				flash( button, strings.copyFailed || 'Could not copy' );
			}
		);
	}

	/**
	 * Show only the client-config block matching the selected client.
	 *
	 * @param {HTMLElement} group The fieldset holding the client radios.
	 */
	function applyClientChoice( group ) {
		var chosen = group.querySelector( 'input[type="radio"]:checked' );

		if ( ! chosen ) {
			return;
		}

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-sitehelm-client]' ),
			function ( block ) {
				var matches = block.getAttribute( 'data-sitehelm-client' ) === chosen.value;
				block.hidden = ! matches;
			}
		);
	}

	/**
	 * Filter the operations table to rows matching the query.
	 *
	 * The count is announced politely so a screen-reader user learns the
	 * result of typing without the table itself being re-read.
	 *
	 * @param {HTMLInputElement} input The search field.
	 */
	function filterOperations( input ) {
		var query = input.value.trim().toLowerCase();
		var status = document.getElementById( input.getAttribute( 'aria-describedby' ) );
		var shown = 0;
		var total = 0;

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-sitehelm-haystack]' ),
			function ( row ) {
				var hit = '' === query || row.getAttribute( 'data-sitehelm-haystack' ).indexOf( query ) !== -1;
				row.hidden = ! hit;
				total += 1;
				shown += hit ? 1 : 0;
			}
		);

		// A dispatcher whose every row is filtered out keeps its heading only
		// while something under it survives; an empty group heading reads as a
		// group with nothing in it rather than as a group that was filtered.
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-sitehelm-group]' ),
			function ( group ) {
				group.hidden = null === group.querySelector( '[data-sitehelm-haystack]:not([hidden])' );
			}
		);

		if ( status ) {
			status.textContent = ( strings.filtered || '%1$s of %2$s operations shown' )
				.replace( '%1$s', String( shown ) )
				.replace( '%2$s', String( total ) );
		}
	}

	/**
	 * Show or hide the tab bar's scroll arrows to match what is overflowing.
	 *
	 * The arrows exist only when the bar is wider than its frame. Offering a
	 * control that scrolls nothing is worse than offering none.
	 *
	 * @param {HTMLElement} nav The scrolling tab bar.
	 */
	function syncNavArrows( nav ) {
		var overflow = nav.scrollWidth - nav.clientWidth;
		var prev = document.querySelector( '[data-sitehelm-nav="prev"]' );
		var next = document.querySelector( '[data-sitehelm-nav="next"]' );

		if ( prev ) {
			prev.hidden = nav.scrollLeft <= 1;
		}

		if ( next ) {
			next.hidden = nav.scrollLeft >= overflow - 1;
		}
	}

	/**
	 * Wire the tab bar's overflow arrows.
	 *
	 * @param {HTMLElement} nav The scrolling tab bar.
	 */
	function initNav( nav ) {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-sitehelm-nav]' ),
			function ( button ) {
				button.addEventListener( 'click', function () {
					var step = Math.max( 160, Math.round( nav.clientWidth * 0.6 ) );
					var sign = 'prev' === button.getAttribute( 'data-sitehelm-nav' ) ? -1 : 1;

					nav.scrollBy( { left: sign * step, behavior: 'smooth' } );
				} );
			}
		);

		nav.addEventListener( 'scroll', function () {
			syncNavArrows( nav );
		} );

		window.addEventListener( 'resize', function () {
			syncNavArrows( nav );
		} );

		syncNavArrows( nav );
	}

	/**
	 * Describe what a response to the unauthenticated probe means.
	 *
	 * The probe carries no credential on purpose, so 401 is the good answer:
	 * the route exists and is evaluating authentication, which narrows the
	 * remaining problem to the credential or a stripped header. 404 means the
	 * request never reached SiteHelm at all.
	 *
	 * @param {number} status The HTTP status the endpoint returned.
	 *
	 * @return {{ok: boolean, message: string}} The verdict to display.
	 */
	function describeProbe( status ) {
		if ( 401 === status || 403 === status ) {
			return {
				ok: true,
				message: strings.testReachable || 'The endpoint answered and asked for a credential.'
			};
		}

		if ( 404 === status ) {
			return {
				ok: false,
				message: strings.testNotFound || 'Nothing answered at that address on this site.'
			};
		}

		return {
			ok: false,
			message: ( strings.testUnexpected || 'The endpoint answered with status %s.' )
				.replace( '%s', String( status ) )
		};
	}

	/**
	 * Write the probe's verdict where the button says it will appear.
	 *
	 * @param {HTMLElement} target  The live region.
	 * @param {string}      tone    Either "ok" or "refused".
	 * @param {string}      message The verdict.
	 */
	function showProbe( target, tone, message ) {
		target.innerHTML = '';

		var note = document.createElement( 'div' );
		note.className = 'sitehelm-note sitehelm-note--' + tone;

		var line = document.createElement( 'p' );
		line.textContent = message;

		note.appendChild( line );
		target.appendChild( note );
	}

	/**
	 * Send one unauthenticated request to the endpoint and report what came back.
	 *
	 * @param {HTMLElement} button The test button.
	 */
	function probe( button ) {
		var target = document.querySelector( '[data-sitehelm-test-result]' );

		if ( ! target ) {
			return;
		}

		button.disabled = true;
		showProbe( target, 'waiting', strings.testRunning || 'Testing…' );

		window.fetch( button.getAttribute( 'data-sitehelm-test' ), {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
			credentials: 'omit'
		} ).then(
			function ( response ) {
				var verdict = describeProbe( response.status );
				showProbe( target, verdict.ok ? 'ok' : 'refused', verdict.message );
			},
			function () {
				showProbe(
					target,
					'refused',
					strings.testFailed || 'The request could not be sent from this browser.'
				);
			}
		).then( function () {
			button.disabled = false;
		} );
	}

	/**
	 * Bring every switch count on the Operations screen up to date.
	 *
	 * Each group heading says how many of its operations are on, and the save
	 * bar says the same for the page. Both are computed from the checkboxes
	 * themselves, so they can never disagree with what will be posted.
	 *
	 * @param {HTMLElement} form The switches form.
	 */
	function syncSwitchCounts( form ) {
		var all = form.querySelectorAll( '[data-sitehelm-switch]' );
		var on = form.querySelectorAll( '[data-sitehelm-switch]:checked' );

		Array.prototype.forEach.call(
			form.querySelectorAll( '[data-sitehelm-group]' ),
			function ( group ) {
				var count = group.querySelector( '[data-sitehelm-switch-count]' );
				if ( ! count ) {
					return;
				}
				count.textContent = count.getAttribute( 'data-sitehelm-count-label' )
					.replace( '%1$s', String( group.querySelectorAll( '[data-sitehelm-switch]:checked' ).length ) )
					.replace( '%2$s', String( group.querySelectorAll( '[data-sitehelm-switch]' ).length ) );
			}
		);

		var summary = form.querySelector( '[data-sitehelm-switch-summary]' );
		if ( summary ) {
			summary.textContent = summary.getAttribute( 'data-sitehelm-count-label' )
				.replace( '%1$s', String( on.length ) )
				.replace( '%2$s', String( all.length ) );
		}
	}

	/**
	 * Reflect one switch's state on its row.
	 *
	 * @param {HTMLInputElement} box The checkbox.
	 */
	function syncSwitchRow( box ) {
		var row = box.closest( '[data-sitehelm-switch-row]' );
		if ( row ) {
			row.classList.toggle( 'sitehelm-table__row--off', ! box.checked );
		}
	}

	/**
	 * Wire the Operations screen's switches: live counts, row dimming, and the
	 * per-group "All on" / "All off" buttons that exist only with script.
	 *
	 * @param {HTMLElement} form The switches form.
	 */
	function initSwitches( form ) {
		var savebar = form.querySelector( '[data-sitehelm-savebar]' );

		Array.prototype.forEach.call(
			form.querySelectorAll( '[data-sitehelm-switch-actions]' ),
			function ( actions ) {
				actions.hidden = false;
			}
		);

		form.addEventListener( 'change', function ( event ) {
			if ( ! event.target.hasAttribute( 'data-sitehelm-switch' ) ) {
				return;
			}
			syncSwitchRow( event.target );
			syncSwitchCounts( form );
			if ( savebar ) {
				savebar.classList.add( 'is-dirty' );
			}
		} );

		form.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-sitehelm-switch-all]' );
			if ( ! button ) {
				return;
			}
			var group = button.closest( '[data-sitehelm-group]' );
			var on = 'on' === button.getAttribute( 'data-sitehelm-switch-all' );

			Array.prototype.forEach.call(
				group.querySelectorAll( '[data-sitehelm-switch]' ),
				function ( box ) {
					box.checked = on;
					syncSwitchRow( box );
				}
			);
			syncSwitchCounts( form );
			if ( savebar ) {
				savebar.classList.add( 'is-dirty' );
			}
		} );

		syncSwitchCounts( form );
	}

	/**
	 * Wire the console once the markup is present.
	 */
	function init() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-sitehelm-copy]' ),
			function ( button ) {
				button.hidden = false;
				button.addEventListener( 'click', function () {
					copyFrom( button );
				} );
			}
		);

		var group = document.querySelector( '[data-sitehelm-clients]' );

		if ( group ) {
			applyClientChoice( group );
			group.addEventListener( 'change', function () {
				applyClientChoice( group );
			} );
		}

		var search = document.querySelector( '[data-sitehelm-search]' );

		if ( search ) {
			search.closest( '.sitehelm-filters' ).hidden = false;
			search.addEventListener( 'input', function () {
				filterOperations( search );
			} );
		}

		var switches = document.querySelector( '[data-sitehelm-switches]' );

		if ( switches ) {
			initSwitches( switches );
		}

		var nav = document.querySelector( '[data-sitehelm-appnav]' );

		if ( nav ) {
			initNav( nav );
		}

		var test = document.querySelector( '[data-sitehelm-test]' );

		// Revealed only when the browser can actually make the request, so the
		// no-script hint stays put on a browser that cannot.
		if ( test && window.fetch ) {
			test.hidden = false;

			Array.prototype.forEach.call(
				document.querySelectorAll( '[data-sitehelm-test-idle]' ),
				function ( hint ) {
					hint.hidden = true;
				}
			);

			test.addEventListener( 'click', function () {
				probe( test );
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
