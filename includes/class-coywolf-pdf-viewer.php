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
	const VERSION = '1.0.9';

	/**
	 * Capability gating the admin screens, granted to administrators.
	 */
	const CAPABILITY = 'coywolf_cpv_manage';

	/**
	 * WP-Cron hook that drains the one-time page-ratio backfill in the
	 * background, and the option recording that it has finished.
	 */
	const BACKFILL_HOOK        = 'coywolf_cpv_backfill_ratios';
	const BACKFILL_DONE_OPTION = 'coywolf_cpv_ratios_backfilled';

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

		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( self::BACKFILL_HOOK, array( $this, 'run_ratio_backfill' ) );
	}

	/**
	 * One-shot per-version upgrade routines (admin only, never on the front
	 * end). Currently: backfill detected page ratios for PDFs saved before
	 * ratio detection existed, so auto-height placeholders match the page.
	 */
	public function maybe_upgrade() {
		$stored = (string) get_option( 'coywolf_cpv_version' );
		if ( self::VERSION === $stored ) {
			return;
		}
		// Ratio backfill for pre-detection rows runs ONCE, in the background
		// via WP-Cron — never synchronously here, since external PDFs each
		// cost a blocking fetch and a large library could stall this request.
		if ( ! get_option( self::BACKFILL_DONE_OPTION ) && ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
			wp_schedule_single_event( time() + 30, self::BACKFILL_HOOK );
		}

		// 1.0.4: the click-to-load overlay default moved from 25% to 75%.
		// Carry along installs still holding the old seeded default (a
		// deliberate non-25 choice is left alone).
		if ( $stored && version_compare( $stored, '1.0.4', '<' ) ) {
			$settings = get_option( Coywolf_CPV_Settings::OPTION );
			if ( is_array( $settings ) && isset( $settings['overlay_opacity'] ) && 25 === (int) $settings['overlay_opacity'] ) {
				$settings['overlay_opacity'] = 75;
				update_option( Coywolf_CPV_Settings::OPTION, $settings );
			}
		}

		update_option( 'coywolf_cpv_version', self::VERSION, false );
	}

	/**
	 * WP-Cron: drain one bounded batch of the page-ratio backfill, then either
	 * reschedule (rows remain) or record completion so it never runs again.
	 */
	public function run_ratio_backfill() {
		if ( $this->store->backfill_ratios() ) {
			if ( ! wp_next_scheduled( self::BACKFILL_HOOK ) ) {
				wp_schedule_single_event( time() + 60, self::BACKFILL_HOOK );
			}
			return;
		}
		update_option( self::BACKFILL_DONE_OPTION, 1, false );
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

		update_option( 'coywolf_cpv_version', self::VERSION, false );
	}

	/**
	 * Deactivation: nothing persistent to undo (cleanup happens on uninstall).
	 */
	public static function on_deactivate() {}
}
