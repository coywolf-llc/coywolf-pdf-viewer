<?php
/**
 * PDF store.
 *
 * PDFs live in a plugin-owned table: a name, a caption, a source (a Media
 * Library attachment or an external URL), and per-PDF viewer options that
 * override the Settings defaults ('' = inherit). Custom-table reads use the
 * %i identifier placeholder (WordPress 6.2+); DirectDatabaseQuery notices are
 * suppressed because these are plugin-owned tables with no Core API
 * equivalent.
 *
 * @package CoywolfPDFViewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for the PDFs table.
 */
class Coywolf_CPV_Store {

	/**
	 * Per-PDF viewer option keys. Tri-state strings: '' (inherit the Settings
	 * default), 'on', or 'off' — except height (0 = inherit), theme, and zoom
	 * ('' = inherit).
	 *
	 * @var array
	 */
	const OPTION_KEYS = array( 'download', 'print', 'fullscreen', 'sidebar', 'search', 'zoom_controls', 'click_to_load' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'coywolf_cpv_pdfs';
	}

	/**
	 * Create the PDFs table on activation.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL DEFAULT '',
  caption text NOT NULL,
  source varchar(10) NOT NULL DEFAULT 'media',
  attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
  url text NOT NULL,
  options longtext NOT NULL,
  created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY name (name(191))
) {$charset};"
		);
	}

	/**
	 * Default per-PDF options (everything inherits the Settings defaults).
	 *
	 * @return array
	 */
	public static function default_options() {
		$options = array_fill_keys( self::OPTION_KEYS, '' );

		$options['height'] = 0;
		$options['theme']  = '';
		$options['zoom']   = '';
		return $options;
	}

	/**
	 * One PDF by ID, or null.
	 *
	 * @param int $id PDF ID.
	 * @return array|null
	 */
	public function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * All PDFs, newest first, optionally filtered by a search term over name
	 * and caption.
	 *
	 * @param string $search Optional search term.
	 * @return array[]
	 */
	public function all( $search = '' ) {
		global $wpdb;
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE name LIKE %s OR caption LIKE %s ORDER BY created DESC, id DESC', self::table(), $like, $like ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY created DESC, id DESC', self::table() ), ARRAY_A );
		}
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Insert a PDF. Returns the new ID or 0.
	 *
	 * @param array $data name, caption, source, attachment_id, url, options.
	 * @return int
	 */
	public function insert( $data ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->insert(
			self::table(),
			array(
				'name'          => $this->clean_name( $data ),
				'caption'       => isset( $data['caption'] ) ? wp_kses_post( $data['caption'] ) : '',
				'source'        => $this->clean_source( $data ),
				'attachment_id' => isset( $data['attachment_id'] ) ? absint( $data['attachment_id'] ) : 0,
				'url'           => isset( $data['url'] ) ? esc_url_raw( $data['url'] ) : '',
				'options'       => wp_json_encode( $this->clean_options( isset( $data['options'] ) ? $data['options'] : array() ) ),
				'created'       => $now,
				'updated'       => $now,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Update a PDF. Only the provided keys change.
	 *
	 * @param int   $id   PDF ID.
	 * @param array $data Fields to update.
	 * @return bool
	 */
	public function update( $id, $data ) {
		global $wpdb;
		$fields  = array();
		$formats = array();
		if ( array_key_exists( 'name', $data ) ) {
			$fields['name'] = $this->clean_name( $data );
			$formats[]      = '%s';
		}
		if ( array_key_exists( 'caption', $data ) ) {
			$fields['caption'] = wp_kses_post( (string) $data['caption'] );
			$formats[]         = '%s';
		}
		if ( array_key_exists( 'source', $data ) ) {
			$fields['source'] = $this->clean_source( $data );
			$formats[]        = '%s';
		}
		if ( array_key_exists( 'attachment_id', $data ) ) {
			$fields['attachment_id'] = absint( $data['attachment_id'] );
			$formats[]               = '%d';
		}
		if ( array_key_exists( 'url', $data ) ) {
			$fields['url'] = esc_url_raw( (string) $data['url'] );
			$formats[]     = '%s';
		}
		if ( array_key_exists( 'options', $data ) ) {
			$current = $this->get( $id );

			// Merge over the stored options so partial updates keep the rest.
			$merged            = array_merge( $current ? $current['options'] : self::default_options(), (array) $data['options'] );
			$fields['options'] = wp_json_encode( $this->clean_options( $merged ) );
			$formats[]         = '%s';
		}
		if ( empty( $fields ) ) {
			return false;
		}
		$fields['updated'] = current_time( 'mysql' );
		$formats[]         = '%s';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( self::table(), $fields, array( 'id' => (int) $id ), $formats, array( '%d' ) );
	}

	/**
	 * Delete a PDF row (the caller scrubs blocks via the index first).
	 *
	 * @param int $id PDF ID.
	 * @return bool
	 */
	public function delete( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * The PDF's file URL: the attachment URL for Media Library PDFs, the
	 * stored URL otherwise. Empty when the attachment is gone.
	 *
	 * @param array $pdf Hydrated PDF.
	 * @return string
	 */
	public function src( $pdf ) {
		if ( 'media' === $pdf['source'] ) {
			$url = wp_get_attachment_url( $pdf['attachment_id'] );
			return $url ? $url : '';
		}
		return $pdf['url'];
	}

	/**
	 * The filename the viewer's download button suggests.
	 *
	 * @param array $pdf Hydrated PDF.
	 * @return string
	 */
	public function filename( $pdf ) {
		$src  = $this->src( $pdf );
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		$name = basename( $path );
		if ( '' === $name || false === stripos( $name, '.pdf' ) ) {
			$name = sanitize_file_name( $pdf['name'] );
			$name = ( '' !== $name ? $name : 'document' ) . '.pdf';
		}
		return $name;
	}

	/**
	 * Normalize a DB row: ints for ids, options decoded over defaults.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private function hydrate( $row ) {
		$options = json_decode( (string) $row['options'], true );
		return array(
			'id'            => (int) $row['id'],
			'name'          => (string) $row['name'],
			'caption'       => (string) $row['caption'],
			'source'        => (string) $row['source'],
			'attachment_id' => (int) $row['attachment_id'],
			'url'           => (string) $row['url'],
			'options'       => $this->clean_options( is_array( $options ) ? $options : array() ),
			'created'       => (string) $row['created'],
			'updated'       => (string) $row['updated'],
		);
	}

	/**
	 * Sanitized name (falls back to the source filename so rows are never
	 * blank in lists).
	 *
	 * @param array $data Input data.
	 * @return string
	 */
	private function clean_name( $data ) {
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( '' === $name && ! empty( $data['url'] ) ) {
			$name = basename( (string) wp_parse_url( (string) $data['url'], PHP_URL_PATH ) );
		}
		if ( '' === $name && ! empty( $data['attachment_id'] ) ) {
			$name = get_the_title( absint( $data['attachment_id'] ) );
		}
		return $name;
	}

	/**
	 * 'media' or 'url'.
	 *
	 * @param array $data Input data.
	 * @return string
	 */
	private function clean_source( $data ) {
		return ( isset( $data['source'] ) && 'url' === $data['source'] ) ? 'url' : 'media';
	}

	/**
	 * Sanitize per-PDF options into the known shape.
	 *
	 * @param array $options Raw options.
	 * @return array
	 */
	public function clean_options( $options ) {
		$clean = self::default_options();
		foreach ( self::OPTION_KEYS as $key ) {
			if ( isset( $options[ $key ] ) && in_array( $options[ $key ], array( 'on', 'off' ), true ) ) {
				$clean[ $key ] = $options[ $key ];
			}
		}
		if ( isset( $options['height'] ) ) {
			$clean['height'] = min( 3000, absint( $options['height'] ) );
		}
		if ( isset( $options['theme'] ) && in_array( $options['theme'], array( 'light', 'dark', 'system' ), true ) ) {
			$clean['theme'] = $options['theme'];
		}
		if ( isset( $options['zoom'] ) && in_array( $options['zoom'], array( 'fit-page', 'fit-width', 'automatic' ), true ) ) {
			$clean['zoom'] = $options['zoom'];
		}
		return $clean;
	}
}
