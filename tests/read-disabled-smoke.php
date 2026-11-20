<?php
/**
 * Run in a NEW WordPress process after disabling list-taxonomies in the DB.
 *
 * @package BCMCPAbilities
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$disabled = get_option( BC_MCP_Abilities_Admin::READ_DISABLED_OPTION, array() );
if ( ! is_array( $disabled ) || ! in_array( 'list-taxonomies', $disabled, true ) ) {
	WP_CLI::error( 'The read ability must be disabled in a previous WordPress process.' );
}
if ( wp_get_ability( 'bc-mcp-abilities/list-taxonomies' ) ) {
	WP_CLI::error( 'Hidden read ability was still registered and discoverable.' );
}
if ( ! isset( BC_MCP_Abilities::read_catalog()['list-taxonomies'] ) ) {
	WP_CLI::error( 'Hidden ability is not present in the re-enable catalog.' );
}
if ( BC_MCP_Abilities::can_read( 'list-taxonomies' ) ) {
	WP_CLI::error( 'Denied read ability still passes direct permission check.' );
}
if ( ! wp_get_ability( 'bc-mcp-abilities/site-info' ) ) {
	WP_CLI::error( 'Unrelated read ability should remain available.' );
}
WP_CLI::success( 'Read exposure denylist hides exactly the selected ability on a fresh WordPress request.' );
