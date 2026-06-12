<?php
/**
 * Uninstall cleanup: tables, options, transients, postmeta, and capability.
 *
 * @package CoywolfPDFViewer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Plugin tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'coywolf_cpv_pdfs' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'coywolf_cpv_usage' ) );
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

// Options.
$coywolf_cpv_options = array(
	'coywolf_cpv_settings',
	'coywolf_cpv_version',
);
foreach ( $coywolf_cpv_options as $coywolf_cpv_option ) {
	delete_option( $coywolf_cpv_option );
}

// Transients (including the GitHub updater caches in the GitHub build).
$coywolf_cpv_transients = array(
	'coywolf_cpv_gh_release',
	'coywolf_cpv_gh_release_neg',
	'coywolf_cpv_gh_release_err',
);
foreach ( $coywolf_cpv_transients as $coywolf_cpv_transient ) {
	delete_transient( $coywolf_cpv_transient );
}

// Per-post embedded-PDF meta.
delete_post_meta_by_key( 'coywolf_cpv_pdfs' );

// The management capability, from every role that has it.
$coywolf_cpv_roles = wp_roles();
foreach ( array_keys( $coywolf_cpv_roles->roles ) as $coywolf_cpv_role_slug ) {
	$coywolf_cpv_role = get_role( $coywolf_cpv_role_slug );
	if ( $coywolf_cpv_role && $coywolf_cpv_role->has_cap( 'coywolf_cpv_manage' ) ) {
		$coywolf_cpv_role->remove_cap( 'coywolf_cpv_manage' );
	}
}
