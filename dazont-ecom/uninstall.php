<?php
/**
 * Runs when WordPress DELETES the plugin — never on deactivation.
 *
 * It erases nothing unless the box in Settings → Modules → Database cleanup
 * was ticked. Deleting a plugin is not the same statement as "throw away the
 * keyword sets I spent hours importing", so the default is to leave everything
 * in place and let a reinstall find it again.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-cleanup.php';

if ( ! get_option( DZE_Cleanup::OPT_ON_UNINSTALL ) ) {
	return;
}

foreach ( DZE_Cleanup::all_ids() as $id ) {
	DZE_Cleanup::purge( $id );
}
