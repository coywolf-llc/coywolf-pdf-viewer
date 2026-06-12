<?php
/**
 * The coywolf/pdf block: registration, server-side rendering, and assets.
 *
 * Performance design: the EmbedPDF library (and its WebAssembly engine) is
 * never enqueued. The small view script loads only on pages that render the
 * block (block.json viewScript semantics via render-time enqueue), and it
 * dynamic-imports the vendored EmbedPDF module only when an embed scrolls
 * near the viewport (or on click, in click-to-load mode).
 *
 * The vendored viewer is configured fully offline: the PDFium engine loads
 * from this plugin, and the library's CDN font/stamp fallbacks are disabled,
 * so embeds make no third-party requests.
 *
 * @package CoywolfPDFViewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Block registration + rendering.
 */
class Coywolf_CPV_Block {

	/**
	 * Vendored EmbedPDF snippet version (matches vendor/embedpdf/*).
	 */
	const EMBEDPDF_VERSION = '2.14.4';

	/**
	 * PDF store.
	 *
	 * @var Coywolf_CPV_Store
	 */
	private $store;

	/**
	 * Settings.
	 *
	 * @var Coywolf_CPV_Settings
	 */
	private $settings;

	/**
	 * Whether the per-page view config has been printed.
	 *
	 * @var bool
	 */
	private $config_added = false;

	/**
	 * Constructor.
	 *
	 * @param Coywolf_CPV_Store    $store    PDF store.
	 * @param Coywolf_CPV_Settings $settings Settings.
	 */
	public function __construct( Coywolf_CPV_Store $store, Coywolf_CPV_Settings $settings ) {
		$this->store    = $store;
		$this->settings = $settings;

		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register assets + the block.
	 */
	public function register() {
		wp_register_script(
			'coywolf-cpv-block',
			COYWOLF_CPV_URL . 'js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data' ),
			Coywolf_PDF_Viewer::VERSION,
			true
		);
		wp_set_script_translations( 'coywolf-cpv-block', 'coywolf-pdf-viewer' );

		wp_register_style( 'coywolf-cpv-block-editor', COYWOLF_CPV_URL . 'css/block-editor.css', array(), Coywolf_PDF_Viewer::VERSION );
		wp_register_style( 'coywolf-cpv-view', COYWOLF_CPV_URL . 'css/view.css', array(), Coywolf_PDF_Viewer::VERSION );
		wp_register_script(
			'coywolf-cpv-view',
			COYWOLF_CPV_URL . 'js/view.js',
			array(),
			Coywolf_PDF_Viewer::VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			'coywolf-cpv-block',
			'window.coywolfCPVBlock = ' . wp_json_encode(
				array(
					'defaults' => $this->settings->all(),
				)
			) . ';',
			'before'
		);

		register_block_type(
			COYWOLF_CPV_PATH . 'block.json',
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Rendering
	 * --------------------------------------------------------------------- */

	/**
	 * Render the block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public function render( $attributes ) {
		$pdf_id = isset( $attributes['pdfId'] ) ? absint( $attributes['pdfId'] ) : 0;
		$pdf    = $pdf_id ? $this->store->get( $pdf_id ) : null;
		if ( ! $pdf ) {
			return '';
		}
		$src = $this->store->src( $pdf );
		if ( '' === $src ) {
			return '';
		}

		$this->enqueue_view_assets();

		$height_mode   = $this->resolve_height_mode( $attributes, $pdf );
		$height        = $this->resolve_scalar( $attributes, $pdf, 'height' );
		$theme         = $this->resolve_scalar( $attributes, $pdf, 'theme' );
		$zoom          = $this->resolve_scalar( $attributes, $pdf, 'zoom' );
		$click_to_load = $this->resolve_bool( $attributes, $pdf, 'clickToLoad', 'click_to_load' );
		$poster        = $click_to_load ? $this->store->poster( $pdf ) : array(
			'url'   => '',
			'ratio' => 0,
		);
		$ratio         = ! empty( $pdf['options']['ratio'] ) ? (float) $pdf['options']['ratio'] : 0;

		// A poster rendered from the first page carries the page shape — use
		// it when the PDF itself hasn't been measured.
		if ( $ratio <= 0 && $poster['ratio'] > 0 ) {
			$ratio = $poster['ratio'];
		}

		$config = array(
			'src'      => esc_url_raw( $src ),
			'name'     => $pdf['name'],
			'filename' => $this->store->filename( $pdf ),
			'fit'      => $height_mode,
			'ratio'    => $ratio,
			'theme'    => $theme,
			'accent'   => (string) $this->settings->get( 'accent_color' ),
			'zoom'     => $zoom,
			'lazy'     => (bool) $this->settings->get( 'lazy' ),
			'click'    => $click_to_load,
			'features' => array(
				'download'   => $this->resolve_bool( $attributes, $pdf, 'download', 'download' ),
				'print'      => $this->resolve_bool( $attributes, $pdf, 'print', 'print' ),
				'fullscreen' => $this->resolve_bool( $attributes, $pdf, 'fullscreen', 'fullscreen' ),
				'sidebar'    => $this->resolve_bool( $attributes, $pdf, 'sidebar', 'sidebar' ),
				'search'     => $this->resolve_bool( $attributes, $pdf, 'search', 'search' ),
				'zoom'       => $this->resolve_bool( $attributes, $pdf, 'zoomControls', 'zoom_controls' ),
			),
		);

		$show_caption = isset( $attributes['showCaption'] ) && is_bool( $attributes['showCaption'] )
			? $attributes['showCaption']
			: (bool) $this->settings->get( 'show_caption' );

		$wrapper = get_block_wrapper_attributes( array( 'class' => 'coywolf-cpv' ) );

		// Auto mode sizes the box to the page shape before any JS runs (the
		// viewer chrome estimate; corrected to the pixel once the document
		// loads), so the page never shows alongside its neighbor and the
		// layout doesn't shift. Fixed mode keeps the explicit height.
		if ( 'auto' === $height_mode ) {
			$css_ratio = $ratio > 0 ? $ratio : 1.2941; // US Letter estimate.
			$style     = 'aspect-ratio:1 / ' . $css_ratio . ';min-height:240px';
		} else {
			$style = 'height:' . (string) $height . 'px';
		}

		$html  = '<figure ' . $wrapper . '>';
		$html .= '<div class="coywolf-cpv-embed" data-cpv="' . esc_attr( (string) wp_json_encode( $config ) ) . '" style="' . esc_attr( $style ) . '">';
		$html .= '<div class="coywolf-cpv-placeholder' . ( '' !== $poster['url'] ? ' coywolf-cpv-has-poster' : '' ) . '">';
		if ( '' !== $poster['url'] ) {
			$html .= '<img class="coywolf-cpv-poster" src="' . esc_url( $poster['url'] ) . '" alt="" loading="lazy" decoding="async" />';
		}
		$html .= '<span class="coywolf-cpv-placeholder-icon" aria-hidden="true"></span>';
		$html .= '<span class="coywolf-cpv-placeholder-name">' . esc_html( $pdf['name'] ) . '</span>';
		if ( $click_to_load ) {
			$html .= '<button type="button" class="coywolf-cpv-load">' . esc_html__( 'Load PDF', 'coywolf-pdf-viewer' ) . '</button>';
		}
		$html .= '<a class="coywolf-cpv-fallback" href="' . esc_url( $src ) . '">' . esc_html__( 'Open the PDF', 'coywolf-pdf-viewer' ) . '</a>';
		$html .= '</div>';
		$html .= '</div>';
		if ( $show_caption && '' !== trim( $pdf['caption'] ) ) {
			$html .= '<figcaption class="coywolf-cpv-caption">' . wp_kses_post( $pdf['caption'] ) . '</figcaption>';
		}
		$html .= '</figure>';

		if ( $this->settings->get( 'schema_enabled' ) ) {
			$html .= $this->schema_tag( $pdf, $src );
		}

		return $html;
	}

	/**
	 * Enqueue the view assets and print the shared config once per page.
	 */
	private function enqueue_view_assets() {
		wp_enqueue_style( 'coywolf-cpv-view' );
		wp_enqueue_script( 'coywolf-cpv-view' );

		if ( $this->config_added ) {
			return;
		}
		$this->config_added = true;

		wp_add_inline_script(
			'coywolf-cpv-view',
			'window.coywolfCPVView = ' . wp_json_encode(
				array(
					// Absolute URLs: the engine worker resolves wasmUrl against
					// a blob: base, where relative paths fail.
					'lib'    => COYWOLF_CPV_URL . 'vendor/embedpdf/embedpdf.js?ver=' . self::EMBEDPDF_VERSION,
					'wasm'   => COYWOLF_CPV_URL . 'vendor/embedpdf/pdfium.wasm',
					'locale' => $this->viewer_locale(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Map the site locale to an EmbedPDF UI locale.
	 *
	 * @return string
	 */
	private function viewer_locale() {
		$locale = get_locale();
		$map    = array(
			'zh_CN' => 'zh-CN',
			'zh_TW' => 'zh-TW',
			'pt_BR' => 'pt-BR',
		);
		if ( isset( $map[ $locale ] ) ) {
			return $map[ $locale ];
		}
		$short = substr( $locale, 0, 2 );
		return in_array( $short, array( 'en', 'nl', 'de', 'fr', 'es', 'ja', 'sv' ), true ) ? $short : 'en';
	}

	/**
	 * Schema.org DigitalDocument JSON-LD for the embed.
	 *
	 * @param array  $pdf Hydrated PDF.
	 * @param string $src PDF URL.
	 * @return string
	 */
	private function schema_tag( $pdf, $src ) {
		$schema  = array(
			'@context'       => 'https://schema.org',
			'@type'          => 'DigitalDocument',
			'name'           => $pdf['name'],
			'url'            => $src,
			'encodingFormat' => 'application/pdf',
		);
		$caption = trim( wp_strip_all_tags( $pdf['caption'] ) );
		if ( '' !== $caption ) {
			$schema['description'] = $caption;
		}
		return wp_get_inline_script_tag(
			(string) wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ),
			array( 'type' => 'application/ld+json' )
		);
	}

	/* --------------------------------------------------------------------- *
	 * Inheritance
	 * --------------------------------------------------------------------- */

	/**
	 * Resolve the height mode: block attribute → per-PDF option → setting.
	 *
	 * @param array $attributes Block attributes.
	 * @param array $pdf        Hydrated PDF.
	 * @return string auto|fixed
	 */
	private function resolve_height_mode( $attributes, $pdf ) {
		if ( isset( $attributes['heightMode'] ) && in_array( $attributes['heightMode'], array( 'auto', 'fixed' ), true ) ) {
			return $attributes['heightMode'];
		}
		if ( isset( $pdf['options']['height_mode'] ) && in_array( $pdf['options']['height_mode'], array( 'auto', 'fixed' ), true ) ) {
			return $pdf['options']['height_mode'];
		}
		return 'fixed' === $this->settings->get( 'height_mode' ) ? 'fixed' : 'auto';
	}

	/**
	 * Resolve a boolean option: block attribute → per-PDF option → setting.
	 *
	 * @param array  $attributes  Block attributes.
	 * @param array  $pdf         Hydrated PDF.
	 * @param string $attr        Block attribute key.
	 * @param string $setting_key Per-PDF/settings key.
	 * @return bool
	 */
	private function resolve_bool( $attributes, $pdf, $attr, $setting_key ) {
		if ( isset( $attributes[ $attr ] ) && is_bool( $attributes[ $attr ] ) ) {
			return $attributes[ $attr ];
		}
		if ( isset( $pdf['options'][ $setting_key ] ) && in_array( $pdf['options'][ $setting_key ], array( 'on', 'off' ), true ) ) {
			return 'on' === $pdf['options'][ $setting_key ];
		}
		return (bool) $this->settings->get( $setting_key );
	}

	/**
	 * Resolve height/theme/zoom: block attribute → per-PDF option → setting.
	 *
	 * @param array  $attributes Block attributes.
	 * @param array  $pdf        Hydrated PDF.
	 * @param string $key        height|theme|zoom.
	 * @return int|string
	 */
	private function resolve_scalar( $attributes, $pdf, $key ) {
		if ( 'height' === $key ) {
			if ( ! empty( $attributes['height'] ) ) {
				return min( 3000, max( 200, absint( $attributes['height'] ) ) );
			}
			if ( ! empty( $pdf['options']['height'] ) ) {
				return min( 3000, max( 200, (int) $pdf['options']['height'] ) );
			}
			return (int) $this->settings->get( 'height' );
		}
		if ( ! empty( $attributes[ $key ] ) && is_string( $attributes[ $key ] ) ) {
			return $attributes[ $key ];
		}
		if ( ! empty( $pdf['options'][ $key ] ) ) {
			return $pdf['options'][ $key ];
		}
		return (string) $this->settings->get( $key );
	}
}
