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
delete_post_meta_by_key( '_bc_mcp_abilities_elementor_undo' );
