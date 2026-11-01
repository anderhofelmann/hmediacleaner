/* global jQuery, ajaxurl, HMC */
( function ( $ ) {
	var failures = 0;

	function fmt( n ) {
		return Number( n ).toLocaleString( 'pt-BR' );
	}

	/* ------------------------------------------------------------ análise */

	function show( d ) {
		var indeterminate = ( d.percent === null );
		$( '#hmc-progress' ).prop( 'hidden', false );
		$( '#hmc-progress .hmc-bar' ).toggleClass( 'is-indeterminate', indeterminate );
		$( '#hmc-progress .hmc-bar span' ).css( 'width', ( indeterminate ? 100 : d.percent ) + '%' );
		$( '#hmc-step' ).text( d.step || '' );
		$( '#hmc-label' ).text( d.busy ? HMC.busy : d.label );
		$( '#hmc-detail' ).text( d.detail || '' );
	}

	function step( start ) {
		$( '#hmc-start, #hmc-continue, .hmc-bulk-btn' ).prop( 'disabled', true );
		$.post( ajaxurl, { action: 'hmc_scan', do: start ? 'start' : 'step', _ajax_nonce: HMC.nonce } )
			.done( function ( r ) {
				if ( ! r || ! r.success ) {
					return fail();
				}
				failures = 0;
				show( r.data );
				if ( r.data.finished ) {
					$( '#hmc-label' ).text( HMC.done );
					window.location.reload();
					return;
				}
				setTimeout( function () { step( false ); }, r.data.busy ? 3000 : 150 );
			} )
			.fail( fail );
	}

	// Falhas momentâneas (timeout do proxy, rede) são toleradas: a análise retoma de onde parou.
	function fail() {
		failures++;
		if ( failures <= 5 ) {
			setTimeout( function () { step( false ); }, 5000 * failures );
			return;
		}
		$( '#hmc-label' ).text( HMC.error );
		$( '#hmc-start, #hmc-continue' ).prop( 'disabled', false );
	}

	/* ------------------------------------------------------------ operações em lote */

	function bulk( $btn, cursor, totals ) {
		var data = {
			action: $btn.data( 'action' ) || 'hmc_bulk',
			_ajax_nonce: HMC.bulkNonce,
			op: $btn.data( 'op' ),
			view: $btn.data( 'view' ),
			kind: $btn.data( 'kind' ),
			s: $btn.data( 's' ),
			cursor: cursor
		};
		$.post( ajaxurl, data )
			.done( function ( r ) {
				if ( ! r || ! r.success ) {
					$( '#hmc-bulk-status' ).text( r && r.data && r.data.message ? r.data.message : HMC.error );
					$( '.hmc-bulk-btn' ).prop( 'disabled', false );
					return;
				}
				var d = r.data;
				if ( d.busy ) {
					setTimeout( function () { bulk( $btn, cursor, totals ); }, 3000 );
					return;
				}
				failures = 0;
				if ( d.total !== null && d.total !== undefined ) {
					totals.total = d.total;
				}
				totals.ok += d.ok;
				totals.skipped += d.skipped;
				Object.keys( d.reasons || {} ).forEach( function ( k ) {
					totals.reasons[ k ] = ( totals.reasons[ k ] || 0 ) + d.reasons[ k ];
				} );
				( d.messages || [] ).forEach( function ( m ) {
					if ( $( '#hmc-bulk-msgs li' ).length < 10 ) {
						$( '#hmc-bulk-msgs' ).append( $( '<li>' ).text( m ) );
					}
				} );
				var processed = totals.ok + totals.skipped;
				var pct = totals.total ? Math.min( 100, Math.round( processed / totals.total * 100 ) ) : 100;
				$( '#hmc-bulk-progress .hmc-bar span' ).css( 'width', pct + '%' );
				$( '#hmc-bulk-status' ).text(
					HMC.bulkStatus.replace( '%1$s', fmt( processed ) ).replace( '%2$s', fmt( totals.total ) ).replace( '%3$s', fmt( totals.ok ) ).replace( '%4$s', fmt( totals.skipped ) )
				);
				if ( d.done ) {
					if ( $btn.data( 'op' ) === 'preview' ) {
						// Pré-visualização: mostra o resultado aqui, sem recarregar (nada foi alterado).
						showPreview( totals );
						$( '.hmc-bulk-btn, #hmc-start' ).prop( 'disabled', false );
						return;
					}
					setTimeout( function () { window.location.reload(); }, 1500 );
					return;
				}
				setTimeout( function () { bulk( $btn, d.cursor, totals ); }, 150 );
			} )
			.fail( function () {
				failures++;
				if ( failures <= 5 ) {
					setTimeout( function () { bulk( $btn, cursor, totals ); }, 5000 * failures );
					return;
				}
				$( '#hmc-bulk-status' ).text( HMC.error );
				$( '.hmc-bulk-btn' ).prop( 'disabled', false );
			} );
	}

	function showPreview( totals ) {
		var $box = $( '#hmc-preview-result' ).empty().prop( 'hidden', false );
		$box.append( $( '<p>' ).append( $( '<strong>' ).text(
			HMC.previewResult.replace( '%1$s', fmt( totals.ok ) ).replace( '%2$s', fmt( totals.skipped ) )
		) ) );
		var keys = Object.keys( totals.reasons );
		if ( keys.length ) {
			var $ul = $( '<ul>' );
			keys.sort( function ( a, b ) { return totals.reasons[ b ] - totals.reasons[ a ]; } ).forEach( function ( k ) {
				$ul.append( $( '<li>' ).text( fmt( totals.reasons[ k ] ) + ' × ' + k ) );
			} );
			$box.append( $( '<p>' ).text( HMC.previewWhy ), $ul );
		}
		$( '#hmc-bulk-progress' ).prop( 'hidden', true );
	}

	$( function () {
		$( '#hmc-start' ).on( 'click', function () {
			if ( window.confirm( HMC.confirm ) ) {
				step( true );
			}
		} );
		$( '#hmc-continue' ).on( 'click', function () { step( false ); } );

		$( '.hmc-bulk-btn' ).on( 'click', function () {
			var $btn = $( this );
			var question = $btn.data( 'confirm' );
			if ( question && ! window.confirm( question ) ) {
				return;
			}
			$( '.hmc-bulk-btn, #hmc-start' ).prop( 'disabled', true );
			$( '#hmc-preview-result' ).prop( 'hidden', true ).empty();
			$( '#hmc-bulk-progress' ).prop( 'hidden', false );
			$( '#hmc-bulk-msgs' ).empty();
			bulk( $btn, 0, { total: 0, ok: 0, skipped: 0, reasons: {} } );
		} );

		// Confirmação para links de exclusão e para a ação em massa "Excluir definitivamente".
		$( document ).on( 'click', 'a.hmc-confirm', function ( e ) {
			if ( ! window.confirm( $( this ).data( 'confirm' ) ) ) {
				e.preventDefault();
			}
		} );
		$( '#hmc-items-form' ).on( 'submit', function ( e ) {
			var action = $( '#bulk-action-selector-top' ).val();
			if ( ! action || action === '-1' ) {
				action = $( '#bulk-action-selector-bottom' ).val();
			}
			if ( action === 'delete' && ! window.confirm( 'Excluir definitivamente os arquivos selecionados? Esta ação não pode ser desfeita.' ) ) {
				e.preventDefault();
			}
		} );
	} );
}( jQuery ) );
