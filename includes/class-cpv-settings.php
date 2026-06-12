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
			'height'         => 800,
			'theme'          => 'system',
			'accent_color'   => '',
			'zoom'           => 'fit-page',
			// Toolbar features.
			'download'       => true,
			'print'          => true,
			'fullscreen'     => true,
			'sidebar'        => true,
			'search'         => true,
			'zoom_controls'  => true,
			// Performance.
			'lazy'           => true,
			'click_to_load'  => false,
			// Display.
			'show_caption'   => true,
			'schema_enabled' => true,
		);
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

		add_settings_section( 'coywolf_cpv_display', __( 'Display', 'coywolf-pdf-viewer' ), '__return_false', self::PAGE );
		add_settings_field( 'display', __( 'Captions & SEO', 'coywolf-pdf-viewer' ), array( $this, 'render_display_field' ), self::PAGE, 'coywolf_cpv_display' );
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

		$clean['height'] = isset( $input['height'] ) ? min( 3000, max( 200, absint( $input['height'] ) ) ) : $defaults['height'];

		$theme          = isset( $input['theme'] ) ? sanitize_key( $input['theme'] ) : '';
		$clean['theme'] = in_array( $theme, array( 'light', 'dark', 'system' ), true ) ? $theme : $defaults['theme'];

		// The "use viewer default" checkbox wins over the color input (a
		// color input cannot submit an empty value).
		$clean['accent_color'] = empty( $input['accent_default'] )
			? $this->sanitize_hex( isset( $input['accent_color'] ) ? $input['accent_color'] : '' )
			: '';

		$zoom          = isset( $input['zoom'] ) ? sanitize_key( $input['zoom'] ) : '';
		$clean['zoom'] = in_array( $zoom, array( 'fit-page', 'fit-width', 'automatic' ), true ) ? $zoom : $defaults['zoom'];

		foreach ( array( 'download', 'print', 'fullscreen', 'sidebar', 'search', 'zoom_controls', 'lazy', 'click_to_load', 'show_caption', 'schema_enabled' ) as $key ) {
			$clean[ $key ] = ! empty( $input[ $key ] );
		}

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
	 * Height field.
	 */
	public function render_height_field() {
		$value = (int) $this->get( 'height' );
		printf(
			'<input type="number" name="%s[height]" value="%d" min="200" max="3000" step="10" class="small-text" /> %s',
			esc_attr( self::OPTION ),
			esc_attr( $value ),
			esc_html__( 'pixels', 'coywolf-pdf-viewer' )
		);
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
	 * Accent color field.
	 */
	public function render_accent_field() {
		$value = (string) $this->get( 'accent_color' );
		printf(
			'<input type="color" id="coywolf-cpv-accent" name="%1$s[accent_color]" value="%2$s" /> <label><input type="checkbox" name="%1$s[accent_default]" value="1"%3$s /> %4$s</label><p class="description">%5$s</p>',
			esc_attr( self::OPTION ),
			esc_attr( '' !== $value ? $value : '#2563eb' ),
			checked( '' === $value, true, false ),
			esc_html__( 'Use the viewer default', 'coywolf-pdf-viewer' ),
			esc_html__( 'Tints buttons, links, and highlights inside the viewer.', 'coywolf-pdf-viewer' )
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
