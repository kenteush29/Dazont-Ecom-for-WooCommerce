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

	$( function () {
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
