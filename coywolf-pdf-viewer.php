<?php
/**
 * Plugin Name:       Coywolf PDF Viewer
 * Plugin URI:        https://coywolf.com/notes/coywolf-pdf-viewer/
 * Description:       Embed and view PDFs on posts and pages with a fast, self-hosted viewer. Manage PDFs from the Media Library or external URLs and embed them with the Coywolf PDF block.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Coywolf
 * Author URI:        https://coywolf.com/jon-henshaw/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coywolf-pdf-viewer
 * Update URI:        https://github.com/coywolf-llc/coywolf-pdf-viewer
 *
 * @package CoywolfPDFViewer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COYWOLF_CPV_FILE', __FILE__ );
define( 'COYWOLF_CPV_PATH', plugin_dir_path( __FILE__ ) );
define( 'COYWOLF_CPV_URL', plugin_dir_url( __FILE__ ) );

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
require_once __DIR__ . '/includes/class-github-updater.php';
define( 'COYWOLF_CPV_GITHUB_BUILD', true );
/* wporg-strip:end */
require_once __DIR__ . '/includes/class-cpv-store.php';
require_once __DIR__ . '/includes/class-cpv-index.php';
require_once __DIR__ . '/includes/class-cpv-settings.php';
require_once __DIR__ . '/includes/class-cpv-rest.php';
require_once __DIR__ . '/includes/class-cpv-block.php';
require_once __DIR__ . '/includes/class-cpv-docs.php';
require_once __DIR__ . '/includes/class-cpv-list-table.php';
require_once __DIR__ . '/includes/class-cpv-admin.php';
require_once __DIR__ . '/includes/class-coywolf-pdf-viewer.php';

register_activation_hook( __FILE__, array( 'Coywolf_PDF_Viewer', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'Coywolf_PDF_Viewer', 'on_deactivate' ) );

Coywolf_PDF_Viewer::instance();

/* wporg-strip:start — GitHub self-updater */
( new Coywolf_CPV_GitHub_Updater( __FILE__, Coywolf_PDF_Viewer::VERSION ) )->init();
/* wporg-strip:end */
