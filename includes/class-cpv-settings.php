<?php
/**
 * Settings screen + stored configuration for Coywolf PDF Viewer.
 *
 * The Settings values are the defaults every embed starts from; each PDF can
 * override them, and each block can override the PDF. Stored as one option
 * array.
 *
 * @package CoywolfPDFViewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings.
 */
class Coywolf_CPV_Settings {

	/**
	 * Settings group / option_page name.
	 */
	const GROUP = 'coywolf_cpv_settings_group';

	/**
	 * Settings screen slug (also the submenu slug).
	 */
	const PAGE = 'coywolf-pdf-viewer-settings';

	/**
	 * Option holding the settings array.
	 */
	const OPTION = 'coywolf_cpv_settings';

	/**
	 * Per-request cache of the merged settings (stored values over defaults).
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scroll_margin_style' ) );

		// Drop the per-request settings cache when the option changes.
		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_cache' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'flush_cache' ) );
	}

	/**
	 * Reset the per-request settings cache.
	 */
	public function flush_cache() {
		$this->cache = null;
	}

	/* --------------------------------------------------------------------- *
	 * Defaults + accessors
	 * --------------------------------------------------------------------- */

	/**
	 * Default settings. The single source of truth for PDF and block
	 * inheritance.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Viewer.
			'height_mode'        => 'auto',
			'height'             => 800,
			'theme'              => 'system',
			'accent_color'       => '',
			'zoom'               => 'fit-page',
			// Toolbar features.
			'download'           => true,
			'print'              => true,
			'fullscreen'         => true,
			'sidebar'            => true,
			'search'             => true,
			'zoom_controls'      => true,
			// Performance.
			'lazy'               => true,
			'click_to_load'      => false,
			// Click-to-load poster overlay.
			'overlay_color'      => '#ffffff',
			'overlay_opacity'    => 75,
			// Display.
			'show_caption'       => true,
			'schema_enabled'     => true,
			// Anchor offset for sites with a fixed/sticky header: > 0 emits
			// [id] { scroll-margin-top: <value><unit> } on the front end.
			'scroll_margin_top'  => 0,
			'scroll_margin_unit' => 'rem',
		);
	}

	/* --------------------------------------------------------------------- *
	 * Front-end scroll margin
	 * --------------------------------------------------------------------- */

	/**
	 * When a scroll margin is configured, offset every anchor target so a
	 * fixed/sticky header doesn't cover it. Printed via an inline style on a
	 * src-less handle (no raw style tags).
	 */
	public function enqueue_scroll_margin_style() {
		$css = $this->scroll_margin_css();
		if ( '' === $css ) {
			return;
		}
		wp_register_style( 'coywolf-cpv-scroll-margin', false, array(), Coywolf_PDF_Viewer::VERSION );
		wp_enqueue_style( 'coywolf-cpv-scroll-margin' );
		wp_add_inline_style( 'coywolf-cpv-scroll-margin', $css );
	}

	/**
	 * The scroll-margin rule, or '' when disabled (0).
	 *
	 * @return string
	 */
	public function scroll_margin_css() {
		$value = (float) $this->get( 'scroll_margin_top' );
		if ( $value <= 0 ) {
			return '';
		}
		$unit = 'px' === $this->get( 'scroll_margin_unit' ) ? 'px' : 'rem';

		// 4 -> "4", 4.50 -> "4.5" (the value is sanitized to a bounded float,
		// so the rule is built only from numbers and a whitelisted unit).
		$number = rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );
		return '[id]{scroll-margin-top:' . $number . $unit . '}';
	}

	/**
	 * All settings, merged over defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->cache;
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/* --------------------------------------------------------------------- *
	 * Registration + sanitization
	 * --------------------------------------------------------------------- */

	/**
	 * Register the setting, sections, and fields.
	 */
	public function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);

		add_settings_section( 'coywolf_cpv_viewer', __( 'Viewer defaults', 'coywolf-pdf-viewer' ), array( $this, 'render_viewer_intro' ), self::PAGE );
		add_settings_field( 'height', __( 'Height', 'coywolf-pdf-viewer' ), array( $this, 'render_height_field' ), self::PAGE, 'coywolf_cpv_viewer' );
		add_settings_field( 'theme', __( 'Color scheme', 'coywolf-pdf-viewer' ), array( $this, 'render_theme_field' ), self::PAGE, 'coywolf_cpv_viewer' );
		add_settings_field( 'accent_color', __( 'Accent color', 'coywolf-pdf-viewer' ), array( $this, 'render_accent_field' ), self::PAGE, 'coywolf_cpv_viewer' );
		add_settings_field( 'zoom', __( 'Default zoom', 'coywolf-pdf-viewer' ), array( $this, 'render_zoom_field' ), self::PAGE, 'coywolf_cpv_viewer' );

		add_settings_section( 'coywolf_cpv_features', __( 'Toolbar features', 'coywolf-pdf-viewer' ), '__return_false', self::PAGE );
		add_settings_field( 'features', __( 'Enabled features', 'coywolf-pdf-viewer' ), array( $this, 'render_features_field' ), self::PAGE, 'coywolf_cpv_features' );

		add_settings_section( 'coywolf_cpv_performance', __( 'Performance', 'coywolf-pdf-viewer' ), '__return_false', self::PAGE );
		add_settings_field( 'performance', __( 'Loading', 'coywolf-pdf-viewer' ), array( $this, 'render_performance_field' ), self::PAGE, 'coywolf_cpv_performance' );
		add_settings_field( 'overlay', __( 'Click-to-load overlay', 'coywolf-pdf-viewer' ), array( $this, 'render_overlay_field' ), self::PAGE, 'coywolf_cpv_performance' );

		add_settings_section( 'coywolf_cpv_display', __( 'Display', 'coywolf-pdf-viewer' ), '__return_false', self::PAGE );
		add_settings_field( 'display', __( 'Captions & SEO', 'coywolf-pdf-viewer' ), array( $this, 'render_display_field' ), self::PAGE, 'coywolf_cpv_display' );
		add_settings_field( 'scroll_margin', __( 'Scroll margin top', 'coywolf-pdf-viewer' ), array( $this, 'render_scroll_margin_field' ), self::PAGE, 'coywolf_cpv_display' );
	}

	/**
	 * Sanitize the settings array.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean    = array();

		$height_mode          = isset( $input['height_mode'] ) ? sanitize_key( $input['height_mode'] ) : '';
		$clean['height_mode'] = in_array( $height_mode, array( 'auto', 'fixed' ), true ) ? $height_mode : $defaults['height_mode'];

		$clean['height'] = isset( $input['height'] ) ? min( 3000, max( 200, absint( $input['height'] ) ) ) : $defaults['height'];

		$theme          = isset( $input['theme'] ) ? sanitize_key( $input['theme'] ) : '';
		$clean['theme'] = in_array( $theme, array( 'light', 'dark', 'system' ), true ) ? $theme : $defaults['theme'];

		// Cleared in the picker = '' = use the viewer default.
		$clean['accent_color'] = $this->sanitize_hex( isset( $input['accent_color'] ) ? $input['accent_color'] : '' );

		$zoom          = isset( $input['zoom'] ) ? sanitize_key( $input['zoom'] ) : '';
		$clean['zoom'] = in_array( $zoom, array( 'fit-page', 'fit-width', 'automatic' ), true ) ? $zoom : $defaults['zoom'];

		foreach ( array( 'download', 'print', 'fullscreen', 'sidebar', 'search', 'zoom_controls', 'lazy', 'click_to_load', 'show_caption', 'schema_enabled' ) as $key ) {
			$clean[ $key ] = ! empty( $input[ $key ] );
		}

		$overlay                = isset( $input['overlay_color'] ) ? sanitize_hex_color( (string) $input['overlay_color'] ) : '';
		$clean['overlay_color'] = $overlay ? $overlay : $defaults['overlay_color'];

		$clean['overlay_opacity'] = isset( $input['overlay_opacity'] ) && is_numeric( $input['overlay_opacity'] )
			? min( 95, max( 0, (int) $input['overlay_opacity'] ) )
			: $defaults['overlay_opacity'];

		$clean['scroll_margin_top'] = isset( $input['scroll_margin_top'] ) && is_numeric( $input['scroll_margin_top'] )
			? min( 1000, max( 0, round( (float) $input['scroll_margin_top'], 3 ) ) )
			: $defaults['scroll_margin_top'];

		$unit                        = isset( $input['scroll_margin_unit'] ) ? sanitize_key( $input['scroll_margin_unit'] ) : '';
		$clean['scroll_margin_unit'] = in_array( $unit, array( 'rem', 'px' ), true ) ? $unit : $defaults['scroll_margin_unit'];

		return $clean;
	}

	/**
	 * A hex color or ''.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function sanitize_hex( $value ) {
		$hex = sanitize_hex_color( (string) $value );
		return $hex ? $hex : '';
	}

	/* --------------------------------------------------------------------- *
	 * Field renderers
	 * --------------------------------------------------------------------- */

	/**
	 * Viewer section intro.
	 */
	public function render_viewer_intro() {
		echo '<p>' . esc_html__( 'Defaults for every embedded viewer. Each PDF and each block can override them.', 'coywolf-pdf-viewer' ) . '</p>';
	}

	/**
	 * Height field: fit-one-page (auto) or fixed pixels.
	 */
	public function render_height_field() {
		$mode  = (string) $this->get( 'height_mode' );
		$value = (int) $this->get( 'height' );
		echo '<select name="' . esc_attr( self::OPTION ) . '[height_mode]">';
		printf( '<option value="auto"%s>%s</option>', selected( $mode, 'auto', false ), esc_html__( 'Fit one page (recommended)', 'coywolf-pdf-viewer' ) );
		printf( '<option value="fixed"%s>%s</option>', selected( $mode, 'fixed', false ), esc_html__( 'Fixed height', 'coywolf-pdf-viewer' ) );
		echo '</select> ';
		printf(
			'<input type="number" name="%s[height]" value="%d" min="200" max="3000" step="10" class="small-text" /> %s',
			esc_attr( self::OPTION ),
			esc_attr( $value ),
			esc_html__( 'pixels (used with fixed height)', 'coywolf-pdf-viewer' )
		);
		echo '<p class="description">' . esc_html__( 'Fit one page sizes each embed to the PDF’s page, so exactly one page shows at a time.', 'coywolf-pdf-viewer' ) . '</p>';
	}

	/**
	 * Theme field.
	 */
	public function render_theme_field() {
		$value   = (string) $this->get( 'theme' );
		$options = array(
			'system' => __( 'Match the visitor’s system preference', 'coywolf-pdf-viewer' ),
			'light'  => __( 'Light', 'coywolf-pdf-viewer' ),
			'dark'   => __( 'Dark', 'coywolf-pdf-viewer' ),
		);
		echo '<select name="' . esc_attr( self::OPTION ) . '[theme]">';
		foreach ( $options as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $value, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Accent color field (a hidden value input + a jscolorpicker mount).
	 */
	public function render_accent_field() {
		$this->color_input( 'accent_color' );
		echo '<p class="description">' . esc_html__( 'Tints buttons, links, and highlights inside the viewer. Clear the color to use the viewer default.', 'coywolf-pdf-viewer' ) . '</p>';
	}

	/**
	 * Render a color field (a hidden value input + a jscolorpicker mount
	 * point — the same picker the Coywolf Video Manager settings use).
	 *
	 * @param string $key Setting key.
	 */
	private function color_input( $key ) {
		printf(
			'<span class="coywolf-cpv-color-field" data-key="%2$s"><input type="hidden" class="coywolf-cpv-color-value" name="%1$s[%2$s]" value="%3$s" /><span class="coywolf-cpv-color-mount"></span></span>',
			esc_attr( self::OPTION ),
			esc_attr( $key ),
			esc_attr( (string) $this->get( $key ) )
		);
	}

	/**
	 * Zoom field.
	 */
	public function render_zoom_field() {
		$value   = (string) $this->get( 'zoom' );
		$options = array(
			'fit-page'  => __( 'Fit page', 'coywolf-pdf-viewer' ),
			'fit-width' => __( 'Fit width', 'coywolf-pdf-viewer' ),
			'automatic' => __( 'Automatic', 'coywolf-pdf-viewer' ),
		);
		echo '<select name="' . esc_attr( self::OPTION ) . '[zoom]">';
		foreach ( $options as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $value, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Feature checkboxes.
	 */
	public function render_features_field() {
		$features = array(
			'download'      => __( 'Download button', 'coywolf-pdf-viewer' ),
			'print'         => __( 'Print button', 'coywolf-pdf-viewer' ),
			'fullscreen'    => __( 'Full screen button', 'coywolf-pdf-viewer' ),
			'sidebar'       => __( 'Sidebar (page thumbnails & outline)', 'coywolf-pdf-viewer' ),
			'search'        => __( 'Search panel', 'coywolf-pdf-viewer' ),
			'zoom_controls' => __( 'Zoom controls', 'coywolf-pdf-viewer' ),
		);
		echo '<fieldset>';
		foreach ( $features as $key => $label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[%2$s]" value="1"%3$s /> %4$s</label><br />',
				esc_attr( self::OPTION ),
				esc_attr( $key ),
				checked( (bool) $this->get( $key ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Performance checkboxes.
	 */
	public function render_performance_field() {
		echo '<fieldset>';
		printf(
			'<label><input type="checkbox" name="%1$s[lazy]" value="1"%2$s /> %3$s</label><p class="description">%4$s</p><br />',
			esc_attr( self::OPTION ),
			checked( (bool) $this->get( 'lazy' ), true, false ),
			esc_html__( 'Lazy-load viewers', 'coywolf-pdf-viewer' ),
			esc_html__( 'The viewer script and PDF only load when the embed scrolls near the screen.', 'coywolf-pdf-viewer' )
		);
		printf(
			'<label><input type="checkbox" name="%1$s[click_to_load]" value="1"%2$s /> %3$s</label><p class="description">%4$s</p>',
			esc_attr( self::OPTION ),
			checked( (bool) $this->get( 'click_to_load' ), true, false ),
			esc_html__( 'Click to load', 'coywolf-pdf-viewer' ),
			esc_html__( 'Show a lightweight preview card and load the viewer only when the visitor asks for it. The fastest option for pages with many PDFs.', 'coywolf-pdf-viewer' )
		);
		echo '</fieldset>';
	}

	/**
	 * Click-to-load overlay color + opacity.
	 */
	public function render_overlay_field() {
		$this->color_input( 'overlay_color' );
		printf(
			' %1$s <input type="number" name="%2$s[overlay_opacity]" value="%3$d" min="0" max="95" step="5" class="small-text" />%%<p class="description">%4$s</p>',
			esc_html__( 'at', 'coywolf-pdf-viewer' ),
			esc_attr( self::OPTION ),
			(int) $this->get( 'overlay_opacity' ),
			esc_html__( 'The tint laid over the poster image behind the Load PDF button. Each PDF can override it.', 'coywolf-pdf-viewer' )
		);
	}

	/**
	 * Display checkboxes.
	 */
	public function render_display_field() {
		echo '<fieldset>';
		printf(
			'<label><input type="checkbox" name="%1$s[show_caption]" value="1"%2$s /> %3$s</label><br />',
			esc_attr( self::OPTION ),
			checked( (bool) $this->get( 'show_caption' ), true, false ),
			esc_html__( 'Show captions under embeds', 'coywolf-pdf-viewer' )
		);
		printf(
			'<label><input type="checkbox" name="%1$s[schema_enabled]" value="1"%2$s /> %3$s</label><p class="description">%4$s</p>',
			esc_attr( self::OPTION ),
			checked( (bool) $this->get( 'schema_enabled' ), true, false ),
			esc_html__( 'Add structured data (schema.org) for embedded PDFs', 'coywolf-pdf-viewer' ),
			esc_html__( 'Helps search engines understand the document attached to the page.', 'coywolf-pdf-viewer' )
		);
		echo '</fieldset>';
	}

	/**
	 * Scroll margin top: value + unit.
	 */
	public function render_scroll_margin_field() {
		$value = (float) $this->get( 'scroll_margin_top' );
		$unit  = 'px' === $this->get( 'scroll_margin_unit' ) ? 'px' : 'rem';
		printf(
			'<input type="number" name="%1$s[scroll_margin_top]" value="%2$s" min="0" max="1000" step="0.25" class="small-text" /> ',
			esc_attr( self::OPTION ),
			esc_attr( $value > 0 ? rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' ) : '0' )
		);
		echo '<select name="' . esc_attr( self::OPTION ) . '[scroll_margin_unit]">';
		printf( '<option value="rem"%s>rem</option>', selected( $unit, 'rem', false ) );
		printf( '<option value="px"%s>px</option>', selected( $unit, 'px', false ) );
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'For sites with a fixed or sticky header: offsets anchor targets so they aren’t hidden behind it when the page scrolls to a link. 0 turns it off; e.g. 4rem outputs [id] { scroll-margin-top: 4rem; } site-wide.', 'coywolf-pdf-viewer' ) . '</p>';
	}

	/* --------------------------------------------------------------------- *
	 * Page
	 * --------------------------------------------------------------------- */

	/**
	 * Render the Settings screen.
	 */
	public function render_page() {
		if ( ! current_user_can( Coywolf_PDF_Viewer::CAPABILITY ) ) {
			return;
		}
		echo '<div class="wrap coywolf-cpv-settings"><h1>' . esc_html__( 'Coywolf PDF Viewer Settings', 'coywolf-pdf-viewer' ) . '</h1>';
		echo '<form action="options.php" method="post">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form></div>';
	}
}
