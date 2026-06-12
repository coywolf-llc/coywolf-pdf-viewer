<?php
/**
 * Admin screens: All PDFs, Add/Edit PDF, Settings, Documentation.
 *
 * @package CoywolfPDFViewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menu + screens.
 */
class Coywolf_CPV_Admin {

	/**
	 * Top-level (All PDFs) screen slug.
	 */
	const PAGE = 'coywolf-pdf-viewer';

	/**
	 * Add/Edit PDF screen slug.
	 */
	const PAGE_ADD = 'coywolf-pdf-viewer-add';

	/**
	 * Documentation screen slug.
	 */
	const PAGE_DOCS = 'coywolf-pdf-viewer-docs';

	/**
	 * PDF store.
	 *
	 * @var Coywolf_CPV_Store
	 */
	private $store;

	/**
	 * Usage index.
	 *
	 * @var Coywolf_CPV_Index
	 */
	private $index;

	/**
	 * Settings.
	 *
	 * @var Coywolf_CPV_Settings
	 */
	private $settings;

	/**
	 * Page hook suffixes, keyed by screen.
	 *
	 * @var array
	 */
	private $hooks = array();

	/**
	 * Constructor.
	 *
	 * @param Coywolf_CPV_Store    $store    PDF store.
	 * @param Coywolf_CPV_Index    $index    Usage index.
	 * @param Coywolf_CPV_Settings $settings Settings.
	 */
	public function __construct( Coywolf_CPV_Store $store, Coywolf_CPV_Index $index, Coywolf_CPV_Settings $settings ) {
		$this->store    = $store;
		$this->index    = $index;
		$this->settings = $settings;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_coywolf_cpv_save_pdf', array( $this, 'handle_save_pdf' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/**
	 * Register the menu and screens.
	 */
	public function register_menu() {
		$cap = Coywolf_PDF_Viewer::CAPABILITY;

		$this->hooks['list'] = add_menu_page(
			__( 'Coywolf PDF Viewer', 'coywolf-pdf-viewer' ),
			__( 'PDFs', 'coywolf-pdf-viewer' ),
			$cap,
			self::PAGE,
			array( $this, 'render_list_page' ),
			'dashicons-pdf',
			26
		);

		add_submenu_page(
			self::PAGE,
			__( 'All PDFs', 'coywolf-pdf-viewer' ),
			__( 'All PDFs', 'coywolf-pdf-viewer' ),
			$cap,
			self::PAGE,
			array( $this, 'render_list_page' )
		);

		$this->hooks['add'] = add_submenu_page(
			self::PAGE,
			__( 'Add PDF', 'coywolf-pdf-viewer' ),
			__( 'Add PDF', 'coywolf-pdf-viewer' ),
			$cap,
			self::PAGE_ADD,
			array( $this, 'render_add_page' )
		);

		$this->hooks['settings'] = add_submenu_page(
			self::PAGE,
			__( 'Settings', 'coywolf-pdf-viewer' ),
			__( 'Settings', 'coywolf-pdf-viewer' ),
			$cap,
			Coywolf_CPV_Settings::PAGE,
			array( $this->settings, 'render_page' )
		);

		$this->hooks['docs'] = add_submenu_page(
			self::PAGE,
			__( 'Documentation', 'coywolf-pdf-viewer' ),
			__( 'Documentation', 'coywolf-pdf-viewer' ),
			$cap,
			self::PAGE_DOCS,
			array( 'Coywolf_CPV_Docs', 'render_page' )
		);

		// Deletes are handled on load (before output) so redirects work.
		add_action( 'load-' . $this->hooks['list'], array( $this, 'handle_list_actions' ) );
	}

	/**
	 * Enqueue admin assets on plugin screens only.
	 *
	 * @param string $hook Current page hook.
	 */
	public function enqueue( $hook ) {
		if ( ! in_array( $hook, $this->hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'coywolf-cpv-admin', COYWOLF_CPV_URL . 'css/admin.css', array(), Coywolf_PDF_Viewer::VERSION );
		wp_enqueue_script( 'coywolf-cpv-admin', COYWOLF_CPV_URL . 'js/admin.js', array( 'jquery' ), Coywolf_PDF_Viewer::VERSION, true );
		wp_add_inline_script(
			'coywolf-cpv-admin',
			'window.coywolfCPVAdmin = ' . wp_json_encode(
				array(
					'i18n' => array(
						'choosePdf'     => __( 'Choose a PDF', 'coywolf-pdf-viewer' ),
						'usePdf'        => __( 'Use this PDF', 'coywolf-pdf-viewer' ),
						'choosePoster'  => __( 'Choose a poster image', 'coywolf-pdf-viewer' ),
						'usePoster'     => __( 'Use this image', 'coywolf-pdf-viewer' ),
						'confirmDelete' => __( 'Delete this PDF? Its block will also be removed from every post and page that embeds it.', 'coywolf-pdf-viewer' ),
					),
				)
			) . ';',
			'before'
		);

		// The Add/Edit screen picks PDFs from the Media Library.
		if ( $hook === $this->hooks['add'] ) {
			wp_enqueue_media();
		}
	}

	/* --------------------------------------------------------------------- *
	 * All PDFs
	 * --------------------------------------------------------------------- */

	/**
	 * Single + bulk delete, before the list renders.
	 */
	public function handle_list_actions() {
		if ( ! current_user_can( Coywolf_PDF_Viewer::CAPABILITY ) ) {
			return;
		}

		// Single delete (row action link).
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		$pdf_id = isset( $_REQUEST['pdf'] ) ? absint( wp_unslash( $_REQUEST['pdf'] ) ) : 0;
		if ( 'delete' === $action && $pdf_id > 0 && isset( $_REQUEST['_wpnonce'] ) ) {
			check_admin_referer( 'coywolf_cpv_delete_' . $pdf_id );
			$this->delete_pdf( $pdf_id );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'        => self::PAGE,
						'cpv_deleted' => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		// Bulk delete (list table form).
		$doaction = '';
		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
			$doaction = sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		} elseif ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$doaction = sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}
		if ( 'delete' === $doaction && ! empty( $_REQUEST['ids'] ) && is_array( $_REQUEST['ids'] ) ) {
			check_admin_referer( 'bulk-pdfs' );
			$ids     = array_map( 'absint', wp_unslash( $_REQUEST['ids'] ) );
			$deleted = 0;
			foreach ( $ids as $id ) {
				if ( $id > 0 && $this->delete_pdf( $id ) ) {
					++$deleted;
				}
			}
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'        => self::PAGE,
						'cpv_deleted' => $deleted,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
	}

	/**
	 * Delete one PDF: scrub its blocks from content, then drop the row.
	 *
	 * @param int $pdf_id PDF ID.
	 * @return bool
	 */
	private function delete_pdf( $pdf_id ) {
		if ( ! $this->store->get( $pdf_id ) ) {
			return false;
		}
		$this->index->remove_pdf( $pdf_id );
		return $this->store->delete( $pdf_id );
	}

	/**
	 * Render the All PDFs screen.
	 */
	public function render_list_page() {
		if ( ! current_user_can( Coywolf_PDF_Viewer::CAPABILITY ) ) {
			return;
		}

		$table = new Coywolf_CPV_List_Table( $this->store, $this->index );
		$table->prepare_items();

		echo '<div class="wrap coywolf-cpv-list">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'PDFs', 'coywolf-pdf-viewer' ) . '</h1>';
		echo ' <a href="' . esc_url( add_query_arg( 'page', self::PAGE_ADD, admin_url( 'admin.php' ) ) ) . '" class="page-title-action">' . esc_html__( 'Add PDF', 'coywolf-pdf-viewer' ) . '</a>';
		echo '<hr class="wp-header-end" />';
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		$table->search_box( __( 'Search PDFs', 'coywolf-pdf-viewer' ), 'coywolf-cpv' );
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	/* --------------------------------------------------------------------- *
	 * Add / Edit PDF
	 * --------------------------------------------------------------------- */

	/**
	 * Render the Add PDF / Edit PDF screen.
	 */
	public function render_add_page() {
		if ( ! current_user_can( Coywolf_PDF_Viewer::CAPABILITY ) ) {
			return;
		}

		$pdf_id  = isset( $_GET['pdf'] ) ? absint( wp_unslash( $_GET['pdf'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pdf     = $pdf_id ? $this->store->get( $pdf_id ) : null;
		$editing = null !== $pdf;
		$values  = $pdf ? $pdf : array(
			'name'          => '',
			'caption'       => '',
			'source'        => 'media',
			'attachment_id' => 0,
			'url'           => '',
			'options'       => Coywolf_CPV_Store::default_options(),
		);

		$attachment_name = '';
		if ( $values['attachment_id'] ) {
			$attachment_name = basename( (string) get_attached_file( $values['attachment_id'] ) );
		}

		echo '<div class="wrap coywolf-cpv-add">';
		echo '<h1>' . ( $editing ? esc_html__( 'Edit PDF', 'coywolf-pdf-viewer' ) : esc_html__( 'Add PDF', 'coywolf-pdf-viewer' ) ) . '</h1>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="coywolf_cpv_save_pdf" />';
		if ( $editing ) {
			echo '<input type="hidden" name="pdf_id" value="' . esc_attr( (string) $pdf['id'] ) . '" />';
		}
		wp_nonce_field( 'coywolf_cpv_save_pdf' );

		echo '<table class="form-table" role="presentation"><tbody>';

		// Name.
		echo '<tr><th scope="row"><label for="coywolf-cpv-name">' . esc_html__( 'Name', 'coywolf-pdf-viewer' ) . '</label></th><td>';
		echo '<input name="cpv_name" id="coywolf-cpv-name" type="text" class="regular-text" value="' . esc_attr( $values['name'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Shown in lists and used as the document title. Defaults to the filename.', 'coywolf-pdf-viewer' ) . '</p>';
		echo '</td></tr>';

		// Caption.
		echo '<tr><th scope="row"><label for="coywolf-cpv-caption">' . esc_html__( 'Caption', 'coywolf-pdf-viewer' ) . '</label></th><td>';
		echo '<textarea name="cpv_caption" id="coywolf-cpv-caption" class="large-text" rows="3">' . esc_textarea( $values['caption'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Optional. Shown under the embedded viewer.', 'coywolf-pdf-viewer' ) . '</p>';
		echo '</td></tr>';

		// Source.
		echo '<tr><th scope="row">' . esc_html__( 'PDF file', 'coywolf-pdf-viewer' ) . '</th><td>';
		echo '<fieldset class="coywolf-cpv-source">';
		printf(
			'<label><input type="radio" name="cpv_source" value="media"%s /> %s</label><br />',
			checked( 'media', $values['source'], false ),
			esc_html__( 'Upload / choose from the Media Library', 'coywolf-pdf-viewer' )
		);
		printf(
			'<label><input type="radio" name="cpv_source" value="url"%s /> %s</label>',
			checked( 'url', $values['source'], false ),
			esc_html__( 'Serve from a URL', 'coywolf-pdf-viewer' )
		);
		echo '</fieldset>';

		echo '<div class="coywolf-cpv-source-media"' . ( 'media' === $values['source'] ? '' : ' style="display:none"' ) . '>';
		echo '<p><button type="button" class="button coywolf-cpv-pick">' . esc_html__( 'Choose PDF', 'coywolf-pdf-viewer' ) . '</button> ';
		echo '<span class="coywolf-cpv-picked">' . esc_html( $attachment_name ) . '</span></p>';
		echo '<input type="hidden" name="cpv_attachment_id" class="coywolf-cpv-attachment-id" value="' . esc_attr( (string) $values['attachment_id'] ) . '" />';
		echo '</div>';

		echo '<div class="coywolf-cpv-source-url"' . ( 'url' === $values['source'] ? '' : ' style="display:none"' ) . '>';
		echo '<p><input type="url" name="cpv_url" class="regular-text code" placeholder="https://example.com/document.pdf" value="' . esc_attr( $values['url'] ) . '" /></p>';
		echo '<p class="description">' . esc_html__( 'The host must allow cross-origin requests (CORS) for the viewer to load it. Same-site URLs always work.', 'coywolf-pdf-viewer' ) . '</p>';
		echo '</div>';
		echo '</td></tr>';

		// Viewer options.
		echo '<tr><th scope="row">' . esc_html__( 'Viewer options', 'coywolf-pdf-viewer' ) . '</th><td>';
		echo '<p class="description">' . esc_html__( '“Default” inherits the value from Settings.', 'coywolf-pdf-viewer' ) . '</p>';
		echo '<table class="coywolf-cpv-options">';
		$toggles = array(
			'download'      => __( 'Download button', 'coywolf-pdf-viewer' ),
			'print'         => __( 'Print button', 'coywolf-pdf-viewer' ),
			'fullscreen'    => __( 'Full screen button', 'coywolf-pdf-viewer' ),
			'sidebar'       => __( 'Sidebar (thumbnails & outline)', 'coywolf-pdf-viewer' ),
			'search'        => __( 'Search panel', 'coywolf-pdf-viewer' ),
			'zoom_controls' => __( 'Zoom controls', 'coywolf-pdf-viewer' ),
			'click_to_load' => __( 'Click to load', 'coywolf-pdf-viewer' ),
		);
		foreach ( $toggles as $key => $label ) {
			$current = isset( $values['options'][ $key ] ) ? $values['options'][ $key ] : '';
			echo '<tr><td><label for="coywolf-cpv-opt-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></td><td>';
			echo '<select name="cpv_options[' . esc_attr( $key ) . ']" id="coywolf-cpv-opt-' . esc_attr( $key ) . '">';
			printf( '<option value=""%s>%s</option>', selected( $current, '', false ), esc_html( $this->default_label( $key ) ) );
			printf( '<option value="on"%s>%s</option>', selected( $current, 'on', false ), esc_html__( 'On', 'coywolf-pdf-viewer' ) );
			printf( '<option value="off"%s>%s</option>', selected( $current, 'off', false ), esc_html__( 'Off', 'coywolf-pdf-viewer' ) );
			echo '</select></td></tr>';
		}

		// Poster image (click-to-load card).
		$poster_id  = isset( $values['options']['poster_id'] ) ? (int) $values['options']['poster_id'] : 0;
		$poster_url = $poster_id ? wp_get_attachment_image_url( $poster_id, 'medium' ) : '';
		echo '<tr><td><label>' . esc_html__( 'Poster image', 'coywolf-pdf-viewer' ) . '</label></td><td>';
		echo '<div class="coywolf-cpv-poster-field">';
		echo '<button type="button" class="button coywolf-cpv-pick-poster">' . esc_html__( 'Choose image', 'coywolf-pdf-viewer' ) . '</button> ';
		echo '<button type="button" class="button coywolf-cpv-clear-poster"' . ( $poster_id ? '' : ' style="display:none"' ) . '>' . esc_html__( 'Remove', 'coywolf-pdf-viewer' ) . '</button>';
		echo '<input type="hidden" name="cpv_options[poster_id]" class="coywolf-cpv-poster-id" value="' . esc_attr( (string) $poster_id ) . '" />';
		echo '<div class="coywolf-cpv-poster-preview">' . ( $poster_url ? '<img src="' . esc_url( $poster_url ) . '" alt="" />' : '' ) . '</div>';
		echo '<p class="description">' . esc_html__( 'Shown behind the Load PDF button in click-to-load mode. Media Library PDFs fall back to their generated first-page preview automatically.', 'coywolf-pdf-viewer' ) . '</p>';
		$known_ratio = isset( $values['options']['ratio'] ) ? (float) $values['options']['ratio'] : 0;
		if ( $known_ratio > 0 ) {
			printf(
				'<p class="description"><strong>%s</strong> 1200 × %dpx</p>',
				esc_html__( 'Recommended size (matches the PDF page):', 'coywolf-pdf-viewer' ),
				(int) round( 1200 * $known_ratio )
			);
		} else {
			echo '<p class="description"><strong>' . esc_html__( 'Recommended width:', 'coywolf-pdf-viewer' ) . '</strong> 1200px</p>';
		}
		echo '</div>';
		echo '</td></tr>';

		// Click-to-load overlay (tint over the poster).
		$overlay_color   = isset( $values['options']['overlay_color'] ) ? (string) $values['options']['overlay_color'] : '';
		$overlay_opacity = isset( $values['options']['overlay_opacity'] ) ? $values['options']['overlay_opacity'] : '';
		echo '<tr><td><label for="coywolf-cpv-opt-overlay-color">' . esc_html__( 'Click-to-load overlay', 'coywolf-pdf-viewer' ) . '</label></td><td>';
		printf(
			'<input type="color" name="cpv_options[overlay_color]" id="coywolf-cpv-opt-overlay-color" value="%1$s" /> <label><input type="checkbox" name="cpv_options[overlay_default]" value="1"%2$s /> %3$s</label> ',
			esc_attr( '' !== $overlay_color ? $overlay_color : (string) $this->settings->get( 'overlay_color' ) ),
			checked( '' === $overlay_color, true, false ),
			esc_html__( 'Default color', 'coywolf-pdf-viewer' )
		);
		printf(
			'<input type="number" name="cpv_options[overlay_opacity]" class="small-text" min="0" max="95" step="5" value="%1$s" placeholder="%2$d" />%% <span class="description">%3$s</span>',
			esc_attr( '' !== $overlay_opacity ? (string) (int) $overlay_opacity : '' ),
			(int) $this->settings->get( 'overlay_opacity' ),
			esc_html__( 'opacity — empty for the default', 'coywolf-pdf-viewer' )
		);
		echo '</td></tr>';

		// Height mode + fixed height.
		$height_mode = isset( $values['options']['height_mode'] ) ? $values['options']['height_mode'] : '';
		echo '<tr><td><label for="coywolf-cpv-opt-height-mode">' . esc_html__( 'Height', 'coywolf-pdf-viewer' ) . '</label></td><td>';
		echo '<select name="cpv_options[height_mode]" id="coywolf-cpv-opt-height-mode">';
		printf( '<option value=""%s>%s</option>', selected( $height_mode, '', false ), esc_html( $this->default_label( 'height_mode' ) ) );
		printf( '<option value="auto"%s>%s</option>', selected( $height_mode, 'auto', false ), esc_html__( 'Fit one page', 'coywolf-pdf-viewer' ) );
		printf( '<option value="fixed"%s>%s</option>', selected( $height_mode, 'fixed', false ), esc_html__( 'Fixed height', 'coywolf-pdf-viewer' ) );
		echo '</select> ';
		$height = isset( $values['options']['height'] ) ? (int) $values['options']['height'] : 0;
		printf(
			'<input type="number" name="cpv_options[height]" id="coywolf-cpv-opt-height" class="small-text" min="0" max="3000" step="10" value="%s" placeholder="0" /> %s',
			esc_attr( $height > 0 ? (string) $height : '' ),
			/* translators: %d: the default viewer height from Settings. */
			esc_html( sprintf( __( 'pixels — used with fixed height; empty for the default (%d)', 'coywolf-pdf-viewer' ), (int) $this->settings->get( 'height' ) ) )
		);
		echo '</td></tr>';

		// Theme.
		$theme = isset( $values['options']['theme'] ) ? $values['options']['theme'] : '';
		echo '<tr><td><label for="coywolf-cpv-opt-theme">' . esc_html__( 'Color scheme', 'coywolf-pdf-viewer' ) . '</label></td><td>';
		echo '<select name="cpv_options[theme]" id="coywolf-cpv-opt-theme">';
		printf( '<option value=""%s>%s</option>', selected( $theme, '', false ), esc_html( $this->default_label( 'theme' ) ) );
		printf( '<option value="light"%s>%s</option>', selected( $theme, 'light', false ), esc_html__( 'Light', 'coywolf-pdf-viewer' ) );
		printf( '<option value="dark"%s>%s</option>', selected( $theme, 'dark', false ), esc_html__( 'Dark', 'coywolf-pdf-viewer' ) );
		printf( '<option value="system"%s>%s</option>', selected( $theme, 'system', false ), esc_html__( 'System', 'coywolf-pdf-viewer' ) );
		echo '</select></td></tr>';

		// Zoom.
		$zoom = isset( $values['options']['zoom'] ) ? $values['options']['zoom'] : '';
		echo '<tr><td><label for="coywolf-cpv-opt-zoom">' . esc_html__( 'Default zoom', 'coywolf-pdf-viewer' ) . '</label></td><td>';
		echo '<select name="cpv_options[zoom]" id="coywolf-cpv-opt-zoom">';
		printf( '<option value=""%s>%s</option>', selected( $zoom, '', false ), esc_html( $this->default_label( 'zoom' ) ) );
		printf( '<option value="fit-page"%s>%s</option>', selected( $zoom, 'fit-page', false ), esc_html__( 'Fit page', 'coywolf-pdf-viewer' ) );
		printf( '<option value="fit-width"%s>%s</option>', selected( $zoom, 'fit-width', false ), esc_html__( 'Fit width', 'coywolf-pdf-viewer' ) );
		printf( '<option value="automatic"%s>%s</option>', selected( $zoom, 'automatic', false ), esc_html__( 'Automatic', 'coywolf-pdf-viewer' ) );
		echo '</select></td></tr>';

		// Spread mode.
		$spread = isset( $values['options']['spread'] ) ? $values['options']['spread'] : '';
		echo '<tr><td><label for="coywolf-cpv-opt-spread">' . esc_html__( 'Spread mode', 'coywolf-pdf-viewer' ) . '</label></td><td>';
		echo '<select name="cpv_options[spread]" id="coywolf-cpv-opt-spread">';
		printf( '<option value=""%s>%s</option>', selected( $spread, '', false ), esc_html__( 'Default (single pages)', 'coywolf-pdf-viewer' ) );
		printf( '<option value="none"%s>%s</option>', selected( $spread, 'none', false ), esc_html__( 'Single pages', 'coywolf-pdf-viewer' ) );
		printf( '<option value="odd"%s>%s</option>', selected( $spread, 'odd', false ), esc_html__( 'Two-page spread (odd pages left)', 'coywolf-pdf-viewer' ) );
		printf( '<option value="even"%s>%s</option>', selected( $spread, 'even', false ), esc_html__( 'Two-page spread (even pages left)', 'coywolf-pdf-viewer' ) );
		echo '</select></td></tr>';

		// Scroll layout.
		$scroll = isset( $values['options']['scroll'] ) ? $values['options']['scroll'] : '';
		echo '<tr><td><label for="coywolf-cpv-opt-scroll">' . esc_html__( 'Scroll layout', 'coywolf-pdf-viewer' ) . '</label></td><td>';
		echo '<select name="cpv_options[scroll]" id="coywolf-cpv-opt-scroll">';
		printf( '<option value=""%s>%s</option>', selected( $scroll, '', false ), esc_html__( 'Default (vertical)', 'coywolf-pdf-viewer' ) );
		printf( '<option value="vertical"%s>%s</option>', selected( $scroll, 'vertical', false ), esc_html__( 'Vertical', 'coywolf-pdf-viewer' ) );
		printf( '<option value="horizontal"%s>%s</option>', selected( $scroll, 'horizontal', false ), esc_html__( 'Horizontal', 'coywolf-pdf-viewer' ) );
		echo '</select></td></tr>';

		echo '</table>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( $editing ? __( 'Update PDF', 'coywolf-pdf-viewer' ) : __( 'Add PDF', 'coywolf-pdf-viewer' ) );
		echo '</form></div>';
	}

	/**
	 * "Default (<current settings value>)" label for a tri-state select.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	private function default_label( $key ) {
		$value  = $this->settings->get( $key );
		$labels = array(
			'auto'  => __( 'Fit one page', 'coywolf-pdf-viewer' ),
			'fixed' => __( 'Fixed height', 'coywolf-pdf-viewer' ),
		);
		if ( is_bool( $value ) ) {
			$current = $value ? __( 'On', 'coywolf-pdf-viewer' ) : __( 'Off', 'coywolf-pdf-viewer' );
		} elseif ( isset( $labels[ (string) $value ] ) ) {
			$current = $labels[ (string) $value ];
		} else {
			$current = (string) $value;
		}
		/* translators: %s: the current default from Settings. */
		return sprintf( __( 'Default (%s)', 'coywolf-pdf-viewer' ), $current );
	}

	/**
	 * Save handler for the Add/Edit PDF form.
	 */
	public function handle_save_pdf() {
		if ( ! current_user_can( Coywolf_PDF_Viewer::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to manage PDFs.', 'coywolf-pdf-viewer' ) );
		}
		check_admin_referer( 'coywolf_cpv_save_pdf' );

		$pdf_id = isset( $_POST['pdf_id'] ) ? absint( wp_unslash( $_POST['pdf_id'] ) ) : 0;
		$data   = array(
			'name'          => isset( $_POST['cpv_name'] ) ? sanitize_text_field( wp_unslash( $_POST['cpv_name'] ) ) : '',
			'caption'       => isset( $_POST['cpv_caption'] ) ? wp_kses_post( wp_unslash( $_POST['cpv_caption'] ) ) : '',
			'source'        => isset( $_POST['cpv_source'] ) ? sanitize_key( wp_unslash( $_POST['cpv_source'] ) ) : 'media',
			'attachment_id' => isset( $_POST['cpv_attachment_id'] ) ? absint( wp_unslash( $_POST['cpv_attachment_id'] ) ) : 0,
			'url'           => isset( $_POST['cpv_url'] ) ? esc_url_raw( wp_unslash( $_POST['cpv_url'] ) ) : '',
			'options'       => isset( $_POST['cpv_options'] ) && is_array( $_POST['cpv_options'] ) ? map_deep( wp_unslash( $_POST['cpv_options'] ), 'sanitize_text_field' ) : array(),
		);

		// The "default color" checkbox wins over the color input (a color
		// input cannot submit an empty value).
		if ( ! empty( $data['options']['overlay_default'] ) ) {
			$data['options']['overlay_color'] = '';
		}
		unset( $data['options']['overlay_default'] );

		// Validate the source.
		$error = '';
		if ( 'url' === $data['source'] ) {
			if ( '' === $data['url'] || ! wp_http_validate_url( $data['url'] ) ) {
				$error = 'url';
			}
		} elseif ( $data['attachment_id'] < 1 || 'application/pdf' !== get_post_mime_type( $data['attachment_id'] ) ) {
			$error = 'media';
		}
		if ( '' !== $error ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'      => self::PAGE_ADD,
						'pdf'       => $pdf_id ? $pdf_id : false,
						'cpv_error' => $error,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( $pdf_id > 0 ) {
			$this->store->update( $pdf_id, $data );
			$saved = $pdf_id;
		} else {
			$saved = $this->store->insert( $data );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE_ADD,
					'pdf'       => $saved,
					'cpv_saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/* --------------------------------------------------------------------- *
	 * Notices
	 * --------------------------------------------------------------------- */

	/**
	 * Saved/deleted/error notices on plugin screens.
	 */
	public function notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, self::PAGE ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own redirects.
		if ( isset( $_GET['cpv_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'PDF saved.', 'coywolf-pdf-viewer' ) . '</p></div>';
		}
		if ( isset( $_GET['cpv_deleted'] ) ) {
			$count = absint( wp_unslash( $_GET['cpv_deleted'] ) );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: number of PDFs deleted. */
						_n( '%s PDF deleted. Its block was removed from all posts and pages.', '%s PDFs deleted. Their blocks were removed from all posts and pages.', $count, 'coywolf-pdf-viewer' ),
						number_format_i18n( $count )
					)
				)
			);
		}
		if ( isset( $_GET['cpv_error'] ) ) {
			$which   = sanitize_key( wp_unslash( $_GET['cpv_error'] ) );
			$message = 'url' === $which
				? __( 'Enter a valid PDF URL.', 'coywolf-pdf-viewer' )
				: __( 'Choose a PDF from the Media Library.', 'coywolf-pdf-viewer' );
			echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
