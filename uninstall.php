<?php
/**
 * Remove only configuration and temporary recovery snapshots owned by this plugin.
 *
 * WordPress posts, media, product data, and third-party SEO fields are retained.
 *
 * @package BCMCPAbilities
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'bc_mcp_abilities_enabled_writes' );
delete_option( 'bc_mcp_standalone_enabled' );
delete_option( 'bc_mcp_oauth_enabled' );
delete_option( 'bc_mcp_oauth_registered_clients' );
delete_option( 'bc_mcp_oauth_rewrite_version' );
delete_option( 'bc_mcp_oauth_debug_enabled' );
delete_option( 'bc_mcp_oauth_debug_events' );
delete_post_meta_by_key( '_bc_mcp_abilities_elementor_undo' );
