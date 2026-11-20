<?php
/**
 * Remove only configuration, credentials and temporary recovery snapshots owned by this plugin.
 *
 * WordPress posts, media, product data, and third-party SEO fields are retained.
 *
 * @package BCMCPAbilities
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete this plugin's data from the current site.
 *
 * @return void
 */
function bc_mcp_abilities_uninstall_site() {
	global $wpdb;

	foreach ( array(
		'bc_mcp_abilities_enabled_writes',
		'bc_mcp_abilities_disabled_reads',
		'bc_mcp_abilities_activity',
		'bc_mcp_standalone_enabled',
		'bc_mcp_oauth_enabled',
		'bc_mcp_oauth_registered_clients',
		'bc_mcp_oauth_rewrite_version',
		'bc_mcp_oauth_debug_enabled',
		'bc_mcp_oauth_debug_events',
	) as $option ) {
		delete_option( $option );
	}

	wp_clear_scheduled_hook( 'bc_mcp_prune_activity' );
	wp_unschedule_hook( 'bc_mcp_oauth_remove_lock' );

	// Authorization codes, access/refresh tokens, rate limits, PageSpeed
	// cache and single-use locks. Sites with a persistent object cache keep
	// transients outside the database; those expire on their own.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time prefix cleanup on uninstall.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bc_mcp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bc_mcp_' ) . '%',
			$wpdb->esc_like( 'bc_mcp_used_' ) . '%'
		)
	);

	// Per-site OAuth grants stored as user options.
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'bc_mcp_oauth_grants', '', true );

	delete_post_meta_by_key( '_bc_mcp_abilities_elementor_undo' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $bc_mcp_site_id ) {
		switch_to_blog( $bc_mcp_site_id );
		bc_mcp_abilities_uninstall_site();
		restore_current_blog();
	}
} else {
	bc_mcp_abilities_uninstall_site();
}
