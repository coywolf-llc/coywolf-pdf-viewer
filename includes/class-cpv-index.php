<?php
/**
 * Post ↔ PDF usage index.
 *
 * Parses posts/pages on save for coywolf/pdf blocks and records which PDFs
 * they embed — in postmeta (for quick per-post lookups) and in a usage table
 * (for the All PDFs counts and the filtered admin views). Also owns removal:
 * deleting a PDF strips its block from every post that embeds it.
 *
 * Custom-table reads use the %i identifier placeholder (WordPress 6.2+); the
 * DirectDatabaseQuery notices are suppressed because these are plugin-owned
 * tables with no Core API equivalent.
 *
 * @package CoywolfPDFViewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks which posts and pages embed which PDFs.
 */
class Coywolf_CPV_Index {

	/**
	 * Post types indexed.
	 *
	 * @var array
	 */
	private $types = array( 'post', 'page' );

	/**
	 * PDF store.
	 *
	 * @var Coywolf_CPV_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Coywolf_CPV_Store $store PDF store.
	 */
	public function __construct( Coywolf_CPV_Store $store ) {
		$this->store = $store;

		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_delete' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_admin_query' ) );
	}

	/**
	 * Usage table name.
	 *
	 * @return string
	 */
	public static function usage_table() {
		global $wpdb;
		return $wpdb->prefix . 'coywolf_cpv_usage';
	}

	/**
	 * Create the usage table on activation.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$usage   = self::usage_table();
		dbDelta(
			"CREATE TABLE {$usage} (
  pdf_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  post_type varchar(20) NOT NULL DEFAULT 'post',
  PRIMARY KEY  (pdf_id,post_id),
  KEY pdf_id (pdf_id),
  KEY post_id (post_id)
) {$charset};"
		);
	}

	/* --------------------------------------------------------------------- *
	 * Indexing
	 * --------------------------------------------------------------------- */

	/**
	 * Reindex on content save.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$this->reindex( $post );
	}

	/**
	 * Reindex on status change (publish/unpublish/trash).
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ) {
		unset( $new_status, $old_status );
		if ( $post instanceof WP_Post ) {
			$this->reindex( $post );
		}
	}

	/**
	 * Remove usage rows when a post is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_delete( $post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::usage_table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
	}

	/**
	 * Rebuild the index for every post/page that mentions the block. Used on
	 * activation so usage counts work for content authored before install.
	 */
	public function rebuild() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN (%s, %s) AND post_status NOT IN ('auto-draft', 'inherit', 'trash') AND post_content LIKE %s",
				'post',
				'page',
				'%' . $wpdb->esc_like( '<!-- wp:coywolf/pdf' ) . '%'
			)
		);
		foreach ( (array) $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post ) {
				$this->reindex( $post );
			}
		}
	}

	/**
	 * Rebuild the postmeta + usage rows for one post.
	 *
	 * @param WP_Post $post Post.
	 */
	private function reindex( $post ) {
		if ( ! in_array( $post->post_type, $this->types, true ) ) {
			return;
		}

		$ids = $this->extract_ids( $post->post_content );

		if ( empty( $ids ) ) {
			delete_post_meta( $post->ID, 'coywolf_cpv_pdfs' );
		} else {
			update_post_meta( $post->ID, 'coywolf_cpv_pdfs', $ids );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::usage_table(), array( 'post_id' => (int) $post->ID ), array( '%d' ) );

		// Only count publicly visible content as "embedding" a PDF.
		if ( 'publish' === $post->post_status ) {
			foreach ( $ids as $pdf_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->insert(
					self::usage_table(),
					array(
						'pdf_id'    => (int) $pdf_id,
						'post_id'   => (int) $post->ID,
						'post_type' => $post->post_type,
					),
					array( '%d', '%d', '%s' )
				);
			}
		}
	}

	/**
	 * Pull unique PDF IDs out of post content.
	 *
	 * @param string $content Post content.
	 * @return int[]
	 */
	private function extract_ids( $content ) {
		if ( false === strpos( $content, 'coywolf/pdf' ) ) {
			return array();
		}
		$ids = array();
		$this->walk_blocks( parse_blocks( $content ), $ids );
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Recursively collect PDF IDs from parsed blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $ids    Accumulator (by reference).
	 */
	private function walk_blocks( $blocks, &$ids ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && 'coywolf/pdf' === $block['blockName'] && ! empty( $block['attrs']['pdfId'] ) ) {
				$ids[] = (int) $block['attrs']['pdfId'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->walk_blocks( $block['innerBlocks'], $ids );
			}
		}
	}

	/* --------------------------------------------------------------------- *
	 * Reads
	 * --------------------------------------------------------------------- */

	/**
	 * Usage counts per PDF, keyed by ID: { posts, pages }.
	 *
	 * @param int[] $ids PDF IDs.
	 * @return array
	 */
	public function usage_map( $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$args         = array_merge( array( self::usage_table() ), $ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pdf_id, post_type, COUNT(*) AS c FROM %i WHERE pdf_id IN ($placeholders) GROUP BY pdf_id, post_type", $args ), ARRAY_A );

		$map = array();
		foreach ( (array) $rows as $row ) {
			$pdf_id = (int) $row['pdf_id'];
			if ( ! isset( $map[ $pdf_id ] ) ) {
				$map[ $pdf_id ] = array(
					'posts' => 0,
					'pages' => 0,
				);
			}
			if ( 'page' === $row['post_type'] ) {
				$map[ $pdf_id ]['pages'] += (int) $row['c'];
			} else {
				$map[ $pdf_id ]['posts'] += (int) $row['c'];
			}
		}
		return $map;
	}

	/**
	 * Post IDs that embed a given PDF.
	 *
	 * @param int $pdf_id PDF ID.
	 * @return int[]
	 */
	public function post_ids_for( $pdf_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE pdf_id = %d', self::usage_table(), $pdf_id ) );
		return array_map( 'intval', (array) $ids );
	}

	/* --------------------------------------------------------------------- *
	 * Admin filtered view
	 * --------------------------------------------------------------------- */

	/**
	 * Register the filter query var.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function add_query_var( $vars ) {
		$vars[] = 'coywolf_cpv_pdf';
		return $vars;
	}

	/**
	 * On edit.php, when ?coywolf_cpv_pdf=<id> is set, restrict the list to
	 * posts that embed that PDF.
	 *
	 * @param WP_Query $query Query.
	 */
	public function filter_admin_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		global $pagenow;
		if ( 'edit.php' !== $pagenow ) {
			return;
		}
		$pdf_id = isset( $_GET['coywolf_cpv_pdf'] ) ? absint( wp_unslash( $_GET['coywolf_cpv_pdf'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $pdf_id < 1 ) {
			return;
		}
		$ids = $this->post_ids_for( $pdf_id );
		$query->set( 'post__in', ! empty( $ids ) ? $ids : array( 0 ) );
	}

	/* --------------------------------------------------------------------- *
	 * Removal
	 * --------------------------------------------------------------------- */

	/**
	 * Strip the coywolf/pdf block for a (deleted) PDF from every post/page
	 * that embeds it. The resulting wp_update_post re-runs the indexer, which
	 * clears the usage rows.
	 *
	 * @param int $pdf_id PDF ID.
	 */
	public function remove_pdf( $pdf_id ) {
		foreach ( $this->post_ids_for( $pdf_id ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || false === strpos( $post->post_content, 'coywolf/pdf' ) ) {
				continue;
			}
			$content = serialize_blocks( $this->strip_blocks( parse_blocks( $post->post_content ), (int) $pdf_id ) );
			if ( $content !== $post->post_content ) {
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => $content,
					)
				);
			}
		}
	}

	/**
	 * Recursively drop the PDF's blocks from a parsed block tree.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param int   $pdf_id PDF ID.
	 * @return array
	 */
	private function strip_blocks( $blocks, $pdf_id ) {
		$kept = array();
		foreach ( $blocks as $block ) {
			if (
				isset( $block['blockName'] ) && 'coywolf/pdf' === $block['blockName']
				&& isset( $block['attrs']['pdfId'] ) && (int) $block['attrs']['pdfId'] === $pdf_id
			) {
				continue;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->strip_blocks( $block['innerBlocks'], $pdf_id );
			}
			$kept[] = $block;
		}
		return $kept;
	}
}
