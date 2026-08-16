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

		button.dataset.sitehelmTimer = String(
			window.setTimeout( function () {
				label.textContent = button.dataset.sitehelmRest;
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
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
