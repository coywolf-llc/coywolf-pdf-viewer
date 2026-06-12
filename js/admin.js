/**
 * Coywolf PDF Viewer — admin screens (Add/Edit PDF media picker, delete
 * confirmations).
 */
( function ( $ ) {
	'use strict';

	var cfg = window.coywolfCPVAdmin || { i18n: {} };
	var frame = null;

	// jscolorpicker (the same picker Coywolf Video Manager bundles) on the
	// Settings and Add/Edit PDF screens. Clearing the swatch submits '',
	// which means "use the default".
	function initColorPickers() {
		if ( 'undefined' === typeof window.ColorPicker ) {
			return;
		}
		var fields = document.querySelectorAll( '.coywolf-cpv-color-field' );
		Array.prototype.forEach.call( fields, function ( field ) {
			var hidden = field.querySelector( '.coywolf-cpv-color-value' );
			var mount = field.querySelector( '.coywolf-cpv-color-mount' );
			if ( ! hidden || ! mount ) {
				return;
			}
			var picker = new window.ColorPicker( mount, {
				color: hidden.value || null,
				submitMode: 'instant',
				enableAlpha: false,
				formats: [ 'hex' ],
				defaultFormat: 'hex',
				showClearButton: true
			} );
			picker.on( 'pick', function ( color ) {
				var hex = '';
				if ( color ) {
					hex = color.string( 'hex' );
					if ( hex && '#' !== hex.charAt( 0 ) ) {
						hex = '#' + hex;
					}
					if ( hex.length > 7 ) {
						hex = hex.substring( 0, 7 ); // drop any alpha.
					}
				}
				hidden.value = hex;
			} );
		} );
	}

	$( function () {
		initColorPickers();

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

		// Poster image picker (click-to-load card).
		var posterFrame = null;
		$( '.coywolf-cpv-pick-poster' ).on( 'click', function ( event ) {
			event.preventDefault();
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			if ( ! posterFrame ) {
				posterFrame = window.wp.media( {
					title: cfg.i18n.choosePoster || 'Choose a poster image',
					button: { text: cfg.i18n.usePoster || 'Use this image' },
					library: { type: 'image' },
					multiple: false
				} );
				posterFrame.on( 'select', function () {
					var attachment = posterFrame.state().get( 'selection' ).first().toJSON();
					var thumb = attachment.sizes && ( attachment.sizes.medium || attachment.sizes.full );
					$( '.coywolf-cpv-poster-id' ).val( attachment.id );
					$( '.coywolf-cpv-poster-preview' ).html(
						$( '<img>', { src: thumb ? thumb.url : attachment.url, alt: '' } )
					);
					$( '.coywolf-cpv-clear-poster' ).show();
				} );
			}
			posterFrame.open();
		} );
		$( '.coywolf-cpv-clear-poster' ).on( 'click', function ( event ) {
			event.preventDefault();
			$( '.coywolf-cpv-poster-id' ).val( '0' );
			$( '.coywolf-cpv-poster-preview' ).empty();
			$( this ).hide();
		} );

		// Deleting a PDF also strips its block from content — confirm first.
		$( document ).on( 'click', '.coywolf-cpv-delete', function ( event ) {
			if ( ! window.confirm( cfg.i18n.confirmDelete || 'Delete this PDF?' ) ) {
				event.preventDefault();
			}
		} );
	} );
} )( window.jQuery );
