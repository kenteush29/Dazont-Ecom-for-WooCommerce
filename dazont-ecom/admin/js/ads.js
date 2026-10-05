/* Google Ads screen: quarantine buttons, the review of a page, the script to copy. */
( function ( $ ) {
	'use strict';
	var cfg = window.dzeAds || {};
	var t = cfg.i18n || {};

	function post( action, data ) {
		return $.post( cfg.ajaxUrl, $.extend( { action: action, nonce: cfg.nonce }, data ) );
	}

	function say( $cell, text ) {
		var $said = $cell.find( '.dze-ads-said' );
		if ( ! $said.length ) {
			$said = $( '<span class="dze-ads-said" aria-live="polite"></span>' ).appendTo( $cell );
		}
		$said.text( text );
	}

	// Take a product or a category out of the ads.
	$( document ).on( 'click', '.dze-ads-hold', function () {
		var $b = $( this );
		var kind = $b.data( 'kind' );
		if ( 'category' === kind && ! window.confirm( t.sureCat ) ) {
			return;
		}
		var $cell = $b.closest( 'td' );
		$b.prop( 'disabled', true ).text( t.working );
		post( 'dze_ads_hold', { kind: kind, id: $b.data( 'id' ), on: 1, spent: $b.data( 'spent' ) } )
			.done( function ( res ) {
				if ( res && res.success ) {
					$b.remove();
					say( $cell, res.data.message );
					$cell.closest( 'tr' ).find( 'td:nth-last-child(2)' ).html( '<span class="dze-ads-chip is-held"></span>' ).find( 'span' ).text( t.held );
				} else {
					$b.prop( 'disabled', false ).text( t.held );
					say( $cell, ( res && res.data && res.data.message ) || t.failed );
				}
			} )
			.fail( function () {
				$b.prop( 'disabled', false );
				say( $cell, t.failed );
			} );
	} );

	// The page is opened from the quarantine: that is the condition to bring it back.
	$( document ).on( 'click', '.dze-ads-open', function () {
		var $a = $( this );
		var $cell = $a.closest( 'td' );
		post( 'dze_ads_opened', { kind: $a.data( 'kind' ), id: $a.data( 'id' ) } ).done( function ( res ) {
			if ( res && res.success ) {
				$cell.find( '.dze-ads-release' ).prop( 'disabled', false );
				say( $cell, res.data.message );
			}
		} );
	} );

	$( document ).on( 'click', '.dze-ads-release', function () {
		var $b = $( this );
		var $cell = $b.closest( 'td' );
		$b.prop( 'disabled', true ).text( t.working );
		post( 'dze_ads_hold', { kind: $b.data( 'kind' ), id: $b.data( 'id' ), on: 0 } )
			.done( function ( res ) {
				if ( res && res.success ) {
					$cell.find( '.button' ).remove();
					say( $cell, res.data.message );
				} else {
					$b.prop( 'disabled', false ).text( t.back );
					say( $cell, ( res && res.data && res.data.message ) || t.failed );
				}
			} )
			.fail( function () {
				$b.prop( 'disabled', false ).text( t.back );
				say( $cell, t.failed );
			} );
	} );

	// The script, to paste into Google Ads.
	$( '#dze-ads-copy' ).on( 'click', function () {
		var box = document.getElementById( 'dze-ads-script' );
		var done = function () { $( '#dze-ads-copied' ).text( t.copied ); };
		var by_hand = function () {
			box.focus();
			box.select();
			$( '#dze-ads-copied' ).text( t.copyFail );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( box.value ).then( done, by_hand );
		} else {
			by_hand();
		}
	} );

	$( '#dze-ads-newkey' ).on( 'click', function () {
		if ( ! window.confirm( t.sureKey ) ) {
			return;
		}
		var $b = $( this ).prop( 'disabled', true );
		post( 'dze_ads_new_key', {} )
			.done( function ( res ) {
				if ( res && res.success ) {
					$( '#dze-ads-script' ).val( res.data.script );
					$( '#dze-ads-copied' ).text( '' );
				}
			} )
			.always( function () { $b.prop( 'disabled', false ); } );
	} );
} )( jQuery );
