/* EmailSendX Sync admin JS · ShaonPro */
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
	var syncPollHandle = null;   // setTimeout handle for the next tick.
	var syncPollStart  = 0;      // When polling started (ms).
	var syncRunId      = '';     // Current run_id returned by run_sync.
	var syncLastUpdate = 0;      // `updated_at` (unix ts) from last status response.
	var syncLastSeenAt = 0;      // Local ms when `updated_at` last changed.
	var syncLastPhase  = '';
	var syncRunBtn     = null;   // Cached reference to the button being polled for.
	var syncStallShown = false;

	/**
	 * Escape a string for safe HTML insertion. ShaonPro.
	 */
	function escapeHtml( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' } )[ c ];
		} );
	}

	/**
	 * Read the selected role slugs from the chipset. Empty when the source
	 * isn't users. ShaonPro.
	 */
	function getSelectedRoles() {
		return $$( 'input[name="esx_sync_roles[]"]:checked' ).map( function ( i ) {
			return i.value;
		} );
	}

	/**
	 * Seed the progress shell to a "queued / 0%" blank slate. Also clears any
	 * lingering stall banner so a new run starts clean. ShaonPro.
	 */
	function seedProgressShell( phaseLabel ) {
		var shell = $( '.esx-progress-shell' );
		if ( ! shell ) { return; }
		shell.hidden = false;
		shell.removeAttribute( 'hidden' );

		var label = $( '.esx-progress-label', shell );
		if ( label ) { label.textContent = phaseLabel || 'Queued'; }

		var pct = $( '.esx-progress-percent', shell );
		if ( pct ) { pct.textContent = '0%'; }

		var fill = $( '.esx-progress-bar-fill', shell );
		if ( fill ) { fill.style.width = '0%'; }

		var meta = $( '.esx-progress-meta', shell );
		if ( meta ) { meta.textContent = ''; }

		var hint = $( '.esx-progress-hint', shell );
		if ( hint ) { hint.textContent = ''; hint.hidden = true; }

		resetStatBlocks( shell );
		hideStallBanner( shell );
	}

	/**
	 * Render/update the numeric stat-grid inside the shell. Prefers updating
	 * existing DOM nodes so we don't thrash layout. Creates them once if the
	 * server rendered an empty container. ShaonPro.
	 */
	function renderStats( shell, totals ) {
		totals = totals || {};
		var grid = $( '.esx-progress-stats', shell );
		if ( ! grid ) { return; }

		var defs = [
			{ k: 'created', label: 'Created' },
			{ k: 'updated', label: 'Updated' },
			{ k: 'skipped', label: 'Skipped' },
			{ k: 'failed',  label: 'Failed'  }
		];

		// Build once if empty.
		if ( ! grid.querySelector( '[data-esx-stat]' ) ) {
			var frag = document.createDocumentFragment();
			defs.forEach( function ( d ) {
				var cell   = document.createElement( 'div' );
				cell.className = 'esx-stat';
				cell.setAttribute( 'data-esx-stat', d.k );
				var number = document.createElement( 'span' );
				number.className = 'esx-stat-number';
				number.textContent = '0';
				var label  = document.createElement( 'span' );
				label.className = 'esx-stat-label';
				label.textContent = d.label;
				cell.appendChild( number );
				cell.appendChild( label );
				frag.appendChild( cell );
			} );
			grid.appendChild( frag );
		}

		// Update text content only — keep structure stable.
		defs.forEach( function ( d ) {
			var cell = grid.querySelector( '[data-esx-stat="' + d.k + '"] .esx-stat-number' );
			if ( cell ) { cell.textContent = String( totals[ d.k ] || 0 ); }
		} );
	}

	function resetStatBlocks( shell ) {
		var grid = $( '.esx-progress-stats', shell );
		if ( ! grid ) { return; }
		$$( '.esx-stat-number', grid ).forEach( function ( n ) { n.textContent = '0'; } );
	}

	/**
	 * Back-compat: also render the legacy #esx-sync-progress host so older
	 * markup still shows progress. ShaonPro.
	 */
	function renderLegacyProgress( state ) {
		var host = document.getElementById( 'esx-sync-progress' );
		if ( ! host || ! state ) { return; }
		var phase   = state.phase_label || state.phase || 'idle';
		var done    = parseInt( state.batches_done || 0, 10 );
		var total   = parseInt( state.batches_total || 0, 10 );
		var percent = typeof state.percent === 'number'
			? Math.min( 100, Math.max( 0, Math.round( state.percent ) ) )
			: ( total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0 );

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

	/**
	 * Apply a status payload to the shell. ShaonPro.
	 */
	function renderProgress( state ) {
		if ( ! state ) { return; }

		var shell = $( '.esx-progress-shell' );
		if ( shell ) {
			shell.hidden = false;
			shell.removeAttribute( 'hidden' );

			var done  = parseInt( state.batches_done  || 0, 10 );
			var total = parseInt( state.batches_total || 0, 10 );
			var percent = typeof state.percent === 'number'
				? Math.min( 100, Math.max( 0, Math.round( state.percent ) ) )
				: ( total > 0 ? Math.min( 100, Math.round( ( done / total ) * 100 ) ) : 0 );

			var label = $( '.esx-progress-label', shell );
			if ( label ) {
				label.textContent = state.phase_label || state.phase || '';
			}

			var pctEl = $( '.esx-progress-percent', shell );
			if ( pctEl ) { pctEl.textContent = percent + '%'; }

			var fill = $( '.esx-progress-bar-fill', shell );
			if ( fill ) { fill.style.width = percent + '%'; }

			renderStats( shell, state.totals );

			var meta = $( '.esx-progress-meta', shell );
			if ( meta ) {
				var src = state.source_label || state.source || '';
				meta.textContent = 'Batch ' + done + ' of ' + total + ( src ? ' · ' + src : '' );
			}
		}

		// Back-compat — keep the legacy host in sync too.
		renderLegacyProgress( state );
	}

	/**
	 * Yellow "looks stuck" banner with a Retry button. Idempotent. ShaonPro.
	 */
	function showStallBanner( shell, reason ) {
		if ( ! shell || syncStallShown ) { return; }
		syncStallShown = true;

		var banner = $( '.esx-progress-stall', shell );
		if ( ! banner ) {
			banner = document.createElement( 'div' );
			banner.className = 'esx-progress-stall esx-pill esx-pill-warn';
			banner.setAttribute( 'role', 'status' );
			var msg = document.createElement( 'span' );
			msg.className = 'esx-progress-stall-msg';
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'esx-btn esx-btn-secondary esx-progress-stall-retry';
			btn.textContent = i18n.retry || 'Retry';
			btn.addEventListener( 'click', function () {
				if ( syncRunBtn ) {
					hideStallBanner( shell );
					// Simulate a fresh click so the full kick-off path re-runs.
					syncRunBtn.click();
				}
			} );
			banner.appendChild( msg );
			banner.appendChild( btn );
			shell.appendChild( banner );
		}
		var msgEl = banner.querySelector( '.esx-progress-stall-msg' );
		if ( msgEl ) { msgEl.textContent = reason || ( i18n.syncStall || 'Looks stuck — retry?' ); }
		banner.hidden = false;
	}

	function hideStallBanner( shell ) {
		syncStallShown = false;
		if ( ! shell ) { return; }
		var banner = $( '.esx-progress-stall', shell );
		if ( banner ) { banner.hidden = true; }
	}

	/**
	 * Small secondary line under the label, e.g. "Spawning background worker…".
	 */
	function setProgressHint( shell, text ) {
		if ( ! shell ) { return; }
		var hint = $( '.esx-progress-hint', shell );
		if ( ! hint ) {
			hint = document.createElement( 'div' );
			hint.className = 'esx-progress-hint';
			// Insert right after the label if possible, else append.
			var label = $( '.esx-progress-label', shell );
			if ( label && label.parentNode ) {
				label.parentNode.insertBefore( hint, label.nextSibling );
			} else {
				shell.appendChild( hint );
			}
		}
		if ( text ) {
			hint.textContent = text;
			hint.hidden = false;
		} else {
			hint.textContent = '';
			hint.hidden = true;
		}
	}

	/**
	 * Adaptive cadence:
	 *   - first 10s  → 1s tick
	 *   - next 20s   → 2s tick
	 *   - beyond 30s without updated_at progress → 4s tick
	 * ShaonPro.
	 */
	function pickNextDelay() {
		var nowMs   = Date.now();
		var elapsed = nowMs - syncPollStart;
		var sinceUpdate = syncLastSeenAt ? ( nowMs - syncLastSeenAt ) : 0;

		if ( elapsed < 10000 )                 { return 1000; }
		if ( sinceUpdate > 30000 )             { return 4000; }
		return 2000;
	}

	function scheduleNextPoll() {
		stopPolling();
		syncPollHandle = window.setTimeout( pollOnce, pickNextDelay() );
	}

	function pollOnce() {
		var shell = $( '.esx-progress-shell' );
		ajax( 'emailsendx_sync_status', syncRunId ? { run_id: syncRunId } : null ).then( function ( res ) {
			if ( ! res || ! res.success || ! res.data ) {
				stopPolling();
				setLoading( syncRunBtn, false );
				return;
			}
			var state = res.data;

			// Track updated_at deltas for adaptive cadence + stall detection.
			var upd = parseInt( state.updated_at || 0, 10 );
			if ( upd && upd !== syncLastUpdate ) {
				syncLastUpdate = upd;
				syncLastSeenAt = Date.now();
			}
			syncLastPhase = state.phase || syncLastPhase;

			renderProgress( state );

			// Polite "spawning worker…" line — show after 3s of queued.
			if ( state.phase === 'queued' ) {
				if ( Date.now() - syncPollStart > 3000 ) {
					setProgressHint( shell, i18n.workerSpawning || 'Spawning background worker…' );
				}
			} else {
				setProgressHint( shell, '' );
			}

			// Stall detection.
			var nowSec = Math.floor( Date.now() / 1000 );
			var staleFor = upd ? ( nowSec - upd ) : 0;
			var queuedTooLong = ( state.phase === 'queued' )
				&& ( Date.now() - syncPollStart > 15000 );
			if (
				( state.phase === 'running' || state.phase === 'queued' )
				&& ( staleFor > 45 || queuedTooLong )
			) {
				showStallBanner( shell );
			} else if ( state.phase === 'running' ) {
				hideStallBanner( shell );
			}

			if ( state.phase === 'done' ) {
				stopPolling();
				setLoading( syncRunBtn, false );
				hideStallBanner( shell );
				setProgressHint( shell, '' );

				var okPill = document.getElementById( 'esx-sync-result' );
				var t = state.totals || {};
				var summary = ( i18n.syncDone || 'Sync complete.' )
					+ ' · +' + ( t.created || 0 ) + ' / ~' + ( t.updated || 0 )
					+ ( ( t.failed || 0 ) ? ' / ✗' + t.failed : '' );
				showPill( okPill, 'ok', summary );

				// Optional page reload hook for hosts that opt in.
				if ( document.querySelector( '[data-esx-reload-on-done="1"]' ) ) {
					window.setTimeout( function () { location.reload(); }, 600 );
				}
				return;
			}

			if ( state.phase === 'error' ) {
				stopPolling();
				setLoading( syncRunBtn, false );
				var errPill = document.getElementById( 'esx-sync-result' );
				var msg = ( Array.isArray( state.errors ) && state.errors[0] )
					|| state.message
					|| ( state.totals && state.totals.failed ? 'Sync finished with errors.' : '' )
					|| ( i18n.syncFail || 'Sync failed.' );
				showPill( errPill, 'error', msg );
				return;
			}

			scheduleNextPoll();
		} ).catch( function () {
			// Transient network error — back off and try again instead of bailing hard.
			scheduleNextPoll();
		} );
	}

	function startPolling( runBtn, runId ) {
		stopPolling();
		syncRunBtn     = runBtn;
		syncRunId      = runId || '';
		syncPollStart  = Date.now();
		syncLastUpdate = 0;
		syncLastSeenAt = 0;
		syncLastPhase  = '';
		syncStallShown = false;
		// Kick off immediately — adaptive scheduler handles the rest.
		syncPollHandle = window.setTimeout( pollOnce, 800 );
	}

	function stopPolling() {
		if ( syncPollHandle ) {
			window.clearTimeout( syncPollHandle );
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

				// Roles — only meaningful for the users source.
				var roles = ( source === 'users' ) ? getSelectedRoles() : [];

				// Optional limit.
				var limitEl = document.getElementById( 'esx-sync-limit' );
				var limit   = ( limitEl && limitEl.value ) ? parseInt( limitEl.value, 10 ) : 0;

				var pill = document.getElementById( 'esx-sync-result' );
				if ( pill ) { pill.hidden = true; }

				// Seed UI: show the shell at 0% / "Queued" before the request even lands.
				seedProgressShell( 'Queued' );

				setLoading( btn, true );

				// URLSearchParams handles array values as roles[]=a&roles[]=b.
				var payload = { source: source, list_id: listId };
				if ( limit > 0 ) { payload.limit = String( limit ); }
				var body = new URLSearchParams();
				body.append( 'action', 'emailsendx_run_sync' );
				body.append( '_ajax_nonce', nonce );
				Object.keys( payload ).forEach( function ( k ) {
					if ( payload[ k ] === undefined || payload[ k ] === null || payload[ k ] === '' ) { return; }
					body.append( k, payload[ k ] );
				} );
				roles.forEach( function ( r ) { body.append( 'roles[]', r ); } );

				fetch( ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
						'X-WP-Nonce': nonce
					},
					body: body.toString()
				} ).then( function ( r ) {
					return r.json().catch( function () { return null; } );
				} ).then( function ( res ) {
					if ( res && res.success ) {
						var runId = ( res.data && res.data.run_id ) || '';
						// Server may include an initial snapshot — draw it if so.
						if ( res.data && ( res.data.phase || res.data.totals ) ) {
							renderProgress( res.data );
						}
						startPolling( btn, runId );
					} else {
						setLoading( btn, false );
						// Collapse the shell on kick-off failure — nothing to show.
						var shell = $( '.esx-progress-shell' );
						if ( shell ) { shell.hidden = true; }

						var msg = ( res && res.data && res.data.message )
							|| ( res && res.data && Array.isArray( res.data.errors ) && res.data.errors[0] )
							|| ( i18n.syncFail || 'Could not start sync.' );
						showPill( pill, 'error', msg );
					}
				} ).catch( function () {
					setLoading( btn, false );
					var shell = $( '.esx-progress-shell' );
					if ( shell ) { shell.hidden = true; }
					showPill( pill, 'error', i18n.syncFail || 'Network error. Please retry.' );
				} );
			} );
		} );

		// Reflect the selected source visually on the radio cards & segmented pills. ShaonPro.
		$$( 'input[name="esx_source"]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				syncSelectedSourceUI();
				applySourceScope();
				updateHeroLabel();
			} );
		} );
		// Same when the target list changes.
		var listSel = document.getElementById( 'esx-list-override' );
		if ( listSel ) {
			listSel.addEventListener( 'change', updateHeroLabel );
		}

		// Role chip bindings.
		bindRoleChips();

		// Initial state.
		syncSelectedSourceUI();
		applySourceScope();
		updateHeroLabel();
	}

	/**
	 * Role chipset: toggle .is-selected on the wrapping <label class="esx-chip">
	 * and recompute the hero label on any change. ShaonPro.
	 */
	function bindRoleChips() {
		var inputs = $$( 'input[name="esx_sync_roles[]"]' );
		inputs.forEach( function ( input ) {
			syncChipSelected( input );
			input.addEventListener( 'change', function () {
				syncChipSelected( input );
				updateHeroLabel();
			} );
		} );
	}

	function syncChipSelected( input ) {
		var label = input.closest ? input.closest( 'label.esx-chip' ) : null;
		if ( ! label ) {
			// Fall back to a parent <label> even if it doesn't carry the class yet.
			label = input.closest ? input.closest( 'label' ) : null;
		}
		if ( label ) {
			label.classList.toggle( 'is-selected', !! input.checked );
		}
	}

	/**
	 * Show/hide the users-only sections (role chips) based on the selected
	 * source. WooCommerce doesn't have WP roles so the chipset is irrelevant.
	 * ShaonPro.
	 */
	function applySourceScope() {
		var checked = document.querySelector( 'input[name="esx_source"]:checked' );
		var source  = ( checked && checked.value ) || 'users';
		$$( '[data-esx-scope="users-only"]' ).forEach( function ( el ) {
			el.hidden = ( source !== 'users' );
		} );
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

		// Base count for the source. May be an object ({ total, roles: {...} })
		// or a raw integer depending on how the server serialised it.
		var rawForSource = counts[ source ];
		var total = 0;
		var rolesMap = null;
		if ( rawForSource && typeof rawForSource === 'object' ) {
			total = parseInt( rawForSource.total || 0, 10 );
			rolesMap = rawForSource.roles || null;
		} else {
			total = parseInt( rawForSource || 0, 10 );
		}
		// Top-level `roles` map support (e.g. counts.roles[slug] = n). ShaonPro.
		if ( ! rolesMap && counts.roles && typeof counts.roles === 'object' ) {
			rolesMap = counts.roles;
		}

		var count = total;
		if ( source === 'users' && rolesMap ) {
			var selected = getSelectedRoles();
			var allRoles = $$( 'input[name="esx_sync_roles[]"]' );
			var isAll    = selected.length === 0 || selected.length === allRoles.length;
			if ( ! isAll ) {
				var sum = 0;
				var anyMatched = false;
				selected.forEach( function ( slug ) {
					if ( rolesMap[ slug ] != null ) {
						anyMatched = true;
						sum += parseInt( rolesMap[ slug ] || 0, 10 );
					}
				} );
				count = anyMatched ? sum : total;
			}
		}

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

	/* ─── 4. Admin notices: dismiss + countdown · ShaonPro ─── */

	var ESX_LARGE_SYNC_THRESHOLD = 5000;

	/**
	 * Fade the notice out, then remove from the DOM. Idempotent. ShaonPro.
	 */
	function fadeRemoveNotice( notice ) {
		if ( ! notice || notice.classList.contains( 'is-dismissing' ) ) { return; }
		notice.classList.add( 'is-dismissing' );
		window.setTimeout( function () {
			if ( notice && notice.parentNode ) {
				notice.parentNode.removeChild( notice );
			}
		}, 250 );
	}

	/**
	 * Delegate dismiss clicks across all `.esx-notice` rendered by the
	 * PHP notices service. Optimistic UI — server response is ignored.
	 */
	function bindNoticeDismiss() {
		document.addEventListener( 'click', function ( e ) {
			var t = e.target;
			if ( ! t ) { return; }
			var btn = t.closest ? t.closest( '[data-esx-action="dismiss-notice"]' ) : null;
			if ( ! btn ) { return; }
			var notice = btn.closest ? btn.closest( '.esx-notice' ) : null;
			if ( ! notice ) { return; }
			e.preventDefault();
			var key = notice.getAttribute( 'data-esx-notice-key' ) || '';
			fadeRemoveNotice( notice );
			if ( key ) {
				// Best-effort server persist; ignore the response.
				try { ajax( 'emailsendx_dismiss_notice', { key: key } ); } catch ( err ) {}
			}
		} );
	}

	/**
	 * Tick down any notice carrying `data-esx-countdown="<seconds>"`. When
	 * it reaches 0 the notice fades out — no server round-trip needed.
	 * Falls back to replacing the first integer in the message if the PHP
	 * side forgot to include a `.esx-countdown-value` span. ShaonPro.
	 */
	function bindNoticeCountdowns() {
		$$( '.esx-notice[data-esx-countdown]' ).forEach( function ( notice ) {
			var secs = parseInt( notice.getAttribute( 'data-esx-countdown' ) || '0', 10 );
			if ( ! isFinite( secs ) || secs <= 0 ) { return; }

			var valueEl = notice.querySelector( '.esx-countdown-value' );
			var msgEl   = notice.querySelector( '.esx-notice-message' );
			var fallbackText = null;
			if ( ! valueEl && msgEl ) {
				fallbackText = msgEl.textContent || '';
			}

			function render( n ) {
				if ( valueEl ) {
					valueEl.textContent = String( n );
				} else if ( msgEl && fallbackText !== null ) {
					msgEl.textContent = fallbackText.replace( /\d+/, String( n ) );
				}
			}

			render( secs );
			var handle = window.setInterval( function () {
				secs -= 1;
				if ( secs <= 0 ) {
					window.clearInterval( handle );
					render( 0 );
					fadeRemoveNotice( notice );
					return;
				}
				render( secs );
			}, 1000 );
		} );
	}

	/* ─── 5. Large-sync confirm modal · ShaonPro ─── */

	var esxConfirmModalEl = null;

	function buildConfirmModal() {
		if ( esxConfirmModalEl ) { return esxConfirmModalEl; }

		var backdrop = document.createElement( 'div' );
		backdrop.className = 'esx-confirm-modal-backdrop';
		backdrop.hidden = true;

		var modal = document.createElement( 'div' );
		modal.className = 'esx-confirm-modal';
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );

		var title = document.createElement( 'h2' );
		title.className = 'esx-confirm-modal-title';

		var text  = document.createElement( 'p' );
		text.className = 'esx-confirm-modal-text';

		var actions = document.createElement( 'div' );
		actions.className = 'esx-confirm-modal-actions';

		var cancelBtn = document.createElement( 'button' );
		cancelBtn.type = 'button';
		cancelBtn.className = 'esx-btn esx-btn-secondary';
		cancelBtn.setAttribute( 'data-esx-confirm-action', 'cancel' );
		cancelBtn.textContent = i18n.cancel || 'Cancel';

		var okBtn = document.createElement( 'button' );
		okBtn.type = 'button';
		okBtn.className = 'esx-btn esx-btn-primary';
		okBtn.setAttribute( 'data-esx-confirm-action', 'confirm' );
		okBtn.textContent = i18n.continueLabel || 'Continue';

		actions.appendChild( cancelBtn );
		actions.appendChild( okBtn );
		modal.appendChild( title );
		modal.appendChild( text );
		modal.appendChild( actions );
		backdrop.appendChild( modal );
		document.body.appendChild( backdrop );

		esxConfirmModalEl = {
			backdrop: backdrop,
			title:    title,
			text:     text,
			cancel:   cancelBtn,
			ok:       okBtn,
			current:  null   // { btn: <button> } waiting on a decision.
		};

		function close() {
			backdrop.hidden = true;
			esxConfirmModalEl.current = null;
		}

		cancelBtn.addEventListener( 'click', close );
		backdrop.addEventListener( 'click', function ( e ) {
			if ( e.target === backdrop ) { close(); }
		} );
		okBtn.addEventListener( 'click', function () {
			var ctx = esxConfirmModalEl.current;
			close();
			if ( ctx && ctx.btn ) {
				ctx.btn.dataset.esxConfirmed = '1';
				// Re-dispatch the click — capture-phase guard will let it through.
				ctx.btn.click();
			}
		} );

		return esxConfirmModalEl;
	}

	function openConfirmModal( btn, count ) {
		var m = buildConfirmModal();
		var nStr = formatCount( count );
		m.title.textContent = ( i18n.largeSyncTitle
			? i18n.largeSyncTitle.replace( '{N}', nStr )
			: ( 'Sync ' + nStr + ' contacts?' ) );
		m.text.textContent  = i18n.largeSyncText
			|| 'This may take a few minutes. The sync runs in the background and you\'ll see progress below.';
		m.current = { btn: btn };
		m.backdrop.hidden = false;
		// Focus the primary action for keyboard users.
		try { m.ok.focus(); } catch ( e ) {}
	}

	/**
	 * Capture-phase guard: intercept run-sync clicks BEFORE bindRunSync's
	 * bubble-phase handler sees them. When the current source's count is
	 * >= threshold we open the confirm modal; otherwise we let the click
	 * fall through untouched. A `dataset.esxConfirmed` flag is set by the
	 * modal's Continue button so the re-dispatched click passes through.
	 * ShaonPro.
	 */
	function bindLargeSyncConfirm() {
		document.addEventListener( 'click', function ( e ) {
			var t = e.target;
			if ( ! t ) { return; }
			var btn = t.closest ? t.closest( '[data-esx-action="run-sync"]' ) : null;
			if ( ! btn ) { return; }

			// Already confirmed (this is the re-dispatched click) — let it through.
			if ( btn.dataset && btn.dataset.esxConfirmed === '1' ) {
				delete btn.dataset.esxConfirmed;
				return;
			}

			// Resolve source the same way bindRunSync does.
			var checked = document.querySelector( 'input[name="esx_source"]:checked' );
			var source  = ( checked && checked.value ) || btn.getAttribute( 'data-source' ) || 'users';

			// Look up the current count for that source.
			var counts = readJsonBlock( 'esx-source-counts' ) || {};
			var raw    = counts[ source ];
			var total  = 0;
			if ( raw && typeof raw === 'object' ) {
				total = parseInt( raw.total || 0, 10 );
			} else {
				total = parseInt( raw || 0, 10 );
			}

			if ( total < ESX_LARGE_SYNC_THRESHOLD ) { return; }

			// Intercept — stop the bubble-phase handler from firing.
			e.stopImmediatePropagation();
			e.preventDefault();
			openConfirmModal( btn, total );
		}, true ); // capture phase — runs before the bubble-phase listener in bindRunSync.
	}

	/**
	 * Repaint Settings default-list `<select>` from AJAX payload. ShaonPro.
	 */
	function refillSettingsListSelect( sel, lists ) {
		var v   = sel.value;
		var ph  = ( i18n.listPlaceholder != null ) ? i18n.listPlaceholder : '\u2014 Select a list \u2014';
		while ( sel.firstChild ) {
			sel.removeChild( sel.firstChild );
		}
		var o0 = document.createElement( 'option' );
		o0.value = '';
		o0.textContent = ph;
		sel.appendChild( o0 );
		lists.forEach( function ( L ) {
			if ( ! L || L.id == null || L.id === '' ) { return; }
			var o = document.createElement( 'option' );
			o.value = String( L.id );
			o.textContent = ( L.name != null && String( L.name ) !== '' ) ? String( L.name ) : String( L.id );
			sel.appendChild( o );
		} );
		var found = false;
		[].forEach.call( sel.options, function ( op ) {
			if ( op.value === v ) { found = true; }
		} );
		if ( found ) {
			sel.value = v;
		}
		sel.removeAttribute( 'data-esx-lists-stale' );
	}

	/**
	 * Repaint Sync hero list `<select>` — keeps the first option (Default / pick).
	 * ShaonPro.
	 */
	function refillHeroListSelect( sel, lists ) {
		var v = sel.value;
		var first = sel.options[ 0 ];
		var firstLabel = first ? first.textContent : ( ( i18n.listPickPlaceholder != null ) ? i18n.listPickPlaceholder : '\u2014 Pick a list \u2014' );
		var firstVal = first ? first.value : '';
		while ( sel.firstChild ) {
			sel.removeChild( sel.firstChild );
		}
		var o0 = document.createElement( 'option' );
		o0.value = firstVal;
		o0.textContent = firstLabel;
		sel.appendChild( o0 );
		lists.forEach( function ( L ) {
			if ( ! L || L.id == null || L.id === '' ) { return; }
			var o = document.createElement( 'option' );
			o.value = String( L.id );
			o.textContent = ( L.name != null && String( L.name ) !== '' ) ? String( L.name ) : String( L.id );
			sel.appendChild( o );
		} );
		var ok = false;
		[].forEach.call( sel.options, function ( op ) {
			if ( op.value === v ) { ok = true; }
		} );
		if ( ok ) {
			sel.value = v;
		}
		sel.removeAttribute( 'data-esx-lists-stale' );
	}

	/**
	 * When PHP could not flatten the API envelope, refetch lists via AJAX
	 * and rebuild the dropdowns (same normaliser as the server). ShaonPro.
	 */
	function bindListDropdownAjax() {
		var sDef = document.getElementById( 'esx-default-list' );
		var sHero = document.getElementById( 'esx-list-override' );
		if ( ! sDef && ! sHero ) {
			return;
		}
		var stale = ( sDef && sDef.getAttribute( 'data-esx-lists-stale' ) ) || ( sHero && sHero.getAttribute( 'data-esx-lists-stale' ) );
		var few = ( sDef && sDef.options.length <= 1 ) || ( sHero && sHero.options.length <= 1 );
		if ( ! stale && ! few ) {
			return;
		}
		ajax( 'emailsendx_get_lists', {} ).then( function ( res ) {
			if ( ! res || ! res.success || ! res.data || ! Array.isArray( res.data.lists ) ) {
				return;
			}
			var lists = res.data.lists;
			if ( lists.length === 0 ) {
				return;
			}
			if ( sDef ) {
				refillSettingsListSelect( sDef, lists );
			}
			if ( sHero ) {
				refillHeroListSelect( sHero, lists );
			}
		} ).catch( function () {} );
	}

	/* ─── Boot ─── */
	function boot() {
		bindTestConnection();
		bindRunSync();
		bindMappingSelects();
		bindAddRow();
		bindModal();
		bindListDropdownAjax();
		// Notices + large-sync confirm. ShaonPro.
		bindLargeSyncConfirm();
		bindNoticeDismiss();
		bindNoticeCountdowns();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

} )();
