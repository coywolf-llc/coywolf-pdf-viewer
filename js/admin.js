/**
 * Coywolf PDF Viewer — admin screens (Add/Edit PDF media picker, delete
 * confirmations).
 */
( function ( $ ) {
	'use strict';

	var cfg = window.coywolfCPVAdmin || { i18n: {} };
	var frame = null;

	$( function () {
		// Source radio toggles the Media Library / URL inputs.
		$( '.coywolf-cpv-source input[type="radio"]' ).on( 'change', function () {
			var media = 'media' === $( this ).val();
			$( '.coywolf-cpv-source-media' ).toggle( media );
			$( '.coywolf-cpv-source-url' ).toggle( ! media );
		} );

		// Media Library picker, restricted to PDFs.
		$( '.coywolf-cpv-pick' ).on( 'click', function ( event ) {
			event.preventDefault();
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			if ( ! frame ) {
				frame = window.wp.media( {
					title: cfg.i18n.choosePdf || 'Choose a PDF',
					button: { text: cfg.i18n.usePdf || 'Use this PDF' },
					library: { type: 'application/pdf' },
					multiple: false
				} );
				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					$( '.coywolf-cpv-attachment-id' ).val( attachment.id );
					$( '.coywolf-cpv-picked' ).text( attachment.filename || attachment.title || '' );
					var name = $( '#coywolf-cpv-name' );
					if ( name.length && '' === name.val() ) {
						name.val( attachment.title || '' );
					}
				} );
			}
			frame.open();
		} );

		// Deleting a PDF also strips its block from content — confirm first.
		$( document ).on( 'click', '.coywolf-cpv-delete', function ( event ) {
			if ( ! window.confirm( cfg.i18n.confirmDelete || 'Delete this PDF?' ) ) {
				event.preventDefault();
			}
		} );
	} );
} )( window.jQuery );
