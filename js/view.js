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
				zoom: { defaultZoomLevel: zoomLevel( mod, cfg.zoom ) },
				i18n: { defaultLocale: global.locale || 'en', fallbackLocale: 'en' },
				disabledCategories: disabledCategories( cfg ),
				'export': { defaultFileName: cfg.filename || 'document.pdf' }
			};
			if ( cfg.features && ! cfg.features.print ) {
				init.permissions = { overrides: { print: false } };
			}

			EmbedPDF.init( init );
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
