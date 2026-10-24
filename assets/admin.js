/**
 * effi AI Corrector : analyse et correction par lots, CRON.
 *
 * Tout texte venant du serveur est inséré avec .text() ; seuls l'aperçu
 * (wp_text_diff() filtré par wp_kses_post()) et l'état du CRON (échappé côté
 * serveur) sont insérés en HTML.
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	var cfg = window.eacAdmin || {};
	var t = cfg.i18n || {};
	var batchSize = parseInt( cfg.batchSize, 10 ) || 50;
	var busy = false;
	var analysed = false;
	var dirty = false;

	var $progress = $( '#eac-progress' );
	var $bar = $progress.find( '.eac-progress-bar' );
	var $label = $progress.find( '.eac-progress-label' );
	var $message = $( '#eac-message' );
	var $cronMessage = $( '#eac-cron-message' );
	var $results = $( '#eac-results' );
	var $tbody = $results.find( 'tbody' );
	var $checkAll = $( '#eac-check-all' );
	var $errors = $( '#eac-errors' );
	var $errorList = $errors.find( 'ul' );
	var $correct = $( '#eac-correct' );

	/**
	 * sprintf minimal : %s, %d, %1$s, %2$d…
	 */
	function format( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;
		return String( str || '' ).replace( /%(\d+\$)?([sd])/g, function ( match, position, type ) {
			var index = position ? parseInt( position, 10 ) - 1 : next++;
			var value = args[ index ];
			if ( 'd' === type ) {
				return String( parseInt( value, 10 ) || 0 );
			}
			return undefined === value ? '' : String( value );
		} );
	}

	function request( action, data ) {
		return $.ajax( {
			url: cfg.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			timeout: 180000,
			data: $.extend( { action: action, nonce: cfg.nonce }, data || {} )
		} );
	}

	/** Motif d'échec d'une requête (.fail). */
	function failReason( xhr, status ) {
		if ( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ) {
			return String( xhr.responseJSON.data.message );
		}
		if ( 'timeout' === status ) {
			return t.timeout;
		}
		if ( 'parsererror' === status ) {
			return t.invalidResponse;
		}
		return format( t.httpError, xhr && xhr.status ? xhr.status : status );
	}

	/** Erreur renvoyée par wp_send_json_error(). */
	function responseError( response ) {
		if ( response && response.data && response.data.message ) {
			return { message: String( response.data.message ), details: response.data.details || [] };
		}
		return { message: t.invalidResponse, details: [] };
	}

	function showMessage( $box, type, text, details ) {
		var $list;
		$box.removeClass( 'notice-info notice-success notice-warning notice-error' )
			.addClass( 'notice-' + type )
			.empty()
			.append( $( '<p>' ).text( text ) )
			.prop( 'hidden', false );
		if ( details && details.length ) {
			$list = $( '<ul class="eac-error-list">' );
			$.each( details, function ( i, detail ) {
				$list.append( $( '<li>' ).text( detail ) );
			} );
			$box.append( $list );
		}
	}

	function setProgress( done, total, text ) {
		var percent = total > 0 ? Math.min( 100, Math.round( ( done * 100 ) / total ) ) : 0;
		$progress.prop( 'hidden', false );
		$bar.css( 'width', percent + '%' );
		$label.text( text );
	}

	function updateCorrectButton() {
		$correct.prop( 'disabled', busy || ! analysed || ! $tbody.find( '.eac-item-check:checked' ).length );
	}

	function setBusy( state ) {
		busy = state;
		$( '#eac-analyze, #eac-cron-toggle' ).prop( 'disabled', state );
		updateCorrectButton();
	}

	function titleNode( item ) {
		var title = item.title || '#' + item.id;
		if ( item.edit_url ) {
			return $( '<a target="_blank" rel="noopener noreferrer">' ).attr( 'href', item.edit_url ).text( title );
		}
		return $( '<span>' ).text( title );
	}

	function addItem( item ) {
		var $row = $( '<tr>' ).attr( 'data-id', parseInt( item.id, 10 ) );
		var $check = $( '<input type="checkbox" class="eac-item-check" checked>' )
			.val( parseInt( item.id, 10 ) )
			.attr( 'aria-label', item.title || '#' + item.id );
		var $cell = $( '<td>' )
			.append( $( '<strong>' ).append( titleNode( item ) ) )
			.append( ' ' )
			.append( $( '<span class="eac-id">' ).text( '#' + item.id ) );
		var $changes = $( '<ul class="eac-changes">' );

		if ( item.preview ) {
			$cell.append(
				$( '<details class="eac-preview">' )
					.append( $( '<summary>' ).text( t.preview ) )
					.append( $( '<div class="eac-diff">' ).html( item.preview ) )
			);
		}
		$.each( item.changes || [], function ( i, change ) {
			$changes.append( $( '<li>' ).text( change ) );
		} );
		if ( item.title_changed ) {
			$changes.append( $( '<li>' ).text( t.titleChange ) );
		}

		$row.append( $( '<th scope="row" class="check-column">' ).append( $check ) )
			.append( $cell )
			.append( $( '<td>' ).text( item.type || '' ) )
			.append( $( '<td>' ).append( $changes ) )
			.append( $( '<td class="eac-status">' ).text( '—' ) );
		$tbody.append( $row );
	}

	function addError( item ) {
		$errorList.append(
			$( '<li>' ).append( titleNode( item ) ).append( document.createTextNode( ' : ' + ( item.message || '' ) ) )
		);
		$errors.prop( 'hidden', false );
	}

	function analyze() {
		var checked = 0;
		var total = 0;
		var found = 0;
		var failed = 0;
		var previewsLeft = parseInt( cfg.previewMax, 10 ) || 5;

		if ( busy ) {
			return;
		}
		if ( dirty && ! window.confirm( t.unsavedSettings ) ) {
			return;
		}

		analysed = false;
		setBusy( true );
		$tbody.empty();
		$errorList.empty();
		$errors.prop( 'hidden', true );
		$results.prop( 'hidden', true );
		$message.prop( 'hidden', true );
		$checkAll.prop( { checked: true, disabled: false } );
		setProgress( 0, 1, format( t.analyzing, 0, 0 ) );

		function next( afterId ) {
			request( 'eac_analyze', { after_id: afterId, previews_left: previewsLeft } )
				.done( function ( response ) {
					var data;
					var error;
					if ( ! response || ! response.success ) {
						error = responseError( response );
						setBusy( false );
						showMessage( $message, 'error', error.message, error.details );
						return;
					}
					data = response.data;
					if ( 'number' === typeof data.total ) {
						total = data.total;
					}
					checked += parseInt( data.checked, 10 ) || 0;
					$.each( data.items || [], function ( i, item ) {
						if ( item.preview ) {
							previewsLeft--;
						}
						addItem( item );
						found++;
					} );
					$.each( data.errors || [], function ( i, item ) {
						addError( item );
						failed++;
					} );
					if ( found ) {
						$results.prop( 'hidden', false );
					}
					setProgress( checked, Math.max( total, checked ), format( t.analyzing, checked, Math.max( total, checked ) ) );

					if ( ! data.done ) {
						next( parseInt( data.last_id, 10 ) || 0 );
						return;
					}
					setProgress( 1, 1, '' );
					analysed = found > 0;
					setBusy( false );
					if ( found ) {
						showMessage( $message, 'info', format( t.analyzeDone, checked, found, failed ) );
					} else {
						showMessage( $message, failed ? 'warning' : 'success', format( t.nothingToFix, checked, failed ) );
					}
				} )
				.fail( function ( xhr, status ) {
					setBusy( false );
					showMessage( $message, 'error', format( t.analyzeFailed, checked, failReason( xhr, status ) ) );
				} );
		}

		next( 0 );
	}

	function correct() {
		var ids;
		var done = 0;
		var updated = 0;
		var other = 0;
		var failed = 0;

		if ( busy || ! analysed ) {
			return;
		}
		ids = $tbody.find( '.eac-item-check:checked' ).map( function () {
			return parseInt( this.value, 10 );
		} ).get();
		if ( ! ids.length ) {
			showMessage( $message, 'warning', t.noSelection );
			return;
		}
		if ( ! window.confirm( format( t.confirmCorrect, ids.length ) ) ) {
			return;
		}

		// Une nouvelle analyse sera nécessaire avant une autre correction.
		analysed = false;
		setBusy( true );
		$tbody.find( '.eac-item-check:not(:checked)' ).closest( 'tr' ).find( '.eac-status' ).text( t.statusExcluded );
		$tbody.find( '.eac-item-check' ).add( $checkAll ).prop( 'disabled', true );
		setProgress( 0, ids.length, format( t.correcting, 0, ids.length ) );

		function next() {
			var batch = ids.slice( done, done + batchSize );
			if ( ! batch.length ) {
				setBusy( false );
				showMessage( $message, failed ? 'warning' : 'success', format( t.correctDone, updated, other, failed ) );
				return;
			}

			request( 'eac_correct', { ids: batch } )
				.done( function ( response ) {
					var error;
					if ( ! response || ! response.success ) {
						error = responseError( response );
						setBusy( false );
						showMessage( $message, 'error', format( t.interrupted, done, error.message ), error.details );
						return;
					}
					$.each( response.data.results || [], function ( i, result ) {
						var label;
						if ( 'updated' === result.status ) {
							updated++;
							label = t.statusUpdated;
						} else if ( 'error' === result.status ) {
							failed++;
							label = t.statusError;
						} else if ( 'skipped' === result.status ) {
							other++;
							label = t.statusSkipped;
						} else {
							other++;
							label = t.statusUnchanged;
						}
						$tbody.find( 'tr[data-id="' + parseInt( result.id, 10 ) + '"] .eac-status' )
							.text( result.message ? label + ' : ' + result.message : label );
					} );
					done += batch.length;
					setProgress( done, ids.length, format( t.correcting, done, ids.length ) );
					next();
				} )
				.fail( function ( xhr, status ) {
					setBusy( false );
					showMessage( $message, 'error', format( t.interrupted, done, failReason( xhr, status ) ) );
				} );
		}

		next();
	}

	function toggleCron() {
		var $button = $( '#eac-cron-toggle' );
		var active = '1' === $button.attr( 'data-active' );

		if ( busy ) {
			return;
		}
		if ( ! active && ! window.confirm( t.confirmCron ) ) {
			return;
		}

		setBusy( true );
		request( 'eac_toggle_cron' )
			.done( function ( response ) {
				var error;
				setBusy( false );
				if ( ! response || ! response.success ) {
					error = responseError( response );
					showMessage( $cronMessage, 'error', error.message, error.details );
					return;
				}
				$button.attr( 'data-active', response.data.active ? '1' : '0' )
					.text( response.data.active ? t.cronDisable : t.cronEnable );
				$( '#eac-cron-status' ).html( response.data.status_html || '' );
				showMessage( $cronMessage, 'success', response.data.message || '' );
			} )
			.fail( function ( xhr, status ) {
				setBusy( false );
				showMessage( $cronMessage, 'error', failReason( xhr, status ) );
			} );
	}

	$( '#eac-analyze' ).on( 'click', analyze );
	$correct.on( 'click', correct );
	$( '#eac-cron-toggle' ).on( 'click', toggleCron );

	$checkAll.on( 'change', function () {
		$tbody.find( '.eac-item-check:not(:disabled)' ).prop( 'checked', this.checked );
		updateCorrectButton();
	} );
	$tbody.on( 'change', '.eac-item-check', updateCorrectButton );

	$( '#eac-default-rules' ).on( 'click', function () {
		if ( window.confirm( t.confirmDefaults ) ) {
			$( '#eac-rules-json' ).val( cfg.defaultRules || '' );
			dirty = true;
		}
	} );

	$( '#eac-settings' ).on( 'change input', ':input', function () {
		dirty = true;
	} );

	$( window ).on( 'beforeunload', function ( event ) {
		if ( busy ) {
			event.preventDefault();
			event.returnValue = t.leaveWarning;
			return t.leaveWarning;
		}
	} );
}( jQuery ) );
