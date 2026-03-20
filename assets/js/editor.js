/* Open World — Inline Translation Editor */
( function () {
	'use strict';

	if ( typeof owEditor === 'undefined' ) return;

	const DEBOUNCE = 900; // ms

	// ── Remove form confirmation ───────────────────────────────────────────────
	document.querySelectorAll( '.ow-remove-form' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			const btn  = form.querySelector( '[data-lang]' );
			const lang = btn ? btn.dataset.lang : '?';
			const ok   = window.confirm(
				'Remove language "' + lang + '" and all its translations?\nThis cannot be undone.'
			);
			if ( ! ok ) e.preventDefault();
		} );
	} );

	// ── Inline translation editor ─────────────────────────────────────────────
	document.querySelectorAll( '.ow-msgstr[contenteditable]' ).forEach( function ( cell ) {
		let timer;

		cell.addEventListener( 'input', function () {
			const id     = this.dataset.id;
			const status = document.querySelector( '.ow-save-status[data-id="' + id + '"]' );

			clearTimeout( timer );

			if ( status ) {
				status.textContent = '…';
				status.className   = 'ow-save-status is-saving';
			}

			const text = this.innerText;

			timer = setTimeout( async function () {
				try {
					const body = new URLSearchParams( {
						action:      'ow_save_translation',
						id:          id,
						msgstr:      text,
						_ajax_nonce: owEditor.nonce,
					} );

					const resp = await fetch( owEditor.ajaxurl, {
						method:      'POST',
						credentials: 'same-origin',
						body:        body,
					} );

					const data = await resp.json();

					if ( status ) {
						if ( data.success ) {
							status.textContent = '✓';
							status.className   = 'ow-save-status is-saved';
							// Mark row as translated
							const row = cell.closest( 'tr' );
							if ( row ) {
								row.classList.add( 'ow-row-translated' );
								row.classList.remove( 'ow-row-untranslated' );
							}
							// Fade ✓ after 2 seconds
							setTimeout( () => {
								if ( status.textContent === '✓' ) status.textContent = '';
							}, 2000 );
						} else {
							status.textContent = '✗';
							status.className   = 'ow-save-status is-error';
						}
					}
				} catch ( e ) {
					if ( status ) {
						status.textContent = '✗';
						status.className   = 'ow-save-status is-error';
					}
				}
			}, DEBOUNCE );
		} );

		// Prevent newline on Enter — single-line mode for short strings
		cell.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' && ! e.shiftKey ) {
				e.preventDefault();
				cell.blur();
			}
		} );
	} );
} )();
