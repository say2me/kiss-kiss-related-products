/**
 * The Suggestions card's progress (C-0493ee8 R3): while the work runs and the page is visible, read the progress
 * route every 20 seconds and replace the block; stop once it no longer runs. Plain JavaScript, no build.
 */
( function () {
	'use strict';

	var config = window.vscmProgress;
	var INTERVAL = 20000;
	var timer = null;

	function block() {
		return document.getElementById( 'vscm-progress' );
	}

	function running() {
		var el = block();
		return !! el && 'working' === el.getAttribute( 'data-state' );
	}

	function schedule() {
		window.clearTimeout( timer );
		timer = null;
		if ( running() && ! document.hidden ) {
			timer = window.setTimeout( update, INTERVAL );
		}
	}

	function update() {
		window
			.fetch( config.url, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.nonce },
			} )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( data ) {
				var el = block();
				if ( data && 'string' === typeof data.html && el ) {
					el.outerHTML = data.html;
				}
			} )
			.catch( function () {} )
			.then( schedule );
	}

	if ( ! config || ! config.url ) {
		return;
	}
	document.addEventListener( 'visibilitychange', schedule );
	schedule();
} )();
