/**
 * Coywolf PDF Viewer — editor block (no build step; uses window.wp.*).
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.data ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var __ = wp.i18n.__;
	var apiFetch = wp.apiFetch;
	var useSelect = wp.data.useSelect;
	var select = wp.data.select;
	var dispatch = wp.data.dispatch;
	var blockEditor = wp.blockEditor;
	var components = wp.components;

	var InspectorControls = blockEditor.InspectorControls;
	var useBlockProps = blockEditor.useBlockProps;
	var MediaUpload = blockEditor.MediaUpload;
	var MediaUploadCheck = blockEditor.MediaUploadCheck;

	var PanelBody = components.PanelBody;
	var ToggleControl = components.ToggleControl;
	var SelectControl = components.SelectControl;
	var RangeControl = components.RangeControl;
	var TextControl = components.TextControl;
	var TextareaControl = components.TextareaControl;
	var Button = components.Button;
	var Modal = components.Modal;
	var Spinner = components.Spinner;
	var Placeholder = components.Placeholder;

	var cfg = window.coywolfCPVBlock || { defaults: {} };
	var defaults = cfg.defaults || {};

	// Inherit-able boolean attribute → settings/per-PDF option key.
	var optionKey = {
		download: 'download',
		print: 'print',
		fullscreen: 'fullscreen',
		sidebar: 'sidebar',
		search: 'search',
		zoomControls: 'zoom_controls',
		clickToLoad: 'click_to_load',
		showCaption: 'show_caption'
	};

	function snackbar( status, message, id ) {
		var notices = dispatch( 'core/notices' );
		if ( notices && notices.createNotice ) {
			notices.createNotice( status, message, {
				type: 'snackbar',
				id: 'coywolf-cpv-pdf-save-' + id
			} );
		}
	}

	// The settings default for an inherit-able boolean, refined by the
	// per-PDF option when one is set ('on'/'off').
	function inheritedBool( attr, pdf ) {
		var key = optionKey[ attr ];
		if ( pdf && pdf.options && ( 'on' === pdf.options[ key ] || 'off' === pdf.options[ key ] ) ) {
			return 'on' === pdf.options[ key ];
		}
		return !! defaults[ key ];
	}

	var pdfIcon = el(
		'svg',
		{ viewBox: '0 0 24 24', width: 24, height: 24, xmlns: 'http://www.w3.org/2000/svg' },
		el( 'path', {
			fill: 'currentColor',
			d: 'M6 2.5h7.6L19.5 8v13a1.5 1.5 0 0 1-1.5 1.5H6A1.5 1.5 0 0 1 4.5 21V4A1.5 1.5 0 0 1 6 2.5Zm7 1.9V8.5h4.1L13 4.4ZM8 12.6h1.4c.9 0 1.6.7 1.6 1.6s-.7 1.6-1.6 1.6h-.4v1.5H8v-4.7Zm1 2.3h.4a.6.6 0 1 0 0-1.3H9v1.3Zm3-2.3h1.5c1.2 0 2 .9 2 2.3s-.8 2.4-2 2.4H12v-4.7Zm1 3.7h.5c.6 0 1-.6 1-1.4 0-.8-.4-1.3-1-1.3H13v2.7Zm3.4-3.7H19v1h-1.6v.9H19v1h-1.6v1.8h-1v-4.7Z'
		} )
	);

	/**
	 * Add PDF form (the in-editor version of the Add PDF screen).
	 */
	function AddForm( props ) {
		var nameState = useState( '' );
		var name = nameState[ 0 ];
		var setName = nameState[ 1 ];
		var captionState = useState( '' );
		var caption = captionState[ 0 ];
		var setCaption = captionState[ 1 ];
		var sourceState = useState( 'media' );
		var source = sourceState[ 0 ];
		var setSource = sourceState[ 1 ];
		var mediaState = useState( null );
		var media = mediaState[ 0 ];
		var setMedia = mediaState[ 1 ];
		var urlState = useState( '' );
		var url = urlState[ 0 ];
		var setUrl = urlState[ 1 ];
		var optionsState = useState( {} );
		var options = optionsState[ 0 ];
		var setOptions = optionsState[ 1 ];
		var posterState = useState( null );
		var poster = posterState[ 0 ];
		var setPoster = posterState[ 1 ];
		var savingState = useState( false );
		var saving = savingState[ 0 ];
		var setSaving = savingState[ 1 ];
		var errorState = useState( '' );
		var error = errorState[ 0 ];
		var setError = errorState[ 1 ];

		function triState( key, label ) {
			var def = !! defaults[ key ];
			return el( SelectControl, {
				label: label,
				value: options[ key ] || '',
				options: [
					{ label: __( 'Default', 'coywolf-pdf-viewer' ) + ' (' + ( def ? __( 'On', 'coywolf-pdf-viewer' ) : __( 'Off', 'coywolf-pdf-viewer' ) ) + ')', value: '' },
					{ label: __( 'On', 'coywolf-pdf-viewer' ), value: 'on' },
					{ label: __( 'Off', 'coywolf-pdf-viewer' ), value: 'off' }
				],
				__nextHasNoMarginBottom: true,
				onChange: function ( v ) {
					var next = {};
					Object.keys( options ).forEach( function ( k ) {
						next[ k ] = options[ k ];
					} );
					next[ key ] = v;
					setOptions( next );
				}
			} );
		}

		function submit() {
			setError( '' );
			if ( 'media' === source && ! media ) {
				setError( __( 'Choose a PDF from the Media Library.', 'coywolf-pdf-viewer' ) );
				return;
			}
			if ( 'url' === source && ! url ) {
				setError( __( 'Enter the PDF URL.', 'coywolf-pdf-viewer' ) );
				return;
			}
			setSaving( true );
			var data = {
				name: name,
				caption: caption,
				source: source,
				attachment_id: media ? media.id : 0,
				url: url,
				options: options
			};
			if ( poster ) {
				data.options = Object.assign( {}, options, { poster_id: poster.id } );
			}
			apiFetch( {
				path: '/coywolf-cpv/v1/pdfs',
				method: 'POST',
				data: data
			} ).then( function ( pdf ) {
				setSaving( false );
				props.onCreated( pdf );
			} ).catch( function ( err ) {
				setSaving( false );
				setError( ( err && err.message ) ? err.message : __( 'Saving the PDF failed.', 'coywolf-pdf-viewer' ) );
			} );
		}

		return el(
			'div',
			{ className: 'coywolf-cpv-addform' },
			el( TextControl, {
				label: __( 'Name', 'coywolf-pdf-viewer' ),
				value: name,
				help: __( 'Defaults to the filename.', 'coywolf-pdf-viewer' ),
				__nextHasNoMarginBottom: true,
				onChange: setName
			} ),
			el( TextareaControl, {
				label: __( 'Caption', 'coywolf-pdf-viewer' ),
				value: caption,
				rows: 2,
				__nextHasNoMarginBottom: true,
				onChange: setCaption
			} ),
			el( SelectControl, {
				label: __( 'PDF file', 'coywolf-pdf-viewer' ),
				value: source,
				options: [
					{ label: __( 'Upload / choose from the Media Library', 'coywolf-pdf-viewer' ), value: 'media' },
					{ label: __( 'Serve from a URL', 'coywolf-pdf-viewer' ), value: 'url' }
				],
				__nextHasNoMarginBottom: true,
				onChange: setSource
			} ),
			'media' === source
				? el(
					MediaUploadCheck,
					{},
					el( MediaUpload, {
						allowedTypes: [ 'application/pdf' ],
						value: media ? media.id : undefined,
						onSelect: function ( m ) {
							setMedia( m );
							if ( ! name && m && m.title ) {
								setName( m.title );
							}
						},
						render: function ( o ) {
							return el(
								'div',
								{ className: 'coywolf-cpv-addform-media' },
								el( Button, { variant: 'secondary', onClick: o.open }, media ? __( 'Change PDF', 'coywolf-pdf-viewer' ) : __( 'Choose PDF', 'coywolf-pdf-viewer' ) ),
								media ? el( 'span', { className: 'coywolf-cpv-addform-file' }, media.filename || media.title || '' ) : null
							);
						}
					} )
				)
				: el( TextControl, {
					label: __( 'PDF URL', 'coywolf-pdf-viewer' ),
					hideLabelFromVision: true,
					placeholder: 'https://example.com/document.pdf',
					value: url,
					type: 'url',
					__nextHasNoMarginBottom: true,
					onChange: setUrl
				} ),
			el(
				MediaUploadCheck,
				{},
				el( MediaUpload, {
					allowedTypes: [ 'image' ],
					value: poster ? poster.id : undefined,
					onSelect: setPoster,
					render: function ( o ) {
						return el(
							'div',
							{ className: 'coywolf-cpv-addform-media' },
							el( Button, { variant: 'tertiary', onClick: o.open }, poster ? __( 'Change poster image', 'coywolf-pdf-viewer' ) : __( 'Poster image (optional)', 'coywolf-pdf-viewer' ) ),
							poster ? el( 'span', { className: 'coywolf-cpv-addform-file' }, poster.filename || poster.title || '' ) : null
						);
					}
				} )
			),
			el(
				'details',
				{ className: 'coywolf-cpv-addform-options' },
				el( 'summary', {}, __( 'Viewer options', 'coywolf-pdf-viewer' ) ),
				triState( 'download', __( 'Download button', 'coywolf-pdf-viewer' ) ),
				triState( 'print', __( 'Print button', 'coywolf-pdf-viewer' ) ),
				triState( 'fullscreen', __( 'Full screen button', 'coywolf-pdf-viewer' ) ),
				triState( 'sidebar', __( 'Sidebar (thumbnails & outline)', 'coywolf-pdf-viewer' ) ),
				triState( 'search', __( 'Search panel', 'coywolf-pdf-viewer' ) ),
				triState( 'zoom_controls', __( 'Zoom controls', 'coywolf-pdf-viewer' ) ),
				triState( 'click_to_load', __( 'Click to load', 'coywolf-pdf-viewer' ) )
			),
			error ? el( 'p', { className: 'coywolf-cpv-addform-error' }, error ) : null,
			el(
				'div',
				{ className: 'coywolf-cpv-addform-actions' },
				el( Button, { variant: 'primary', isBusy: saving, disabled: saving, onClick: submit }, __( 'Add PDF', 'coywolf-pdf-viewer' ) ),
				el( Button, { variant: 'tertiary', onClick: props.onBack }, __( 'Back', 'coywolf-pdf-viewer' ) )
			)
		);
	}

	/**
	 * PDF picker modal: search-as-you-type list + the Add PDF form.
	 */
	function Picker( props ) {
		var state = useState( '' );
		var query = state[ 0 ];
		var setQuery = state[ 1 ];
		var listState = useState( null );
		var items = listState[ 0 ];
		var setItems = listState[ 1 ];
		var loadingState = useState( false );
		var loading = loadingState[ 0 ];
		var setLoading = loadingState[ 1 ];
		var addingState = useState( false );
		var adding = addingState[ 0 ];
		var setAdding = addingState[ 1 ];

		function search( term ) {
			setLoading( true );
			apiFetch( { path: '/coywolf-cpv/v1/pdfs?s=' + encodeURIComponent( term ) } )
				.then( function ( res ) {
					setItems( res && res.pdfs ? res.pdfs : [] );
					setLoading( false );
				} )
				.catch( function () {
					setItems( [] );
					setLoading( false );
				} );
		}

		// Auto-filter as the user types (debounced); also runs on open (query '').
		useEffect( function () {
			if ( adding ) {
				return undefined;
			}
			var timer = setTimeout( function () {
				search( query );
			}, 300 );
			return function () {
				clearTimeout( timer );
			};
		}, [ query, adding ] );

		var body;
		if ( adding ) {
			body = el( AddForm, {
				onBack: function () {
					setAdding( false );
				},
				onCreated: function ( pdf ) {
					props.onSelect( pdf );
				}
			} );
		} else {
			var grid = null;
			if ( loading || null === items ) {
				grid = el( Spinner, {} );
			} else if ( items && items.length ) {
				grid = el(
					'div',
					{ className: 'coywolf-cpv-picker-list' },
					items.map( function ( pdf ) {
						return el(
							'button',
							{
								key: pdf.id,
								type: 'button',
								className: 'coywolf-cpv-picker-item',
								onClick: function () {
									props.onSelect( pdf );
								}
							},
							el( 'span', { className: 'coywolf-cpv-picker-icon' }, pdfIcon ),
							el(
								'span',
								{ className: 'coywolf-cpv-picker-text' },
								el( 'span', { className: 'coywolf-cpv-picker-name' }, pdf.name || __( '(untitled)', 'coywolf-pdf-viewer' ) ),
								pdf.caption ? el( 'span', { className: 'coywolf-cpv-picker-caption' }, pdf.caption ) : null
							),
							el( 'span', { className: 'coywolf-cpv-picker-badge' }, 'url' === pdf.source ? __( 'External', 'coywolf-pdf-viewer' ) : __( 'Media', 'coywolf-pdf-viewer' ) )
						);
					} )
				);
			} else if ( items ) {
				grid = el( 'p', {}, __( 'No PDFs found.', 'coywolf-pdf-viewer' ) );
			}

			body = el(
				Fragment,
				{},
				el(
					'div',
					{ className: 'coywolf-cpv-picker-search' },
					el( TextControl, {
						label: __( 'Search PDFs', 'coywolf-pdf-viewer' ),
						hideLabelFromVision: true,
						value: query,
						placeholder: __( 'Search PDFs…', 'coywolf-pdf-viewer' ),
						onChange: setQuery,
						__nextHasNoMarginBottom: true
					} ),
					el(
						Button,
						{
							variant: 'secondary',
							onClick: function () {
								setAdding( true );
							}
						},
						__( 'Add PDF', 'coywolf-pdf-viewer' )
					)
				),
				grid
			);
		}

		return el(
			Modal,
			{
				title: adding ? __( 'Add a PDF', 'coywolf-pdf-viewer' ) : __( 'Select a PDF', 'coywolf-pdf-viewer' ),
				onRequestClose: props.onClose,
				className: 'coywolf-cpv-picker'
			},
			body
		);
	}

	/**
	 * Block edit.
	 */
	function Edit( props ) {
		var a = props.attributes;
		var setAttributes = props.setAttributes;
		var blockProps = useBlockProps( { className: 'coywolf-cpv-editor' } );
		var pickerState = useState( false );
		var pickerOpen = pickerState[ 0 ];
		var setPickerOpen = pickerState[ 1 ];

		// The PDF's canonical record (name/caption/options) and the user's
		// staged edits (null = untouched). Staged edits are pushed to the PDF
		// only after the post itself is saved.
		var pdfState = useState( null );
		var pdf = pdfState[ 0 ];
		var setPdf = pdfState[ 1 ];
		var stagedState = useState( { name: null, caption: null } );
		var staged = stagedState[ 0 ];
		var setStaged = stagedState[ 1 ];
		var wasSavingPost = useRef( false );

		var isSavingPost = useSelect( function ( sel ) {
			var editor = sel( 'core/editor' );
			return editor ? ( editor.isSavingPost() && ! editor.isAutosavingPost() ) : false;
		}, [] );

		// Load the canonical record when the selected PDF changes.
		useEffect( function () {
			setPdf( null );
			setStaged( { name: null, caption: null } );
			if ( ! a.pdfId ) {
				return undefined;
			}
			var alive = true;
			apiFetch( { path: '/coywolf-cpv/v1/pdfs/' + a.pdfId } )
				.then( function ( res ) {
					if ( alive && res ) {
						setPdf( res );
						// Keep the stored fallback fresh.
						if ( res.name !== a.pdfName || res.url !== a.pdfUrl ) {
							setAttributes( { pdfName: res.name, pdfUrl: res.url } );
						}
					}
				} )
				.catch( function () {} );
			return function () {
				alive = false;
			};
		}, [ a.pdfId ] );

		// After the post finishes saving (autosaves excluded), push any staged
		// name/caption edits to the PDF — the same update the Edit PDF screen
		// performs. Failures keep the edits staged so re-saving retries.
		useEffect( function () {
			var finished = wasSavingPost.current && ! isSavingPost;
			wasSavingPost.current = isSavingPost;
			if ( ! finished || ! a.pdfId || ! pdf ) {
				return;
			}
			var editor = select( 'core/editor' );
			if ( editor && 'function' === typeof editor.didPostSaveRequestSucceed && ! editor.didPostSaveRequestSucceed() ) {
				return;
			}
			var data = {};
			if ( null !== staged.name && staged.name !== pdf.name ) {
				data.name = staged.name;
			}
			if ( null !== staged.caption && staged.caption !== pdf.caption ) {
				data.caption = staged.caption;
			}
			if ( ! Object.keys( data ).length ) {
				return;
			}
			var id = a.pdfId;
			apiFetch( {
				path: '/coywolf-cpv/v1/pdfs/' + id,
				method: 'POST',
				data: data
			} ).then( function ( res ) {
				setPdf( res );
				setStaged( { name: null, caption: null } );
				snackbar( 'success', __( 'PDF updated.', 'coywolf-pdf-viewer' ), id );
			} ).catch( function ( err ) {
				snackbar( 'error', ( err && err.message ) ? err.message : __( 'Updating the PDF failed.', 'coywolf-pdf-viewer' ), id );
			} );
		}, [ isSavingPost ] );

		// The effective value of an inherit-able boolean (override or
		// PDF/settings default).
		function resolvedBool( attr ) {
			var v = a[ attr ];
			return ( undefined === v || null === v ) ? inheritedBool( attr, pdf ) : !! v;
		}

		function inheritToggle( attr, label ) {
			var isInheriting = ( undefined === a[ attr ] || null === a[ attr ] );
			var value = isInheriting ? inheritedBool( attr, pdf ) : a[ attr ];
			var help = isInheriting ? __( 'Inheriting the PDF / site default.', 'coywolf-pdf-viewer' ) : __( 'Overriding for this block.', 'coywolf-pdf-viewer' );
			return el( ToggleControl, {
				label: label,
				checked: !! value,
				help: help,
				__nextHasNoMarginBottom: true,
				onChange: function ( v ) {
					var update = {};
					update[ attr ] = v;
					setAttributes( update );
				}
			} );
		}

		// Staged edit if any, else the canonical value once loaded, else the
		// stored block name while the lookup is still in flight.
		var pdfNameValue = null !== staged.name ? staged.name : ( pdf ? pdf.name : ( a.pdfName || '' ) );
		var pdfCaptionValue = null !== staged.caption ? staged.caption : ( pdf ? pdf.caption : '' );

		var inspector = el(
			InspectorControls,
			{},
			el(
				PanelBody,
				{ title: __( 'PDF', 'coywolf-pdf-viewer' ), initialOpen: true },
				el( 'p', {}, pdfNameValue ? pdfNameValue : __( 'No PDF selected.', 'coywolf-pdf-viewer' ) ),
				el(
					Button,
					{
						variant: 'secondary',
						onClick: function () {
							setPickerOpen( true );
						}
					},
					a.pdfId ? __( 'Replace PDF', 'coywolf-pdf-viewer' ) : __( 'Select PDF', 'coywolf-pdf-viewer' )
				),
				a.pdfId
					? el(
						'div',
						{ className: 'coywolf-cpv-pdf-fields' },
						el( TextControl, {
							label: __( 'PDF name', 'coywolf-pdf-viewer' ),
							value: pdfNameValue,
							help: __( 'Saved to the PDF when the post is saved.', 'coywolf-pdf-viewer' ),
							__nextHasNoMarginBottom: true,
							onChange: function ( v ) {
								setStaged( function ( prev ) {
									return { name: v, caption: prev.caption };
								} );
								// Keep the block's stored fallback in sync.
								setAttributes( { pdfName: v } );
							}
						} ),
						el( TextareaControl, {
							label: __( 'Caption', 'coywolf-pdf-viewer' ),
							value: pdfCaptionValue,
							rows: 3,
							help: __( 'Saved to the PDF when the post is saved.', 'coywolf-pdf-viewer' ),
							__nextHasNoMarginBottom: true,
							onChange: function ( v ) {
								setStaged( function ( prev ) {
									return { name: prev.name, caption: v };
								} );
							}
						} )
					)
					: null
			),
			el(
				PanelBody,
				{ title: __( 'Viewer', 'coywolf-pdf-viewer' ), initialOpen: false },
				el( SelectControl, {
					label: __( 'Height', 'coywolf-pdf-viewer' ),
					value: a.heightMode || '',
					options: [
						{ label: __( 'Inherit', 'coywolf-pdf-viewer' ), value: '' },
						{ label: __( 'Fit one page', 'coywolf-pdf-viewer' ), value: 'auto' },
						{ label: __( 'Fixed height', 'coywolf-pdf-viewer' ), value: 'fixed' }
					],
					help: __( 'Fit one page sizes the viewer to the PDF page, so exactly one page shows at a time.', 'coywolf-pdf-viewer' ),
					__nextHasNoMarginBottom: true,
					onChange: function ( v ) {
						setAttributes( { heightMode: v } );
					}
				} ),
				'fixed' === ( a.heightMode || ( pdf && pdf.options && pdf.options.height_mode ) || defaults.height_mode || 'auto' )
					? el( RangeControl, {
						label: __( 'Height (px)', 'coywolf-pdf-viewer' ),
						value: a.height || ( pdf && pdf.options && pdf.options.height ? pdf.options.height : ( defaults.height || 800 ) ),
						min: 200,
						max: 2000,
						step: 10,
						allowReset: true,
						help: a.height ? __( 'Overriding for this block.', 'coywolf-pdf-viewer' ) : __( 'Inheriting the PDF / site default.', 'coywolf-pdf-viewer' ),
						__nextHasNoMarginBottom: true,
						onChange: function ( v ) {
							setAttributes( { height: v || 0 } );
						}
					} )
					: null,
				el( SelectControl, {
					label: __( 'Color scheme', 'coywolf-pdf-viewer' ),
					value: a.theme || '',
					options: [
						{ label: __( 'Inherit', 'coywolf-pdf-viewer' ), value: '' },
						{ label: __( 'Light', 'coywolf-pdf-viewer' ), value: 'light' },
						{ label: __( 'Dark', 'coywolf-pdf-viewer' ), value: 'dark' },
						{ label: __( 'System', 'coywolf-pdf-viewer' ), value: 'system' }
					],
					__nextHasNoMarginBottom: true,
					onChange: function ( v ) {
						setAttributes( { theme: v } );
					}
				} ),
				el( SelectControl, {
					label: __( 'Default zoom', 'coywolf-pdf-viewer' ),
					value: a.zoom || '',
					options: [
						{ label: __( 'Inherit', 'coywolf-pdf-viewer' ), value: '' },
						{ label: __( 'Fit page', 'coywolf-pdf-viewer' ), value: 'fit-page' },
						{ label: __( 'Fit width', 'coywolf-pdf-viewer' ), value: 'fit-width' },
						{ label: __( 'Automatic', 'coywolf-pdf-viewer' ), value: 'automatic' }
					],
					__nextHasNoMarginBottom: true,
					onChange: function ( v ) {
						setAttributes( { zoom: v } );
					}
				} ),
				inheritToggle( 'download', __( 'Download button', 'coywolf-pdf-viewer' ) ),
				inheritToggle( 'print', __( 'Print button', 'coywolf-pdf-viewer' ) ),
				inheritToggle( 'fullscreen', __( 'Full screen button', 'coywolf-pdf-viewer' ) ),
				inheritToggle( 'sidebar', __( 'Sidebar (thumbnails & outline)', 'coywolf-pdf-viewer' ) ),
				inheritToggle( 'search', __( 'Search panel', 'coywolf-pdf-viewer' ) ),
				inheritToggle( 'zoomControls', __( 'Zoom controls', 'coywolf-pdf-viewer' ) ),
				inheritToggle( 'clickToLoad', __( 'Click to load', 'coywolf-pdf-viewer' ) ),
				inheritToggle( 'showCaption', __( 'Show caption', 'coywolf-pdf-viewer' ) )
			)
		);

		var body;
		if ( ! a.pdfId ) {
			body = el(
				Placeholder,
				{
					icon: pdfIcon,
					label: __( 'Coywolf PDF', 'coywolf-pdf-viewer' ),
					instructions: __( 'Choose a PDF from your library, or add a new one.', 'coywolf-pdf-viewer' )
				},
				el(
					Button,
					{
						variant: 'primary',
						onClick: function () {
							setPickerOpen( true );
						}
					},
					__( 'Select PDF', 'coywolf-pdf-viewer' )
				)
			);
		} else {
			// A lightweight preview card — the real viewer (with its
			// WebAssembly engine) is intentionally not loaded in the editor.
			var bits = [];
			if ( resolvedBool( 'download' ) ) {
				bits.push( __( 'Download', 'coywolf-pdf-viewer' ) );
			}
			if ( resolvedBool( 'print' ) ) {
				bits.push( __( 'Print', 'coywolf-pdf-viewer' ) );
			}
			if ( resolvedBool( 'fullscreen' ) ) {
				bits.push( __( 'Full screen', 'coywolf-pdf-viewer' ) );
			}
			if ( resolvedBool( 'search' ) ) {
				bits.push( __( 'Search', 'coywolf-pdf-viewer' ) );
			}
			body = el(
				'div',
				{ className: 'coywolf-cpv-card' },
				el( 'span', { className: 'coywolf-cpv-card-icon' }, pdfIcon ),
				el(
					'div',
					{ className: 'coywolf-cpv-card-text' },
					el( 'strong', {}, pdfNameValue || __( '(untitled PDF)', 'coywolf-pdf-viewer' ) ),
					el( 'span', { className: 'coywolf-cpv-card-meta' }, bits.length ? bits.join( ' · ' ) : __( 'Viewer', 'coywolf-pdf-viewer' ) ),
					a.pdfUrl
						? el( 'a', { href: a.pdfUrl, target: '_blank', rel: 'noreferrer noopener' }, __( 'Open PDF', 'coywolf-pdf-viewer' ) )
						: null
				),
				resolvedBool( 'showCaption' ) && pdfCaptionValue
					? el( 'span', { className: 'coywolf-cpv-card-caption' }, pdfCaptionValue )
					: null
			);
		}

		var modal = pickerOpen
			? el( Picker, {
				onClose: function () {
					setPickerOpen( false );
				},
				onSelect: function ( selected ) {
					setAttributes( {
						pdfId: selected.id,
						pdfName: selected.name || '',
						pdfUrl: selected.url || ''
					} );
					setPickerOpen( false );
				}
			} )
			: null;

		return el( Fragment, {}, inspector, el( 'div', blockProps, body ), modal );
	}

	/**
	 * Static fallback saved into post content (shown only if the plugin is gone).
	 */
	function save( props ) {
		var a = props.attributes;
		var blockProps = useBlockProps.save();
		if ( ! a.pdfId || ! a.pdfUrl ) {
			return null;
		}
		return el(
			'div',
			blockProps,
			el( 'a', { href: a.pdfUrl }, a.pdfName || a.pdfUrl )
		);
	}

	wp.blocks.registerBlockType( 'coywolf/pdf', {
		icon: pdfIcon,
		edit: Edit,
		save: save
	} );
} )( window.wp );
