( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		initTabs();
		initCopyButtons();
		initPresets();
		initTestConnection();
	} );

	function initTabs() {
		document.querySelectorAll( '[data-amc-tabs]' ).forEach( function ( tabs ) {
			tabs.querySelectorAll( '.amc-tabs__btn' ).forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var target = btn.getAttribute( 'data-tab' );

					tabs.querySelectorAll( '.amc-tabs__btn' ).forEach( function ( b ) {
						b.classList.toggle( 'is-active', b === btn );
					} );
					tabs.querySelectorAll( '.amc-tabs__panel' ).forEach( function ( panel ) {
						panel.classList.toggle( 'is-active', panel.getAttribute( 'data-panel' ) === target );
					} );
				} );
			} );
		} );
	}

	function initCopyButtons() {
		document.querySelectorAll( '.amc-copy-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var targetId = btn.getAttribute( 'data-copy-target' );
				var el = document.getElementById( targetId );
				if ( ! el ) {
					return;
				}

				var text = el.textContent;

				var done = function () {
					var original = btn.textContent;
					btn.textContent = 'Copied!';
					setTimeout( function () {
						btn.textContent = original;
					}, 1500 );
				};

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( text ).then( done );
				} else {
					var textarea = document.createElement( 'textarea' );
					textarea.value = text;
					textarea.style.position = 'fixed';
					textarea.style.opacity = '0';
					document.body.appendChild( textarea );
					textarea.select();
					document.execCommand( 'copy' );
					document.body.removeChild( textarea );
					done();
				}
			} );
		} );
	}

	function initPresets() {
		var presets = document.querySelectorAll( '[data-preset]' );
		if ( ! presets.length ) {
			return;
		}

		var checkboxes = document.querySelectorAll( '.amc-scope-item input[type="checkbox"]' );
		var modeField = document.getElementById( 'amc_scope_mode' );
		var modeHint = document.getElementById( 'amc-scope-mode-hint' );

		presets.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var preset = btn.getAttribute( 'data-preset' );

				checkboxes.forEach( function ( cb ) {
					var level = cb.getAttribute( 'data-level' );

					switch ( preset ) {
						case 'read':
							cb.checked = level === 'read';
							break;
						case 'read_write':
							cb.checked = level === 'read' || level === 'write';
							break;
						case 'full':
							cb.checked = true;
							break;
						default:
							cb.checked = false;
					}
				} );

				// A preset button sets a "live" mode — the connection stays in
				// sync with that preset even if abilities are added later.
				// Any manual checkbox edit afterward (below) falls back to a
				// fixed, explicit list, since the user is now hand-picking.
				if ( modeField ) {
					modeField.value = ( preset === 'read' || preset === 'read_write' || preset === 'full' ) ? preset : 'explicit';
				}
				if ( modeHint ) {
					modeHint.hidden = ( modeField.value === 'explicit' );
				}
			} );
		} );

		checkboxes.forEach( function ( cb ) {
			cb.addEventListener( 'change', function () {
				if ( modeField ) {
					modeField.value = 'explicit';
				}
				if ( modeHint ) {
					modeHint.hidden = true;
				}
			} );
		} );
	}

	function initTestConnection() {
		var btn = document.getElementById( 'amc-test-connection' );
		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var result = document.getElementById( 'amc-test-result' );
			var token = btn.getAttribute( 'data-token' );
			var nonce = btn.getAttribute( 'data-nonce' );

			btn.disabled = true;
			result.textContent = 'Testing…';
			result.style.color = '#787c82';

			fetch( amcRestUrl(), {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: JSON.stringify( { token: token } ),
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( data ) {
					result.textContent = data.message || ( data.ok ? 'Connected!' : 'Failed.' );
					result.style.color = data.ok ? '#00a32a' : '#d63638';
				} )
				.catch( function () {
					result.textContent = 'Could not reach the test endpoint.';
					result.style.color = '#d63638';
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );
	}

	function amcRestUrl() {
		return ( window.amcConnect && window.amcConnect.testTokenUrl ) || '/wp-json/wp-mcp-connect/v1/test-token';
	}
} )();
