/* EmailSendX Sync admin JS · ShaonPro · v1.0.0 */
( function () {
	'use strict';

	var Cfg = window.EmailSendXSync || {};
	var ajaxUrl = Cfg.ajaxUrl || ( window.ajaxurl || '' );
	var nonce   = Cfg.nonce   || '';
	var i18n    = Cfg.i18n    || {};

	/**
	 * POST a form-encoded request to admin-ajax.php with our nonce.
	 * Resolves with the JSON body or rejects on transport error.
	 * ShaonPro.
	 */
	function ajax( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( '_ajax_nonce', nonce );
		if ( data ) {
			Object.keys( data ).forEach( function ( k ) {
				if ( data[ k ] === undefined || data[ k ] === null ) { return; }
				body.append( k, data[ k ] );
			} );
		}
		return fetch( ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
				'X-WP-Nonce': nonce
			},
			body: body.toString()
		} ).then( function ( r ) {
			return r.json().catch( function () { return null; } );
		} );
	}

	function $( sel, root ) { return ( root || document ).querySelector( sel ); }
	function $$( sel, root ) { return Array.prototype.slice.call( ( root || document ).querySelectorAll( sel ) ); }

	function setLoading( btn, on ) {
		if ( ! btn ) { return; }
		btn.classList.toggle( 'is-loading', !! on );
		btn.disabled = !! on;
	}

	function showPill( host, kind, message ) {
		if ( ! host ) { return; }
		host.className = 'esx-pill esx-pill-' + kind;
		host.textContent = message;
		host.hidden = false;
	}

	/* ─── 1. Test connection · Settings tab ─── */
	function bindTestConnection() {
		$$( '[data-esx-action="test-connection"]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var pillId = btn.getAttribute( 'data-esx-result' ) || 'esx-test-result';
				var pill = document.getElementById( pillId );
				setLoading( btn, true );
				ajax( 'emailsendx_test_connection' ).then( function ( res ) {
					setLoading( btn, false );
					if ( res && res.success ) {
						showPill( pill, 'ok', ( res.data && res.data.message ) || ( i18n.connectionOk || 'Connected.' ) );
					} else {
						var msg = ( res && res.data && res.data.message ) || ( i18n.connectionFail || 'Connection failed.' );
						showPill( pill, 'error', msg );
					}
				} ).catch( function () {
					setLoading( btn, false );
					showPill( pill, 'error', i18n.connectionFail || 'Connection failed.' );
				} );
			} );
		} );
	}

	/* ─── 2. Run sync now · Sync tab ─── */
	var syncPollHandle = null;

	function renderProgress( state ) {
		var host = document.getElementById( 'esx-sync-progress' );
		if ( ! host || ! state ) { return; }
		var phase   = state.phase || 'idle';
		var done    = parseInt( state.batches_done || 0, 10 );
		var total   = parseInt( state.batches_total || 0, 10 );
		var percent = total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0;

		var totals = state.totals || {};
		var html = ''
			+ '<div class="esx-stat-grid">'
			+   '<div class="esx-stat"><span class="esx-stat-number">' + ( totals.created  || 0 ) + '</span><span class="esx-stat-label">Created</span></div>'
			+   '<div class="esx-stat"><span class="esx-stat-number">' + ( totals.updated  || 0 ) + '</span><span class="esx-stat-label">Updated</span></div>'
			+   '<div class="esx-stat"><span class="esx-stat-number">' + ( totals.skipped  || 0 ) + '</span><span class="esx-stat-label">Skipped</span></div>'
			+   '<div class="esx-stat"><span class="esx-stat-number">' + ( totals.failed   || 0 ) + '</span><span class="esx-stat-label">Failed</span></div>'
			+ '</div>'
			+ '<div class="esx-progress"><div class="esx-progress-fill" style="width:' + percent + '%"></div></div>'
			+ '<p class="esx-help">' + escapeHtml( phase ) + ' · ' + done + ' / ' + total + ' batches</p>';
		host.innerHTML = html;
	}

	function escapeHtml( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ];
		} );
	}

	function startPolling( runBtn ) {
		stopPolling();
		syncPollHandle = window.setInterval( function () {
			ajax( 'emailsendx_sync_status' ).then( function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					stopPolling();
					setLoading( runBtn, false );
					return;
				}
				renderProgress( res.data );
				if ( res.data.phase === 'done' || res.data.phase === 'error' ) {
					stopPolling();
					setLoading( runBtn, false );
				}
			} ).catch( function () {
				stopPolling();
				setLoading( runBtn, false );
			} );
		}, 2000 );
	}

	function stopPolling() {
		if ( syncPollHandle ) {
			window.clearInterval( syncPollHandle );
			syncPollHandle = null;
		}
	}

	function bindRunSync() {
		$$( '[data-esx-action="run-sync"]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();

				// Source — radio cards override the button default. ShaonPro.
				var checked = document.querySelector( 'input[name="esx_source"]:checked' );
				var source  = ( checked && checked.value ) || btn.getAttribute( 'data-source' ) || 'users';

				// List — override <select> wins, then button default.
				var listSel = document.getElementById( 'esx-list-override' );
				var listId  = ( listSel && listSel.value ) || btn.getAttribute( 'data-list-id' ) || '';

				var pill = document.getElementById( 'esx-sync-result' );
				if ( pill ) { pill.hidden = true; }

				setLoading( btn, true );
				ajax( 'emailsendx_run_sync', { source: source, list_id: listId } ).then( function ( res ) {
					if ( res && res.success ) {
						if ( res.data ) { renderProgress( res.data ); }
						startPolling( btn );
						// If the server already returned a final envelope (small batch),
						// the polling loop will see phase==='done' on its first tick.
					} else {
						setLoading( btn, false );
						// Try the standard `data.message`, then fall back to errors[] from
						// the run envelope (which is what the server returns on failure).
						var msg = ( res && res.data && res.data.message )
							|| ( res && res.data && Array.isArray( res.data.errors ) && res.data.errors[0] )
							|| ( i18n.syncFail || 'Could not start sync.' );
						showPill( pill, 'error', msg );
					}
				} ).catch( function () {
					setLoading( btn, false );
					showPill( pill, 'error', i18n.syncFail || 'Network error. Please retry.' );
				} );
			} );
		} );

		// Reflect the selected source visually on the radio cards & segmented pills. ShaonPro.
		$$( 'input[name="esx_source"]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				syncSelectedSourceUI();
				updateHeroLabel();
			} );
		} );
		// Same when the target list changes.
		var listSel = document.getElementById( 'esx-list-override' );
		if ( listSel ) {
			listSel.addEventListener( 'change', updateHeroLabel );
		}
		// Initial state.
		syncSelectedSourceUI();
		updateHeroLabel();
	}

	/**
	 * Toggle .is-selected on whichever source card / segment is checked.
	 * ShaonPro.
	 */
	function syncSelectedSourceUI() {
		$$( '.esx-source-card, .esx-seg' ).forEach( function ( el ) {
			var inp = el.querySelector( 'input[type="radio"]' );
			el.classList.toggle( 'is-selected', !! ( inp && inp.checked ) );
		} );
	}

	/**
	 * Read the embedded JSON data blocks and refresh the hero CTA's
	 * "Sync N contacts to LIST" label whenever source / list changes.
	 * ShaonPro.
	 */
	function readJsonBlock( id ) {
		var el = document.getElementById( id );
		if ( ! el ) { return null; }
		try { return JSON.parse( el.textContent || el.innerText || '{}' ); }
		catch ( e ) { return null; }
	}

	function formatCount( n ) {
		n = parseInt( n, 10 ) || 0;
		// Use the browser locale for consistent grouping. ShaonPro.
		try { return n.toLocaleString(); } catch ( e ) { return String( n ); }
	}

	function updateHeroLabel() {
		var btn = document.getElementById( 'esx-run-sync' );
		if ( ! btn ) { return; }
		var countEl = btn.querySelector( '.esx-btn-hero-count' );
		var listEl  = btn.querySelector( '.esx-btn-hero-list' );
		if ( ! countEl || ! listEl ) { return; }

		var checked = document.querySelector( 'input[name="esx_source"]:checked' );
		var source  = ( checked && checked.value ) || 'users';

		var counts = readJsonBlock( 'esx-source-counts' ) || {};
		var names  = readJsonBlock( 'esx-list-names' ) || {};

		var listSel = document.getElementById( 'esx-list-override' );
		var listId  = ( listSel && listSel.value ) || btn.getAttribute( 'data-list-id' ) || '';
		// Empty value in the override = "use default".
		if ( '' === listId ) { listId = btn.getAttribute( 'data-list-id' ) || ''; }

		var listName = names[ listId ] || names.__default__ || listId;
		var count    = counts[ source ] != null ? counts[ source ] : 0;

		countEl.setAttribute( 'data-source-count', source );
		countEl.textContent = formatCount( count );
		listEl.textContent  = listName;
	}

	/* ─── 3. Mapping UI ─── */

	/** Track the select that triggered the modal so we can update it on success. */
	var modalOriginSelect = null;

	function bindMappingSelects( root ) {
		$$( '.esx-target-select', root || document ).forEach( function ( sel ) {
			if ( sel.dataset.esxBound ) { return; }
			sel.dataset.esxBound = '1';
			// Remember the prior value so we can restore it if the modal is cancelled. ShaonPro.
			sel.dataset.esxLast = sel.value;
			sel.addEventListener( 'change', function () {
				if ( sel.value === '__create__' ) {
					modalOriginSelect = sel;
					openModal();
				} else {
					sel.dataset.esxLast = sel.value;
				}
			} );
		} );
	}

	function bindAddRow() {
		$$( '[data-esx-action="add-row"]' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var tpl = document.getElementById( 'esx-mapping-row-template' );
				var tbody = $( '.esx-mapping-rows' );
				if ( ! tpl || ! tbody ) { return; }
				var clone = tpl.content ? tpl.content.cloneNode( true ) : null;
				if ( ! clone ) { return; }
				tbody.appendChild( clone );
				var lastRow = tbody.lastElementChild;
				if ( lastRow ) {
					wireExtraRow( lastRow );
					bindMappingSelects( lastRow );
				}
			} );
		} );

		// Also wire any pre-existing extra rows that came from the server.
		$$( '.esx-mapping-row-extra' ).forEach( wireExtraRow );

		// Remove-row delegation.
		document.addEventListener( 'click', function ( e ) {
			var t = e.target;
			if ( t && t.classList && t.classList.contains( 'esx-remove-row' ) ) {
				var tr = t.closest( 'tr' );
				if ( tr && tr.parentNode ) { tr.parentNode.removeChild( tr ); }
			}
		} );
	}

	/**
	 * Keep the target <select>'s `name` attribute in sync with whatever
	 * the user types in the source-key text input. ShaonPro.
	 */
	function wireExtraRow( row ) {
		var keyInput = $( '.esx-extra-source', row );
		var sel      = $( '.esx-target-select', row );
		if ( ! keyInput || ! sel ) { return; }
		var template = keyInput.getAttribute( 'data-row-name-template' ) || '';

		function sync() {
			var raw = ( keyInput.value || '' ).trim();
			if ( raw && template ) {
				sel.setAttribute( 'name', template.replace( '__KEY__', raw ) );
			} else {
				sel.removeAttribute( 'name' );
			}
		}
		keyInput.addEventListener( 'input', sync );
		sync();
	}

	function openModal() {
		var modal = document.getElementById( 'esx-create-field-modal' );
		if ( ! modal ) { return; }
		modal.hidden = false;
		var keyEl = $( '#esx-cf-key' ); if ( keyEl ) { keyEl.value = ''; keyEl.focus(); }
		var lblEl = $( '#esx-cf-label' ); if ( lblEl ) { lblEl.value = ''; }
		var err = $( '.esx-modal-error', modal ); if ( err ) { err.hidden = true; err.textContent = ''; }
	}

	function closeModal( restore ) {
		var modal = document.getElementById( 'esx-create-field-modal' );
		if ( modal ) { modal.hidden = true; }
		if ( restore && modalOriginSelect ) {
			modalOriginSelect.value = modalOriginSelect.dataset.esxLast || '';
		}
		modalOriginSelect = null;
	}

	function bindModal() {
		$$( '[data-esx-action="close-modal"]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { closeModal( true ); } );
		} );
		var submit = $( '[data-esx-action="submit-create-field"]' );
		if ( submit ) {
			submit.addEventListener( 'click', function () {
				var keyEl = $( '#esx-cf-key' );
				var lblEl = $( '#esx-cf-label' );
				var typEl = $( '#esx-cf-type' );
				var err   = $( '.esx-modal-error' );
				var key   = keyEl ? ( keyEl.value || '' ).trim() : '';
				var label = lblEl ? ( lblEl.value || '' ).trim() : '';
				var type  = typEl ? typEl.value : 'text';

				if ( ! /^[a-z][a-z0-9_]{0,49}$/.test( key ) ) {
					if ( err ) { err.hidden = false; err.textContent = i18n.badKey || 'Invalid key — use lowercase letters, digits, underscore.'; }
					return;
				}
				if ( ! label ) {
					if ( err ) { err.hidden = false; err.textContent = i18n.badLabel || 'Label is required.'; }
					return;
				}

				setLoading( submit, true );
				ajax( 'emailsendx_create_custom_field', { key: key, label: label, type: type } ).then( function ( res ) {
					setLoading( submit, false );
					if ( res && res.success ) {
						appendCustomOption( key, label );
						if ( modalOriginSelect ) {
							modalOriginSelect.value = 'custom::' + key;
							modalOriginSelect.dataset.esxLast = modalOriginSelect.value;
						}
						closeModal( false );
					} else {
						var msg = ( res && res.data && res.data.message ) || ( i18n.createFail || 'Could not create field.' );
						if ( err ) { err.hidden = false; err.textContent = msg; }
					}
				} ).catch( function () {
					setLoading( submit, false );
					if ( err ) { err.hidden = false; err.textContent = i18n.createFail || 'Could not create field.'; }
				} );
			} );
		}
	}

	/** Append a freshly-created custom field to every target select on the page. */
	function appendCustomOption( key, label ) {
		$$( '.esx-target-select' ).forEach( function ( sel ) {
			var group = sel.querySelector( 'optgroup.esx-optgroup-custom' );
			if ( ! group ) { return; }
			// Avoid duplicates if the same key was added twice. ShaonPro.
			if ( group.querySelector( 'option[value="custom::' + key + '"]' ) ) { return; }
			var opt = document.createElement( 'option' );
			opt.value = 'custom::' + key;
			opt.textContent = label;
			group.appendChild( opt );
		} );
	}

	/* ─── Boot ─── */
	function boot() {
		bindTestConnection();
		bindRunSync();
		bindMappingSelects();
		bindAddRow();
		bindModal();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

} )();
