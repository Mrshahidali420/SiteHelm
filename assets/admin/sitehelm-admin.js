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
	 * Read a remembered console choice, or an empty string.
	 *
	 * Storage is wrapped because a browser in private mode, or one told to
	 * block site data, throws on the accessor itself rather than returning
	 * nothing. A console that cannot remember a tab is fine; one that stops
	 * wiring its buttons because of it is not.
	 *
	 * @param {string} key The name to read.
	 *
	 * @return {string} The stored value, or ''.
	 */
	function remembered( key ) {
		try {
			return window.localStorage.getItem( 'sitehelm.' + key ) || '';
		} catch ( error ) {
			return '';
		}
	}

	/**
	 * Remember a console choice for the next visit.
	 *
	 * @param {string} key   The name to write.
	 * @param {string} value The value to keep.
	 */
	function remember( key, value ) {
		try {
			window.localStorage.setItem( 'sitehelm.' + key, value );
		} catch ( error ) {
			// A choice that cannot be remembered is still a choice that works now.
		}
	}

	/**
	 * Select the radio carrying a value, if it is still on the page.
	 *
	 * @param {HTMLElement} group The fieldset holding the radios.
	 * @param {string}      value The value to select.
	 *
	 * @return {boolean} Whether a radio was selected.
	 */
	function selectRadio( group, value ) {
		if ( ! value ) {
			return false;
		}

		var wanted = group.querySelector( 'input[type="radio"][value="' + value + '"]' );

		if ( ! wanted || wanted.disabled ) {
			return false;
		}

		wanted.checked = true;

		return true;
	}

	/**
	 * Put the selected class on the label wrapping the checked radio.
	 *
	 * The stylesheet has always had a selected state for these cards; nothing
	 * was applying it, so a picked card looked the same as an unpicked one
	 * except for the radio dot. This is the two lines that were missing.
	 *
	 * @param {HTMLElement} group The fieldset holding the radios.
	 */
	function markSelected( group ) {
		Array.prototype.forEach.call(
			group.querySelectorAll( 'label' ),
			function ( label ) {
				var radio = label.querySelector( 'input[type="radio"]' );

				if ( radio ) {
					label.classList.toggle( 'is-selected', radio.checked );
				}
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

		markSelected( group );
		remember( 'connect.client', chosen.value );
	}

	/**
	 * Show only the connection method the operator chose.
	 *
	 * Every shape group declares which method it belongs to, so hiding by
	 * method is what keeps an application-password header block off the screen
	 * of somebody who has decided to sign in instead. Both are on the page
	 * with scripting off, which is readable rather than wrong.
	 *
	 * @param {HTMLElement} group The fieldset holding the method radios.
	 */
	function applyMethodChoice( group ) {
		var chosen = group.querySelector( 'input[type="radio"]:checked' );

		if ( ! chosen ) {
			return;
		}

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-sitehelm-auth]' ),
			function ( block ) {
				block.hidden = block.getAttribute( 'data-sitehelm-auth' ) !== chosen.value;
			}
		);

		markSelected( group );
		remember( 'connect.method', chosen.value );
	}

	/**
	 * Show only the shape whose tab is selected, within one group.
	 *
	 * @param {HTMLElement} group The element holding one client's shapes.
	 */
	function applyShapeChoice( group ) {
		var tabs = group.querySelector( '[data-sitehelm-shapetabs]' );

		if ( ! tabs ) {
			return;
		}

		var chosen = tabs.querySelector( 'input[type="radio"]:checked' );

		if ( ! chosen ) {
			return;
		}

		Array.prototype.forEach.call(
			group.querySelectorAll( '[data-sitehelm-shape]' ),
			function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-sitehelm-shape' ) !== chosen.value;
			}
		);
	}

	/**
	 * Wire every shape tab strip on the screen.
	 */
	function initShapes() {
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-sitehelm-shapes]' ),
			function ( group ) {
				applyShapeChoice( group );
				group.addEventListener( 'change', function () {
					applyShapeChoice( group );
				} );
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
			row.classList.toggle( 'is-off', ! box.checked );
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
			var toggle = event.target.closest( '[data-sitehelm-collapse]' );
			if ( toggle ) {
				var section = toggle.closest( '[data-sitehelm-group]' );
				var collapsed = section.classList.toggle( 'is-collapsed' );
				toggle.setAttribute( 'aria-expanded', collapsed ? 'false' : 'true' );
				return;
			}

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
	 * Open the first-run connect dialog.
	 *
	 * The dialog is printed closed and opened here rather than carrying an
	 * `open` attribute, because only showModal() gives it a backdrop, a focus
	 * trap and Escape. A browser with scripting off therefore sees nothing,
	 * which is the right way round: an untrapped panel nailed over the console
	 * with no way to dismiss it would be worse than no dialog at all.
	 *
	 * Escape is left to cancel without dismissing for good. Closing it that way
	 * is not an answer, so the dialog is offered again on the next screen; the
	 * two controls that ARE answers both post.
	 *
	 * @param {HTMLElement} modal The dialog element.
	 */
	function initConnectModal( modal ) {
		if ( 'function' !== typeof modal.showModal ) {
			return;
		}

		modal.showModal();
	}

	/**
	 * Turn one destructive button into a two-step one.
	 *
	 * The first press does not submit: it changes the button into a question
	 * naming the app, and a second press answers it. A browser dialog cannot
	 * say which row it is about, and somebody who dismisses those by reflex
	 * has no way of telling what they just agreed to.
	 *
	 * The button reverts on blur and after a pause, so a half-pressed control
	 * left on screen does not become a live one the next time the mouse lands
	 * near it. With scripting off the single press submits, which is the same
	 * bargain the rest of the console makes.
	 *
	 * @param {HTMLButtonElement} button The submit button carrying the wording.
	 */
	function armConfirm( button ) {
		var resting = button.textContent;
		var asked   = false;
		var timer   = null;

		function rest() {
			asked = false;
			button.textContent = resting;
			button.classList.remove( 'is-asking' );

			if ( timer ) {
				window.clearTimeout( timer );
				timer = null;
			}
		}

		button.addEventListener( 'click', function ( event ) {
			if ( asked ) {
				return;
			}

			event.preventDefault();
			asked = true;
			button.textContent = button.getAttribute( 'data-sitehelm-confirm' );
			button.classList.add( 'is-asking' );
			timer = window.setTimeout( rest, 5000 );
		} );

		button.addEventListener( 'blur', rest );
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

		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-sitehelm-confirm]' ),
			armConfirm
		);

		var methods = document.querySelector( '[data-sitehelm-methods]' );

		if ( methods ) {
			selectRadio( methods, remembered( 'connect.method' ) );
			applyMethodChoice( methods );
			methods.addEventListener( 'change', function () {
				applyMethodChoice( methods );
			} );
		}

		initShapes();

		var group = document.querySelector( '[data-sitehelm-clients]' );

		if ( group ) {
			selectRadio( group, remembered( 'connect.client' ) );
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

		// A one-switch form posts itself on change; its Apply button is only
		// for a browser that cannot.
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-sitehelm-autosubmit]' ),
			function ( form ) {
				var apply = form.querySelector( '[data-sitehelm-autosubmit-apply]' );
				if ( apply ) {
					apply.hidden = true;
				}
				form.addEventListener( 'change', function () {
					form.submit();
				} );
			}
		);

		var modal = document.querySelector( '[data-sitehelm-connect-modal]' );

		if ( modal ) {
			initConnectModal( modal );
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
