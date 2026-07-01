<?php
/**
 * REST routes for the block editor: list, create, and update PDFs.
 *
 * Listing needs edit_posts (anyone who can use the block); creating and
 * updating need upload_files (the same trust level as adding to the Media
 * Library).
 *
 * @package CoywolfPDFViewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller.
 */
class Coywolf_CPV_REST {

	/**
	 * Route namespace.
	 */
	const NS = 'coywolf-cpv/v1';

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
	 * Constructor.
	 *
	 * @param Coywolf_CPV_Store    $store    PDF store.
	 * @param Coywolf_CPV_Settings $settings Settings.
	 */
	public function __construct( Coywolf_CPV_Store $store, Coywolf_CPV_Settings $settings ) {
		$this->store    = $store;
		$this->settings = $settings;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 */
	public function register_routes() {
		$can_list = static function () {
			return current_user_can( 'edit_posts' );
		};
		$can_save = static function () {
			return current_user_can( 'upload_files' ) || current_user_can( Coywolf_PDF_Viewer::CAPABILITY );
		};

		register_rest_route(
			self::NS,
			'/pdfs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_pdfs' ),
					'permission_callback' => $can_list,
					'args'                => array(
						's' => array(
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_pdf' ),
					'permission_callback' => $can_save,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pdfs/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_pdf' ),
					'permission_callback' => $can_list,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_pdf' ),
					'permission_callback' => $can_save,
				),
			)
		);
	}

	/**
	 * GET /pdfs — all PDFs (optionally searched), newest first.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_pdfs( $request ) {
		$pdfs = $this->store->all( (string) $request['s'] );
		return rest_ensure_response(
			array(
				'pdfs'     => array_map( array( $this, 'shape' ), $pdfs ),
				'defaults' => $this->settings->all(),
			)
		);
	}

	/**
	 * GET /pdfs/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_pdf( $request ) {
		$pdf = $this->store->get( (int) $request['id'] );
		if ( ! $pdf ) {
			return new WP_Error( 'coywolf_cpv_not_found', __( 'PDF not found.', 'coywolf-pdf-viewer' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->shape( $pdf ) );
	}

	/**
	 * POST /pdfs — create.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_pdf( $request ) {
		$data  = $this->input( $request );
		$error = $this->validate_source( $data );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		$id = $this->store->insert( $data );
		if ( $id < 1 ) {
			return new WP_Error( 'coywolf_cpv_insert_failed', __( 'Saving the PDF failed.', 'coywolf-pdf-viewer' ), array( 'status' => 500 ) );
		}
		return rest_ensure_response( $this->shape( $this->store->get( $id ) ) );
	}

	/**
	 * POST /pdfs/{id} — update name/caption/options.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_pdf( $request ) {
		$id  = (int) $request['id'];
		$pdf = $this->store->get( $id );
		if ( ! $pdf ) {
			return new WP_Error( 'coywolf_cpv_not_found', __( 'PDF not found.', 'coywolf-pdf-viewer' ), array( 'status' => 404 ) );
		}

		// The write capability is upload_files-OR-manage, so scope edits per
		// object: a manager may edit any PDF; otherwise a media-backed PDF may
		// be edited only by someone who can edit that attachment, and a URL
		// PDF (no attachment to own) requires the manage capability. Stops an
		// upload_files author from altering another author's PDF.
		if ( ! current_user_can( Coywolf_PDF_Viewer::CAPABILITY ) ) {
			$attachment_id = ( 'media' === $pdf['source'] ) ? (int) $pdf['attachment_id'] : 0;
			if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
				return new WP_Error( 'coywolf_cpv_forbidden', __( 'You are not allowed to edit this PDF.', 'coywolf-pdf-viewer' ), array( 'status' => 403 ) );
			}
		}
		$data = array();
		foreach ( array( 'name', 'caption' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$data[ $key ] = (string) $request->get_param( $key );
			}
		}
		if ( null !== $request->get_param( 'options' ) && is_array( $request->get_param( 'options' ) ) ) {
			$data['options'] = $request->get_param( 'options' );
		}
		if ( ! empty( $data ) ) {
			$this->store->update( $id, $data );
		}
		return rest_ensure_response( $this->shape( $this->store->get( $id ) ) );
	}

	/**
	 * Common create input.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function input( $request ) {
		return array(
			'name'          => (string) $request->get_param( 'name' ),
			'caption'       => (string) $request->get_param( 'caption' ),
			'source'        => (string) $request->get_param( 'source' ),
			'attachment_id' => (int) $request->get_param( 'attachment_id' ),
			'url'           => (string) $request->get_param( 'url' ),
			'options'       => is_array( $request->get_param( 'options' ) ) ? $request->get_param( 'options' ) : array(),
		);
	}

	/**
	 * Reject creates whose source does not resolve to a PDF.
	 *
	 * @param array $data Create input.
	 * @return true|WP_Error
	 */
	private function validate_source( $data ) {
		if ( 'url' === $data['source'] ) {
			if ( '' === $data['url'] || ! wp_http_validate_url( $data['url'] ) ) {
				return new WP_Error( 'coywolf_cpv_bad_url', __( 'Enter a valid PDF URL.', 'coywolf-pdf-viewer' ), array( 'status' => 400 ) );
			}
			return true;
		}
		$attachment_id = (int) $data['attachment_id'];
		if ( $attachment_id < 1
			|| 'application/pdf' !== get_post_mime_type( $attachment_id )
			|| ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new WP_Error( 'coywolf_cpv_bad_attachment', __( 'Choose a PDF from the Media Library.', 'coywolf-pdf-viewer' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Response shape for one PDF.
	 *
	 * @param array $pdf Hydrated PDF.
	 * @return array
	 */
	private function shape( $pdf ) {
		$poster = $this->store->poster( $pdf );
		return array(
			'id'            => $pdf['id'],
			'name'          => $pdf['name'],
			'caption'       => $pdf['caption'],
			'source'        => $pdf['source'],
			'attachment_id' => $pdf['attachment_id'],
			'url'           => $this->store->src( $pdf ),
			'poster'        => $poster['url'],
			'options'       => $pdf['options'],
			'created'       => $pdf['created'],
		);
	}
}
