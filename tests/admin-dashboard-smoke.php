<?php
/**
 * Basic admin dashboard integration checks on disposable WordPress.
 *
 * @package BCMCPAbilities
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$assert = static function ( $ok, $message ) {
	if ( ! $ok ) {
		WP_CLI::error( $message );
	}
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'ID' ) ) );
$assert( ! empty( $admin ), 'An administrator account is required.' );
$previous_user = get_current_user_id();
$previous_writes = get_option( BC_MCP_Abilities_Admin::OPTION, array() );
$previous_server = get_option( BC_MCP_Abilities_Server::OPTION, '0' );
$previous_oauth = get_option( BC_MCP_Abilities_OAuth::OPTION, '0' );
$previous_debug = get_option( 'bc_mcp_oauth_debug_enabled', '0' );

wp_set_current_user( $admin[0]->ID );
update_option( BC_MCP_Abilities_Admin::OPTION, array( 'create-draft' ) );

ob_start();
BC_MCP_Abilities_Admin::render();
$markup = ob_get_clean();

foreach ( array(
	'id="bc-mcp-admin"',
	'id="bc-panel-overview"',
	'id="bc-panel-connections"',
	'id="bc-panel-abilities"',
	'id="bc-panel-diagnostics"',
	'id="bc-panel-claude"',
	'name="bc_mcp_abilities_enabled_writes[]"',
	'name="bc_mcp_standalone_enabled"',
	'name="bc_mcp_oauth_enabled"',
	'name="bc_mcp_oauth_debug_enabled"',
	'checked=',
) as $expected ) {
	$assert( false !== strpos( $markup, $expected ), 'Admin dashboard markup missing: ' . $expected );
}

$assert( 1 === substr_count( $markup, 'id="bc-mcp-admin"' ), 'Dashboard rendered twice.' );
$assert( isset( BC_MCP_Abilities_Admin::writable_abilities()['create-draft'] ), 'Original ability registration missing.' );

update_option( BC_MCP_Abilities_Admin::OPTION, $previous_writes );
update_option( BC_MCP_Abilities_Server::OPTION, $previous_server );
update_option( BC_MCP_Abilities_OAuth::OPTION, $previous_oauth );
update_option( 'bc_mcp_oauth_debug_enabled', $previous_debug );
wp_set_current_user( $previous_user );

WP_CLI::success( 'Dashboard sections and Settings API forms render without changing existing options.' );
