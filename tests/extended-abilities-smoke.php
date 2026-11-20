<?php
/**
 * Functional tests for custom content, Elementor and diagnostics abilities on disposable WordPress.
 *
 * Run only on local wp-env: wp eval-file tests/extended-abilities-smoke.php
 *
 * @package BCMCPAbilities
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$assert = static function ( $valid, $description ) {
	if ( ! $valid ) {
		WP_CLI::error( $description );
	}
};

$original_user = get_current_user_id();
$original_opt  = get_option( BC_MCP_Abilities_Admin::OPTION, array() );

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => array( 'ID' ),
	)
);
$assert( ! empty( $admins ), 'An administrator is required for this test.' );
wp_set_current_user( $admins[0]->ID );

register_post_type(
	'bc_test_entry',
	array(
		'label'        => 'BC Test Entry',
		'public'       => true,
		'show_ui'      => true,
		'show_in_rest' => true,
		'supports'     => array( 'title', 'editor', 'revisions' ),
	)
);

$slugs = array(
	'content-types',
	'search-custom-content',
	'get-custom-content',
	'create-custom-draft',
	'update-custom-content',
	'elementor-validate-json',
	'elementor-export-json',
	'elementor-create-document',
	'elementor-update-settings',
	'elementor-add-element',
	'elementor-delete-element',
	'elementor-move-element',
	'elementor-regenerate-css',
	'pagespeed-audit',
);
foreach ( $slugs as $slug ) {
	$assert( wp_get_ability( 'bc-mcp-abilities/' . $slug ), 'Missing extended ability: ' . $slug );
}

$assert( true === wp_get_ability( 'bc-mcp-abilities/pagespeed-audit' )->check_permissions( array() ), 'Admin should be able to run PageSpeed audits.' );
$assert( is_wp_error( BC_MCP_Abilities_Performance::audit( array( 'path' => '//evil.com' ) ) ), 'External URL paths must be denied.' );
$assert( is_wp_error( BC_MCP_Abilities_Performance::audit( array( 'path' => '/../../secret' ) ) ), 'Traversal paths must be denied.' );
$assert( is_wp_error( BC_MCP_Abilities_Performance::audit( array( 'path' => '/', 'categories' => array( 'other' ) ) ) ), 'Unknown categories must be denied.' );

$types = wp_get_ability( 'bc-mcp-abilities/content-types' )->execute( array() );
$assert( ! is_wp_error( $types ), 'Content types read failed.' );
$names = array_column( $types['data']['types'], 'post_type' );
$assert( in_array( 'bc_test_entry', $names, true ), 'Custom post type not discoverable.' );
$assert( ! in_array( 'shop_order', $names, true ), 'WooCommerce orders must not be handled as ordinary posts.' );

$fixture = array(
	'content' => array(
		array(
			'id'       => 'container1',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(
				array(
					'id'         => 'widget001',
					'elType'     => 'widget',
					'widgetType' => 'heading',
					'settings'   => array( 'title' => 'Testing' ),
					'elements'   => array(),
				),
			),
		),
	),
);
$validated = wp_get_ability( 'bc-mcp-abilities/elementor-validate-json' )->execute(
	array( 'template_json' => wp_json_encode( $fixture ) )
);
$assert( ! is_wp_error( $validated ) && 2 === $validated['data']['element_count'], 'Safe Elementor JSON failed validation.' );

$fixture['content'][0]['elements'][0]['id'] = 'container1';
$duplicate = BC_MCP_Abilities_Elementor_Agent::validate_json( array( 'template_json' => wp_json_encode( $fixture ) ) );
$assert( is_wp_error( $duplicate ), 'Duplicate Elementor node ID was not rejected.' );

$denied_before = wp_get_ability( 'bc-mcp-abilities/create-custom-draft' )->check_permissions( array() );
$assert( true !== $denied_before, 'Extended write abilities must be disabled by default.' );

// Enable exactly two draft-content writes for this disposable test site.
update_option( BC_MCP_Abilities_Admin::OPTION, array( 'create-custom-draft', 'update-custom-content' ) );
$created = wp_get_ability( 'bc-mcp-abilities/create-custom-draft' )->execute(
	array(
		'post_type' => 'bc_test_entry',
		'title'     => 'BC Test Draft',
		'content'   => 'Temporary test content.',
	)
);
$assert( ! is_wp_error( $created ) && ! empty( $created['data']['post_id'] ), 'Custom draft creation failed.' );
$id = $created['data']['post_id'];
$assert( 'draft' === get_post_status( $id ), 'Custom post type creation must never publish.' );

$detail = wp_get_ability( 'bc-mcp-abilities/get-custom-content' )->execute( array( 'post_id' => $id ) );
$assert( ! is_wp_error( $detail ) && ! empty( $detail['data']['revision'] ), 'Custom content read failed.' );

$updated = wp_get_ability( 'bc-mcp-abilities/update-custom-content' )->execute(
	array(
		'post_id'  => $id,
		'revision' => $detail['data']['revision'],
		'title'    => 'BC Updated Draft',
	)
);
$assert( ! is_wp_error( $updated ) && 'BC Updated Draft' === get_the_title( $id ), 'Custom content update failed.' );

$conflict = wp_get_ability( 'bc-mcp-abilities/update-custom-content' )->execute(
	array(
		'post_id'  => $id,
		'revision' => $detail['data']['revision'],
		'title'    => 'Stale update must fail',
	)
);
$assert( is_wp_error( $conflict ), 'Stale custom content edits were not rejected.' );

// This is a disposable CI database; leave no test content or opt-ins behind.
wp_delete_post( $id, true );
unregister_post_type( 'bc_test_entry' );
update_option( BC_MCP_Abilities_Admin::OPTION, $original_opt );
wp_set_current_user( $original_user );

WP_CLI::success( 'Extended abilities: 13 abilities, custom post type draft/update protections and Elementor JSON validation passed.' );
