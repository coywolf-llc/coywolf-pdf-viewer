<?php
/**
 * All PDFs list table.
 *
 * Lists the stored PDFs joined with post/page usage counts. Search runs in
 * SQL over name + caption; the source/usage filter and sorting are applied
 * locally; pagination in PHP.
 *
 * @package CoywolfPDFViewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The All PDFs table.
 */
class Coywolf_CPV_List_Table extends WP_List_Table {

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
	 * Constructor.
	 *
	 * @param Coywolf_CPV_Store $store PDF store.
	 * @param Coywolf_CPV_Index $index Usage index.
	 */
	public function __construct( Coywolf_CPV_Store $store, Coywolf_CPV_Index $index ) {
		parent::__construct(
			array(
				'singular' => 'pdf',
				'plural'   => 'pdfs',
				'ajax'     => false,
			)
		);
		$this->store = $store;
		$this->index = $index;
	}

	/**
	 * Column definitions.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'      => '<input type="checkbox" />',
			'name'    => __( 'Name', 'coywolf-pdf-viewer' ),
			'source'  => __( 'Source', 'coywolf-pdf-viewer' ),
			'posts'   => __( 'Posts', 'coywolf-pdf-viewer' ),
			'pages'   => __( 'Pages', 'coywolf-pdf-viewer' ),
			'created' => __( 'Added', 'coywolf-pdf-viewer' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		return array(
			'name'    => array( 'name', false ),
			'source'  => array( 'source', false ),
			'posts'   => array( 'posts', false ),
			'pages'   => array( 'pages', false ),
			// True: the default view is already sorted by this, descending.
			'created' => array( 'created', true ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'coywolf-pdf-viewer' ) );
	}

	/**
	 * Source/usage filter dropdown, right of the bulk actions.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$current = isset( $_REQUEST['cpv_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['cpv_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$options = array(
			''       => __( 'All PDFs', 'coywolf-pdf-viewer' ),
			'media'  => __( 'Media Library', 'coywolf-pdf-viewer' ),
			'url'    => __( 'External URL', 'coywolf-pdf-viewer' ),
			'posts'  => __( 'On posts', 'coywolf-pdf-viewer' ),
			'pages'  => __( 'On pages', 'coywolf-pdf-viewer' ),
			'unused' => __( 'Not embedded', 'coywolf-pdf-viewer' ),
		);
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="coywolf-cpv-filter">' . esc_html__( 'Filter PDFs', 'coywolf-pdf-viewer' ) . '</label>';
		echo '<select name="cpv_filter" id="coywolf-cpv-filter" class="coywolf-cpv-filter">';
		foreach ( $options as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'coywolf-pdf-viewer' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * Name column with row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_name( $item ) {
		$edit_url = add_query_arg(
			array(
				'page' => Coywolf_CPV_Admin::PAGE_ADD,
				'pdf'  => (int) $item['id'],
			),
			admin_url( 'admin.php' )
		);

		$name    = '' !== $item['name'] ? $item['name'] : __( '(untitled)', 'coywolf-pdf-viewer' );
		$actions = array(
			'edit'  => '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'coywolf-pdf-viewer' ) . '</a>',
			'view'  => '' !== $item['src'] ? '<a href="' . esc_url( $item['src'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'coywolf-pdf-viewer' ) . '</a>' : '',
			'trash' => '<a href="' . esc_url( $this->delete_url( $item['id'] ) ) . '" class="coywolf-cpv-delete">' . esc_html__( 'Delete', 'coywolf-pdf-viewer' ) . '</a>',
		);
		$actions = array_filter( $actions );

		return '<strong><a class="row-title" href="' . esc_url( $edit_url ) . '">' . esc_html( $name ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/**
	 * Single-delete URL (nonce-protected).
	 *
	 * @param int $id PDF ID.
	 * @return string
	 */
	private function delete_url( $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'   => Coywolf_CPV_Admin::PAGE,
					'action' => 'delete',
					'pdf'    => (int) $id,
				),
				admin_url( 'admin.php' )
			),
			'coywolf_cpv_delete_' . (int) $id
		);
	}

	/**
	 * Source column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_source( $item ) {
		if ( 'url' === $item['source'] ) {
			return esc_html__( 'External URL', 'coywolf-pdf-viewer' );
		}
		if ( '' === $item['src'] ) {
			return '<span class="coywolf-cpv-missing">' . esc_html__( 'Media Library (file missing)', 'coywolf-pdf-viewer' ) . '</span>';
		}
		return esc_html__( 'Media Library', 'coywolf-pdf-viewer' );
	}

	/**
	 * Posts usage column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_posts( $item ) {
		return $this->usage_cell( (int) $item['posts'], 'post', $item['id'] );
	}

	/**
	 * Pages usage column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_pages( $item ) {
		return $this->usage_cell( (int) $item['pages'], 'page', $item['id'] );
	}

	/**
	 * Render a usage count as a link to the filtered post-type list, or "0".
	 *
	 * @param int    $count     Usage count.
	 * @param string $post_type Post type.
	 * @param int    $id        PDF ID.
	 * @return string
	 */
	private function usage_cell( $count, $post_type, $id ) {
		if ( $count < 1 ) {
			return '0';
		}
		$url = add_query_arg(
			array(
				'post_type'       => $post_type,
				'coywolf_cpv_pdf' => (int) $id,
			),
			admin_url( 'edit.php' )
		);
		return '<a href="' . esc_url( $url ) . '">' . esc_html( number_format_i18n( $count ) ) . '</a>';
	}

	/**
	 * Added column, in the site timezone and format.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_created( $item ) {
		$timestamp = strtotime( $item['created'] );
		if ( ! $timestamp ) {
			return '&#8212;';
		}
		return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) );
	}

	/**
	 * Fallback column renderer.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
	}

	/**
	 * Message when there are no PDFs.
	 */
	public function no_items() {
		printf(
			/* translators: %s: Add PDF screen URL. */
			wp_kses_post( __( 'No PDFs yet. <a href="%s">Add your first PDF</a>.', 'coywolf-pdf-viewer' ) ),
			esc_url( add_query_arg( 'page', Coywolf_CPV_Admin::PAGE_ADD, admin_url( 'admin.php' ) ) )
		);
	}

	/**
	 * Fetch + assemble the rows.
	 */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		// Search by name or caption (SQL LIKE).
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pdfs   = $this->store->all( $search );

		$rows = array();
		$ids  = array();
		foreach ( $pdfs as $pdf ) {
			$ids[]  = $pdf['id'];
			$rows[] = array(
				'id'      => $pdf['id'],
				'name'    => $pdf['name'],
				'source'  => $pdf['source'],
				'src'     => $this->store->src( $pdf ),
				'created' => $pdf['created'],
				'posts'   => 0,
				'pages'   => 0,
			);
		}

		$usage = $this->index->usage_map( $ids );
		foreach ( $rows as &$row ) {
			if ( isset( $usage[ $row['id'] ] ) ) {
				$row['posts'] = (int) $usage[ $row['id'] ]['posts'];
				$row['pages'] = (int) $usage[ $row['id'] ]['pages'];
			}
		}
		unset( $row );

		// Source/usage filter.
		$filter = isset( $_REQUEST['cpv_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['cpv_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $filter, array( 'media', 'url', 'posts', 'pages', 'unused' ), true ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						if ( 'media' === $filter || 'url' === $filter ) {
							return $filter === $row['source'];
						}
						if ( 'unused' === $filter ) {
							return ( $row['posts'] + $row['pages'] ) < 1;
						}
						return (int) $row[ $filter ] > 0;
					}
				)
			);
		}

		// Sort. Default: newest first.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_REQUEST['order'] ) ? strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $orderby, array( 'name', 'source', 'created', 'posts', 'pages' ), true ) ) {
			$orderby = 'created';
			if ( '' === $order ) {
				$order = 'desc';
			}
		}
		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$order = 'asc';
		}
		usort(
			$rows,
			static function ( $a, $b ) use ( $orderby ) {
				if ( 'name' === $orderby || 'source' === $orderby || 'created' === $orderby ) {
					return strcasecmp( (string) $a[ $orderby ], (string) $b[ $orderby ] );
				}
				return $a[ $orderby ] <=> $b[ $orderby ];
			}
		);
		if ( 'desc' === $order ) {
			$rows = array_reverse( $rows );
		}

		// Paginate in PHP.
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$total        = count( $rows );
		$this->items  = array_slice( $rows, ( $current_page - 1 ) * $per_page, $per_page );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}
}
