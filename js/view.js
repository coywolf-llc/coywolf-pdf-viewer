/**
 * Coywolf PDF Viewer — front-end loader.
 *
 * Boots each embed lazily: the vendored EmbedPDF module (and its WebAssembly
 * engine) is dynamic-imported only when an embed scrolls near the viewport —
 * or on demand in click-to-load mode. The viewer is configured fully offline
 * (local engine, no CDN font/stamp fallbacks), so embeds make no third-party
 * requests.
 */
( function () {
	'use strict';

	var global = window.coywolfCPVView || {};
	var modulePromise = null;

	function loadModule() {
		if ( ! modulePromise ) {
			modulePromise = import( global.lib );
		}
		return modulePromise;
	}

	function zoomLevel( mod, zoom ) {
		var modes = mod.ZoomMode || {};
		if ( 'fit-width' === zoom ) {
			return modes.FitWidth;
		}
		if ( 'automatic' === zoom ) {
			return modes.Automatic;
		}
		return modes.FitPage;
	}

	// The snippet build's default viewport padding (viewportGap) and gap
	// between pages in a spread (pageGap).
	var VIEWPORT_GAP = 10;
	var PAGE_GAP = 10;

	// The width one page column gets inside the viewport: in a two-page
	// spread the pages share the row (minus the gap between them).
	function pageColumnWidth( width, cfg ) {
		var available = width - 2 * VIEWPORT_GAP;
		if ( 'odd' === cfg.spread || 'even' === cfg.spread ) {
			return ( available - PAGE_GAP ) / 2;
		}
		return available;
	}

	// Estimated viewer chrome (toolbar) height, used to pre-size auto-height
	// placeholders so loading the viewer doesn't shift the layout. The
	// post-load fit measures the real value and corrects any residue.
	var CHROME_ESTIMATE = 50;

	// Auto-height embeds that haven't booted yet: keep their placeholder
	// height matched to the page shape as the window resizes.
	var pending = [];
	var resizeQueued = false;

	function presize( embed, cfg ) {
		var width = embed.clientWidth;
		if ( ! width || ! cfg.ratio ) {
			return;
		}
		var px = Math.ceil( CHROME_ESTIMATE + 2 * VIEWPORT_GAP + cfg.ratio * pageColumnWidth( width, cfg ) );
		if ( embed.style.height !== px + 'px' ) {
			embed.style.aspectRatio = 'auto';
			embed.style.height = px + 'px';
		}
	}

	function watchPending( embed, cfg ) {
		presize( embed, cfg );
		pending.push( { embed: embed, cfg: cfg } );
		if ( 1 === pending.length ) {
			window.addEventListener( 'resize', function () {
				if ( resizeQueued ) {
					return;
				}
				resizeQueued = true;
				window.requestAnimationFrame( function () {
					resizeQueued = false;
					for ( var i = 0; i < pending.length; i++ ) {
						if ( ! pending[ i ].embed.getAttribute( 'data-cpv-loaded' ) ) {
							presize( pending[ i ].embed, pending[ i ].cfg );
						}
					}
				} );
			} );
		}
	}

	/**
	 * Auto height: size the embed so exactly one page fits. The page's
	 * aspect ratio comes from the loaded document (rotation-corrected, and
	 * re-read on page change so differently sized pages each fit); the
	 * viewer chrome (toolbar) height is measured as host height minus the
	 * scroll viewport height. Re-applies whenever the viewport resizes —
	 * the re-apply converges because the setter is value-idempotent.
	 */
	function autoFit( embed, viewer, cfg ) {
		viewer.registry.then( function ( registry ) {
			var dmPlugin = registry.getPlugin( 'document-manager' );
			var vpPlugin = registry.getPlugin( 'viewport' );
			var scrollPlugin = registry.getPlugin( 'scroll' );
			if ( ! dmPlugin || ! vpPlugin ) {
				return;
			}
			var dm = dmPlugin.provides();
			var vp = vpPlugin.provides();
			var scroll = scrollPlugin ? scrollPlugin.provides() : null;

			var aspect = null;
			var docState = null;

			function pageAspect( index ) {
				if ( ! docState || ! docState.document || ! docState.document.pages ) {
					return null;
				}
				var page = docState.document.pages[ index ];
				if ( ! page || ! page.size || ! page.size.width ) {
					return null;
				}
				var turns = ( ( page.rotation || 0 ) + ( docState.rotation || 0 ) ) % 4;
				return ( 1 === turns % 2 )
					? page.size.width / page.size.height
					: page.size.height / page.size.width;
			}

			function apply() {
				if ( null === aspect ) {
					return;
				}
				var metrics;
				try {
					metrics = vp.getMetrics();
				} catch ( e ) {
					return;
				}
				if ( ! metrics || ! metrics.clientHeight || ! metrics.clientWidth ) {
					return;
				}
				var chrome = embed.getBoundingClientRect().height - metrics.clientHeight;
				var pageWidth = pageColumnWidth( metrics.clientWidth, cfg );
				if ( chrome < 0 || pageWidth <= 0 ) {
					return;
				}
				var px = Math.ceil( chrome + 2 * VIEWPORT_GAP + aspect * pageWidth );
				if ( embed.style.height !== px + 'px' ) {
					embed.style.aspectRatio = 'auto';
					embed.style.height = px + 'px';
				}
			}

			// Replays the last value for late subscribers, so this also
			// covers documents that finished loading before we got here.
			dm.onDocumentOpened( function ( state ) {
				docState = state;
				aspect = pageAspect( 0 );
				apply();
			} );

			vp.onViewportResize( function () {
				apply();
			} );

			if ( scroll ) {
				if ( scroll.onLayoutReady ) {
					scroll.onLayoutReady( function () {
						apply();
					} );
				}
				// Pages can differ in size — re-fit to the page in view.
				if ( scroll.onPageChange ) {
					scroll.onPageChange( function ( event ) {
						var number = event && event.pageNumber ? event.pageNumber : 1;
						var next = pageAspect( number - 1 );
						if ( null !== next && next !== aspect ) {
							aspect = next;
							apply();
						}
					} );
				}
			}
		} ).catch( function () {
			// Keep the server-side aspect-ratio estimate.
		} );
	}

	function themeConfig( cfg ) {
		var theme = { preference: 'system' };
		if ( 'light' === cfg.theme || 'dark' === cfg.theme ) {
			theme.preference = cfg.theme;
		}
		if ( cfg.accent ) {
			var accent = { accent: { primary: cfg.accent } };
			theme.light = accent;
			theme.dark = accent;
		}
		return theme;
	}

	function disabledCategories( cfg ) {
		// Read-only embeds: editing tools stay off; visitor-facing features
		// follow the resolved settings.
		var disabled = [
			'annotation',
			'annotation-shape',
			'form',
			'redaction',
			'insert',
			'history',
			'capture',
			'document-open',
			'document-close',
			'document-capture',
			'document-protect',
			'panel-comment'
		];
		var f = cfg.features || {};
		if ( ! f.download ) {
			disabled.push( 'document-export' );
		}
		if ( ! f.print ) {
			disabled.push( 'document-print' );
		}
		if ( ! f.fullscreen ) {
			disabled.push( 'document-fullscreen' );
		}
		if ( ! f.sidebar ) {
			disabled.push( 'panel-sidebar' );
		}
		if ( ! f.search ) {
			disabled.push( 'panel-search' );
		}
		if ( ! f.zoom ) {
			disabled.push( 'zoom' );
		}
		return disabled;
	}

	/**
	 * Surface Download and Full screen as always-visible toolbar icons
	 * (they ship buried in the hamburger menu). Re-uses the built-in
	 * document:export / document:fullscreen commands, so the icons, F11
	 * shortcut, and active-state styling all come from the viewer itself;
	 * the surfaced entries are removed from the menu to avoid duplicates.
	 * Must run right after registry resolves — the toolbar reads the schema
	 * when the document UI mounts.
	 */
	function surfaceToolbarButtons( viewer, cfg ) {
		viewer.registry.then( function ( registry ) {
			var uiPlugin = registry.getPlugin( 'ui' );
			var features = cfg.features || {};
			if ( ! uiPlugin || ( ! features.download && ! features.fullscreen ) ) {
				return;
			}
			var ui = uiPlugin.provides();
			var schema = ui.getSchema();
			var toolbar = schema.toolbars && schema.toolbars[ 'main-toolbar' ];
			if ( ! toolbar || ! toolbar.items ) {
				return;
			}
			var items = JSON.parse( JSON.stringify( toolbar.items ) );
			var right = null;
			for ( var i = 0; i < items.length; i++ ) {
				if ( 'right-group' === items[ i ].id ) {
					right = items[ i ];
				}
			}
			if ( ! right || ! right.items ) {
				return;
			}

			var surfaced = [];
			if ( features.download ) {
				right.items.push( {
					type: 'command-button',
					id: 'coywolf-cpv-download',
					commandId: 'document:export',
					variant: 'icon',
					categories: [ 'document', 'document-export' ]
				} );
				surfaced.push( 'document:export' );
			}
			if ( features.fullscreen ) {
				right.items.push( {
					type: 'command-button',
					id: 'coywolf-cpv-fullscreen',
					commandId: 'document:fullscreen',
					variant: 'icon',
					categories: [ 'document', 'document-fullscreen' ]
				} );
				surfaced.push( 'document:fullscreen' );
				// The divider sits directly above the fullscreen menu entry;
				// removing the entry would leave it dangling.
				surfaced.push( 'divider-11' );
			}

			var merge = { toolbars: {} };
			// mergeSchema replaces a toolbar's whole items array, so always
			// pass the full (cloned + extended) list.
			merge.toolbars[ 'main-toolbar' ] = items.length ? Object.assign( {}, toolbar, { items: items } ) : toolbar;

			var menu = schema.menus && schema.menus[ 'document-menu' ];
			if ( menu && menu.items ) {
				merge.menus = {
					'document-menu': Object.assign( {}, menu, {
						items: menu.items.filter( function ( item ) {
							return -1 === surfaced.indexOf( item.id );
						} )
					} )
				};
			}

			ui.mergeSchema( merge );
		} ).catch( function () {
			// The stock toolbar (with the menu entries) keeps working.
		} );
	}

	function boot( embed, cfg ) {
		if ( embed.getAttribute( 'data-cpv-loaded' ) ) {
			return;
		}
		embed.setAttribute( 'data-cpv-loaded', '1' );
		embed.classList.add( 'coywolf-cpv-loading' );

		loadModule().then( function ( mod ) {
			var EmbedPDF = mod.default;
			var target = document.createElement( 'div' );
			target.className = 'coywolf-cpv-viewer';
			embed.textContent = '';
			embed.appendChild( target );

			// In auto-height mode the container is sized to the page, so
			// fit-width fills it exactly (and, being width-driven, can't
			// feedback-loop with our own height writes).
			var fitting = 'auto' === cfg.fit;

			var init = {
				type: 'container',
				target: target,
				src: cfg.src,
				wasmUrl: global.wasm,
				// Fully offline: no CDN fonts, stamps, or glyph fallbacks.
				fontFallback: null,
				fonts: { ui: null, signature: null },
				stamp: { manifests: [] },
				theme: themeConfig( cfg ),
				zoom: { defaultZoomLevel: fitting ? ( mod.ZoomMode || {} ).FitWidth : zoomLevel( mod, cfg.zoom ) },
				i18n: { defaultLocale: global.locale || 'en', fallbackLocale: 'en' },
				disabledCategories: disabledCategories( cfg ),
				'export': { defaultFileName: cfg.filename || 'document.pdf' }
			};
			if ( cfg.features && ! cfg.features.print ) {
				init.permissions = { overrides: { print: false } };
			}
			if ( 'horizontal' === cfg.scrollDir ) {
				init.scroll = { defaultStrategy: 'horizontal' };
			}
			if ( 'odd' === cfg.spread || 'even' === cfg.spread ) {
				var spreads = mod.SpreadMode || {};
				init.spread = {
					defaultSpreadMode: 'odd' === cfg.spread ? ( spreads.Odd || 'odd' ) : ( spreads.Even || 'even' )
				};
			}

			var viewer = EmbedPDF.init( init );
			if ( viewer && viewer.registry ) {
				surfaceToolbarButtons( viewer, cfg );
				if ( fitting ) {
					autoFit( embed, viewer, cfg );
				}
			}
			embed.classList.remove( 'coywolf-cpv-loading' );
		} ).catch( function () {
			// Leave the placeholder (with its direct link) in place.
			embed.classList.remove( 'coywolf-cpv-loading' );
			embed.removeAttribute( 'data-cpv-loaded' );
		} );
	}

	function setup( embed ) {
		var cfg;
		try {
			cfg = JSON.parse( embed.getAttribute( 'data-cpv' ) || '{}' );
		} catch ( e ) {
			return;
		}
		if ( ! cfg.src || ! global.lib ) {
			return;
		}

		// Match the placeholder to the future viewer height (page + chrome)
		// before anything loads, so booting the viewer doesn't shift layout.
		if ( 'auto' === cfg.fit ) {
			watchPending( embed, cfg );
		}

		if ( cfg.click ) {
			embed.classList.add( 'coywolf-cpv-clickable' );
			var button = embed.querySelector( '.coywolf-cpv-load' );
			if ( button ) {
				button.addEventListener( 'click', function () {
					boot( embed, cfg );
				} );
			}
			return;
		}

		if ( cfg.lazy && 'IntersectionObserver' in window ) {
			var observer = new IntersectionObserver(
				function ( entries ) {
					entries.forEach( function ( entry ) {
						if ( entry.isIntersecting ) {
							observer.disconnect();
							boot( embed, cfg );
						}
					} );
				},
				{ rootMargin: '600px 0px' }
			);
			observer.observe( embed );
			return;
		}

		boot( embed, cfg );
	}

	function init() {
		var embeds = document.querySelectorAll( '.coywolf-cpv-embed[data-cpv]' );
		for ( var i = 0; i < embeds.length; i++ ) {
			setup( embeds[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
