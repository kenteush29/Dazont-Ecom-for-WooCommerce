/**
 * The Linking tab.
 *
 * Two gestures and no third: read the site again, and open a row to see which
 * pages should point at it. Nothing here writes anything — a row's button
 * opens the choice, and the choice is sent to the writing queue, where it is
 * reviewed like everything else this plugin writes.
 */
( function ( $ ) {
	'use strict';

	var L = window.dzeMesh || {};

	function post( action, data ) {
		return $.post( L.ajax, $.extend( { action: action, nonce: L.nonce }, data || {} ) );
	}

	/** The panel a row opens: the pages that should point at it. */
	function panel( $row, res ) {
		var rows = ( res && res.rows ) || [];
		var $cell = $( '<td colspan="4"></td>' );
		var $tr = $( '<tr class="dze-mesh-panel"></tr>' ).append( $cell );
		if ( ! rows.length ) {
			$cell.append( $( '<p class="description"></p>' ).text( L.none ) );
			return $tr;
		}
		if ( 'words' === res.how ) {
			$cell.append( $( '<p class="description"></p>' ).text( L.words ) );
		}
		var $list = $( '<div class="dze-mesh-list"></div>' );
		rows.forEach( function ( one ) {
			var $lab = $( '<label style="display:block;margin:4px 0;"></label>' );
			$lab.append( $( '<input type="checkbox" checked />' ).val( one.key ) );
			$lab.append( $( '<strong></strong>' ).text( ' ' + one.title + ' ' ) );
			$lab.append( $( '<span class="description"></span>' ).text( one.why ? '— ' + one.why : '' ) );
			$list.append( $lab );
		} );
		$cell.append( $list );
		$cell.append(
			$( '<p style="margin:10px 0 4px;"></p>' )
				.append( $( '<button type="button" class="button button-primary dze-mesh-send"></button>' ).text( L.add ) )
				.append( $( '<span class="description dze-mesh-said" style="margin-left:10px;"></span>' ) )
		);
		return $tr;
	}

	/**
	 * The one place a string is filled in, so a word the shop has not
	 * registered cannot stop the handler it sits in.
	 *
	 * `String.prototype.replace` called straight on an argument throws a
	 * TypeError when that argument is undefined, and every line after it in
	 * the same handler dies in silence — the fault that left the linking
	 * screen showing whatever it last held.
	 */
	function say( tpl ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;
		// Coerced, never called straight on the argument: a string the shop
		// has not registered is undefined, and `.replace` on it throws.
		return String( tpl == null ? '' : tpl )
			// The numbered form first, so "%1$s of %2$s" is read as two
			// positions rather than as two plain "%s" in the wrong order.
			.replace( /%(\d+)\$s/g, function ( m, i ) {
				var v = args[ parseInt( i, 10 ) - 1 ];
				return undefined === v ? m : String( v );
			} )
			.replace( /%s/g, function ( m ) {
				var v = args[ next++ ];
				return undefined === v ? m : String( v );
			} );
	}

	/** The pages ticked on the chooser, in the order the table draws them. */
	function pickedRows() {
		return $( '.dze-mesh-pages tbody .dze-mesh-cb:checked' ).closest( 'tr' );
	}

	/** The bar says what is ticked, and refuses to act when nothing is. */
	function refreshBulk() {
		var n = pickedRows().length;
		$( '.dze-mesh-pick' ).prop( 'disabled', ! n );
		$( '.dze-mesh-count' ).text( n ? say( L.picked, n ) : '' );
	}

	$( function () {
		// ---- WHICH PAGES TAKE PART ----
		// Ticks, SHIFT for a run of them, one bar, one press.
		var lastTicked = null;

		$( document ).on( 'click', '.dze-mesh-pages .dze-mesh-cb', function ( e ) {
			var $all = $( '.dze-mesh-pages tbody .dze-mesh-cb' );
			var here = $all.index( this );
			// SHIFT TAKES THE RUN BETWEEN THE TWO. It copies the box just
			// pressed onto every row between, which is what every list the
			// shop already uses does — never a toggle, which would leave the
			// run half on and half off.
			if ( e.shiftKey && null !== lastTicked && here !== lastTicked ) {
				var from = Math.min( here, lastTicked );
				var to   = Math.max( here, lastTicked );
				var on   = $( this ).prop( 'checked' );
				$all.slice( from, to + 1 ).prop( 'checked', on );
			}
			lastTicked = here;
			refreshBulk();
		} );

		$( document ).on( 'click', '.dze-mesh-all', function () {
			$( '.dze-mesh-pages tbody .dze-mesh-cb' ).prop( 'checked', $( this ).prop( 'checked' ) );
			lastTicked = null;
			refreshBulk();
		} );

		$( document ).on( 'click', '.dze-mesh-pick', function () {
			var $b  = $( this );
			var on  = '1' === String( $b.data( 'on' ) );
			var $rows = pickedRows();
			var ids = $rows.map( function () { return $( this ).data( 'id' ); } ).get();
			if ( ! ids.length ) { return; }
			$( '.dze-mesh-pick' ).prop( 'disabled', true );
			$( '.dze-mesh-msg' ).text( L.saving );
			post( 'dze_mesh_pick', { ids: ids, on: on ? 1 : 0 } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						$( '.dze-mesh-msg' ).text( ( res && res.data && res.data.message ) || L.failed );
						refreshBulk();
						return;
					}
					// EVERY FIGURE THE PRESS MOVED, MOVED. The summary above
					// the table states how many take part, and a screen that
					// states a figure and then does not keep it is worse than
					// one that states none.
					$rows.removeClass( 'is-in is-out' ).addClass( on ? 'is-in' : 'is-out' )
						.find( '.dze-mesh-in' ).text( on ? L.takes : L.leftout );
					$rows.find( '.dze-mesh-cb' ).prop( 'checked', false );
					$( '.dze-mesh-all' ).prop( 'checked', false );
					$( '.dze-mesh-pagesbox > summary' ).text(
						say( L.pagesOf, res.data.chosen, res.data.pages )
					);
					$( '.dze-mesh-msg' ).text( res.data.message || '' );
					lastTicked = null;
					refreshBulk();
				} )
				.fail( function () {
					$( '.dze-mesh-msg' ).text( L.failed );
					refreshBulk();
				} );
		} );

		$( '#dze-mesh-scan' ).on( 'click', function () {
			var $b = $( this );
			$b.prop( 'disabled', true );
			$( '#dze-mesh-state' ).text( L.reading );
			post( 'dze_mesh_scan' ).always( function () {
				window.location.reload();
			} );
		} );

		// One row, opened. A second press shuts it again: the panel is a look
		// at what would be done, not a step you have to undo.
		$( '#dze-mesh-needs' ).on( 'click', '.dze-mesh-pairs', function () {
			var $b = $( this );
			var $row = $b.closest( 'tr' );
			var $open = $row.next( '.dze-mesh-panel' );
			if ( $open.length ) {
				$open.remove();
				return;
			}
			$b.prop( 'disabled', true );
			var $wait = $( '<tr class="dze-mesh-panel"><td colspan="4"><span class="description"></span></td></tr>' );
			$wait.find( 'span' ).text( L.looking );
			$row.after( $wait );
			post( 'dze_mesh_pairs', { key: $row.data( 'key' ) } )
				.done( function ( res ) {
					$wait.replaceWith( panel( $row, ( res && res.data ) || {} ) );
				} )
				.fail( function () {
					$wait.find( 'span' ).text( L.failed );
				} )
				.always( function () {
					$b.prop( 'disabled', false );
				} );
		} );

		$( '#dze-mesh-needs' ).on( 'click', '.dze-mesh-send', function () {
			var $b = $( this );
			var $panel = $b.closest( '.dze-mesh-panel' );
			var $said = $panel.find( '.dze-mesh-said' );
			var from = $panel.find( 'input:checked' ).map( function () {
				return this.value;
			} ).get();
			if ( ! from.length ) {
				$said.text( L.nopick );
				return;
			}
			$b.prop( 'disabled', true );
			$said.text( L.sending );
			post( 'dze_mesh_queue', { to: $panel.prev( 'tr' ).data( 'key' ), from: from } )
				.done( function ( res ) {
					if ( res && res.success ) {
						$said.empty().append( sentSaid() );
						$panel.find( '.dze-mesh-list' ).remove();
						$b.remove();
					} else {
						$said.text( ( res && res.data && res.data.message ) || L.failed );
						$b.prop( 'disabled', false );
					}
				} )
				.fail( function () {
					$said.text( L.failed );
					$b.prop( 'disabled', false );
				} );
		} );

	// WHAT THE PRESS DID, and the way to what it produced. The sentence named
	// a tab and left the shop to go and find it; the text it is about is one
	// click away and takes nothing open here with it.
	function sentSaid() {
		var $s = $( '<span class="description"></span>' ).text( L.sent );
		if ( L.reviewUrl ) {
			$s.append( ' ' ).append(
				$( '<a></a>' ).attr( { href: L.reviewUrl, target: '_blank', rel: 'noopener' } ).text( L.reviewGo )
			);
		}
		return $s;
	}

		$( '#dze-mesh-ends' ).on( 'click', '.dze-mesh-out', function () {
			var $b = $( this );
			var $row = $b.closest( 'tr' );
			$b.prop( 'disabled', true ).text( L.sending );
			post( 'dze_mesh_out', { key: $row.data( 'key' ) } )
				.done( function ( res ) {
					$b.replaceWith(
						res && res.success
							? sentSaid()
							: $( '<span class="description"></span>' ).text( ( res && res.data && res.data.message ) || L.failed )
					);
				} )
				.fail( function () {
					$b.prop( 'disabled', false ).text( L.failed );
				} );
		} );
	} );
}( jQuery ) );
