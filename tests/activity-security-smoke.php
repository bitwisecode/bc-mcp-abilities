<?php
/**
 * Disposable WordPress permissions and audit smoke tests.
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

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => array( 'ID' ),
	)
);
$assert( ! empty( $admins ), 'Administrator required.' );

$old_user  = get_current_user_id();
$old_audit = get_option( BC_MCP_Abilities_Audit::OPTION, array() );
$old_reads = get_option( BC_MCP_Abilities_Admin::READ_DISABLED_OPTION, array() );
wp_set_current_user( (int) $admins[0]->ID );

$catalog = BC_MCP_Abilities::read_catalog();
$assert( isset( $catalog['site-info'], $catalog['list-taxonomies'] ), 'Read catalog should include core and new abilities.' );
$filtered = BC_MCP_Abilities_Admin::sanitize_disabled_reads( array( 'site-info', 'create-draft', 'not-real', '' ) );
$assert( array( 'site-info' ) === $filtered, 'Read denylist must include only first-party read slugs.' );
$assert( empty( $old_reads ), 'Disposable test expects no prior disabled reads.' );
$assert( has_action( 'admin_post_bc_mcp_export_activity' ), 'Audit CSV handler not registered.' );
$assert( has_action( 'admin_post_bc_mcp_clear_activity' ), 'Audit clear handler not registered.' );

delete_option( BC_MCP_Abilities_Audit::OPTION );
$info = wp_get_ability( 'bc-mcp-abilities/site-info' )->execute( array() );
$assert( ! is_wp_error( $info ) && ! empty( $info['success'] ), 'Administrator ability execution failed.' );
$events = BC_MCP_Abilities_Audit::recent();
$assert( 1 === count( $events ), 'Execution should create exactly one audit event.' );
$assert( 'site-info' === $events[0]['ability'] && 'read' === $events[0]['kind'] && 'success' === $events[0]['outcome'], 'Audit labels should be correct.' );
$assert( (int) $admins[0]->ID === $events[0]['user_id'], 'Audit should record the WordPress user ID.' );
$encoded = wp_json_encode( get_option( BC_MCP_Abilities_Audit::OPTION ) );
$assert( false === strpos( $encoded, 'wordpress_version' ) && false === strpos( $encoded, 'active_plugins' ), 'Audit persisted response payload fields.' );

$old = array(
	'time'    => time() - ( 8 * DAY_IN_SECONDS ),
	'user_id' => 1,
	'ability' => 'site-info',
	'kind'    => 'read',
	'outcome' => 'success',
);
update_option( BC_MCP_Abilities_Audit::OPTION, array( $old, $events[0] ), false );
$assert( 1 === count( BC_MCP_Abilities_Audit::recent() ), 'Expired events should not be returned.' );
BC_MCP_Abilities_Audit::prune();
$assert( 1 === count( get_option( BC_MCP_Abilities_Audit::OPTION, array() ) ), 'Expired event was not pruned.' );

wp_set_current_user( 0 );
$assert( false === BC_MCP_Abilities::can_read( 'list-taxonomies' ), 'Anonymous user can read.' );
$assert( false === BC_MCP_Abilities::can_write( 'create-menu' ), 'Anonymous user can write.' );

$subscriber = wp_create_user( 'bc_mcp_audit_subscriber_' . wp_rand( 1000, 9999 ), wp_generate_password( 24 ), 'bc-mcp-sub-' . wp_rand( 1000, 9999 ) . '@example.invalid' );
$assert( ! is_wp_error( $subscriber ), 'Temporary subscriber could not be created.' );
$sub_user = get_user_by( 'id', $subscriber );
$sub_user->set_role( 'subscriber' );
wp_set_current_user( $subscriber );
$assert( false === BC_MCP_Abilities::can_read( 'list-taxonomies' ), 'Subscriber can read protected abilities.' );
$assert( false === BC_MCP_Abilities::can_write( 'create-menu' ), 'Subscriber can execute an opted-out write.' );

wp_set_current_user( $old_user );
wp_delete_user( $subscriber );
if ( $old_audit ) {
	update_option( BC_MCP_Abilities_Audit::OPTION, $old_audit, false );
} else {
	delete_option( BC_MCP_Abilities_Audit::OPTION );
}
update_option( BC_MCP_Abilities_Admin::READ_DISABLED_OPTION, $old_reads, false );

WP_CLI::success( 'Audit data minimization, retention, admin handlers, read sanitize and anonymous/subscriber gates passed.' );
