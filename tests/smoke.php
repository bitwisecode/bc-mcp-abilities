<?php
/**
 * Non-destructive WP-CLI smoke tests for a dedicated staging WordPress instance.
 *
 * Run: wp eval-file tests/smoke.php
 *
 * @package BCMCPAbilities
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$read_abilities = array(
	'site-info',
	'list-content',
	'get-content',
	'list-revisions',
	'list-media',
	'get-media',
	'elementor-document',
	'elementor-get-element',
	'check-url',
	'rank-math-read',
	'wc-products',
	'wc-product',
	'wc-order-report',
	'content-types',
	'search-custom-content',
	'get-custom-content',
	'elementor-validate-json',
	'elementor-export-json',
);

$write_abilities = array(
	'create-draft',
	'update-content',
	'restore-revision',
	'update-media-alt',
	'upload-image',
	'rank-math-update',
	'elementor-update-text',
	'elementor-undo',
	'wc-update-stock',
	'create-custom-draft',
	'update-custom-content',
	'elementor-create-document',
	'elementor-update-settings',
	'elementor-add-element',
	'elementor-delete-element',
	'elementor-move-element',
	'elementor-regenerate-css',
);

$all_abilities = array_merge( $read_abilities, $write_abilities );
foreach ( $all_abilities as $slug ) {
	$ability = wp_get_ability( 'bc-mcp-abilities/' . $slug );
	if ( ! $ability ) {
		WP_CLI::error( 'Ability did not register: ' . $slug );
	}
}

$manifest = BC_MCP_Abilities_Claude::manifest();
if (
	'0.3' !== $manifest['manifest_version'] ||
	! isset( $manifest['server']['entry_point'] ) ||
	'server/index.js' !== $manifest['server']['entry_point'] ||
	BC_MCP_Abilities_Claude::endpoint() !== $manifest['user_config']['site_url']['default'] ||
	empty( $manifest['user_config']['application_password']['sensitive'] )
) {
	WP_CLI::error( 'The Claude Desktop extension manifest is incomplete or insecure.' );
}
if ( ! is_readable( dirname( __DIR__ ) . '/claude-extension/server/index.js' ) ) {
	WP_CLI::error( 'The Claude Desktop extension bridge is missing from the plugin.' );
}

$old_user = get_current_user_id();
wp_set_current_user( 0 );
foreach ( $all_abilities as $slug ) {
	$ability = wp_get_ability( 'bc-mcp-abilities/' . $slug );
	if ( true === $ability->check_permissions() ) {
		WP_CLI::error( 'Anonymous user can execute ' . $slug );
	}
}

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => array( 'ID' ),
	)
);
if ( empty( $admins ) ) {
	WP_CLI::error( 'No administrator found in this WordPress test site.' );
}
wp_set_current_user( $admins[0]->ID );
if ( get_option( BC_MCP_Abilities_Admin::OPTION, array() ) ) {
	WP_CLI::error( 'Staging test expects write abilities to be disabled.' );
}
foreach ( $write_abilities as $slug ) {
	$ability = wp_get_ability( 'bc-mcp-abilities/' . $slug );
	if ( true === $ability->check_permissions() ) {
		WP_CLI::error( 'Write ability is enabled before explicit opt-in: ' . $slug );
	}
}

$info = wp_get_ability( 'bc-mcp-abilities/site-info' )->execute( array() );
if ( is_wp_error( $info ) || empty( $info['success'] ) || empty( $info['data']['wordpress_version'] ) ) {
	WP_CLI::error( 'Site information ability execution failed.' );
}
wp_set_current_user( $old_user );
WP_CLI::success( sprintf( '%d abilities registered; anonymous access denied; writes off by default.', count( $all_abilities ) ) );
