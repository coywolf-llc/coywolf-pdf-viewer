<?php
/**
 * Plugin composition root.
 *
 * Wires the store, usage index, settings, REST routes, block, and admin
 * screens together, and owns activation/deactivation.
 *
 * @package CoywolfPDFViewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin singleton.
 */
class Coywolf_PDF_Viewer {

	/**
	 * Plugin version (kept in sync with the main file header by the release
	 * workflow).
	 */
	const VERSION = '1.0.0';

	/**
	 * Capability gating the admin screens, granted to administrators.
	 */
	const CAPABILITY = 'coywolf_cpv_manage';

	/**
	 * Singleton instance.
	 *
	 * @var Coywolf_PDF_Viewer|null
	 */
	private static $instance = null;

	/**
	 * PDF store.
	 *
	 * @var Coywolf_CPV_Store
	 */
	public $store;

	/**
	 * Usage index.
	 *
	 * @var Coywolf_CPV_Index
	 */
	public $index;

	/**
	 * Settings.
	 *
	 * @var Coywolf_CPV_Settings
	 */
	public $settings;

	/**
	 * REST routes.
	 *
	 * @var Coywolf_CPV_REST
	 */
	public $rest;

	/**
	 * Block.
	 *
	 * @var Coywolf_CPV_Block
	 */
	public $block;

	/**
	 * Admin screens.
	 *
	 * @var Coywolf_CPV_Admin
	 */
	public $admin;

	/**
	 * Get (or build) the singleton.
	 *
	 * @return Coywolf_PDF_Viewer
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the modules.
	 */
	private function __construct() {
		$this->store    = new Coywolf_CPV_Store();
		$this->index    = new Coywolf_CPV_Index( $this->store );
		$this->settings = new Coywolf_CPV_Settings();
		$this->rest     = new Coywolf_CPV_REST( $this->store, $this->settings );
		$this->block    = new Coywolf_CPV_Block( $this->store, $this->settings );
		$this->admin    = new Coywolf_CPV_Admin( $this->store, $this->index, $this->settings );
	}

	/**
	 * Activation: create tables, seed settings, grant the capability, and
	 * index existing content once.
	 */
	public static function on_activate() {
		Coywolf_CPV_Store::create_tables();
		Coywolf_CPV_Index::create_tables();

		if ( false === get_option( Coywolf_CPV_Settings::OPTION, false ) ) {
			add_option( Coywolf_CPV_Settings::OPTION, Coywolf_CPV_Settings::defaults() );
		}

		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}

		$index = new Coywolf_CPV_Index( new Coywolf_CPV_Store() );
		$index->rebuild();

		update_option( 'coywolf_cpv_version', self::VERSION );
	}

	/**
	 * Deactivation: nothing persistent to undo (cleanup happens on uninstall).
	 */
	public static function on_deactivate() {}
}
