<?php
/**
 * Actual Elementor plugin API integration checks on disposable WordPress only.
 *
 * Requires active Elementor; run: wp eval-file tests/elementor-integration.php
 *
 * @package BCMCPAbilities
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$verify = static function ( $condition, $message ) {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
};

$verify( class_exists( '\Elementor\Plugin' ), 'Elementor is not active.' );

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => array( 'ID' ),
	)
);
$verify( ! empty( $admins ), 'Administrator not found.' );

$old_user   = get_current_user_id();
$old_option = get_option( BC_MCP_Abilities_Admin::OPTION, array() );
wp_set_current_user( $admins[0]->ID );
update_option(
	BC_MCP_Abilities_Admin::OPTION,
	array(
		'elementor-create-document',
		'elementor-add-element',
		'elementor-update-settings',
		'elementor-move-element',
		'elementor-delete-element',
		'elementor-undo',
		'elementor-regenerate-css',
	)
);

$created = wp_get_ability( 'bc-mcp-abilities/elementor-create-document' )->execute(
	array(
		'post_type' => 'page',
		'title'     => 'Temporary BC Elementor Integration',
	)
);
$verify( ! is_wp_error( $created ) && ! empty( $created['data']['post_id'] ), 'Could not create an Elementor draft document.' );
$post_id = $created['data']['post_id'];
$verify( 'draft' === get_post_status( $post_id ), 'Elementor creation must remain draft-only.' );

$container = wp_get_ability( 'bc-mcp-abilities/elementor-add-element' )->execute(
	array(
		'post_id'           => $post_id,
		'expected_revision' => $created['data']['revision'],
		'kind'              => 'container',
		'settings'          => array( 'content_width' => 'full' ),
	)
);
$verify( ! is_wp_error( $container ) && ! empty( $container['data']['element_id'] ), 'Could not add an Elementor root container.' );
$first_container_id = $container['data']['element_id'];

$heading = wp_get_ability( 'bc-mcp-abilities/elementor-add-element' )->execute(
	array(
		'post_id'           => $post_id,
		'expected_revision' => $container['data']['revision'],
		'kind'              => 'heading',
		'parent_id'         => $first_container_id,
		'settings'          => array( 'title' => 'BC First Heading' ),
	)
);
$verify( ! is_wp_error( $heading ) && ! empty( $heading['data']['element_id'] ), 'Could not add an Elementor heading widget.' );
$heading_id = $heading['data']['element_id'];

$edited = wp_get_ability( 'bc-mcp-abilities/elementor-update-settings' )->execute(
	array(
		'post_id'           => $post_id,
		'expected_revision' => $heading['data']['revision'],
		'element_id'        => $heading_id,
		'settings'          => array( 'title' => 'BC Edited Heading', 'align' => 'center' ),
	)
);
$verify( ! is_wp_error( $edited ), 'Could not edit a supported Elementor heading setting.' );

$second_container = wp_get_ability( 'bc-mcp-abilities/elementor-add-element' )->execute(
	array(
		'post_id'           => $post_id,
		'expected_revision' => $edited['data']['revision'],
		'kind'              => 'container',
	)
);
$verify( ! is_wp_error( $second_container ), 'Could not create the second container.' );

$moved = wp_get_ability( 'bc-mcp-abilities/elementor-move-element' )->execute(
	array(
		'post_id'           => $post_id,
		'expected_revision' => $second_container['data']['revision'],
		'element_id'        => $heading_id,
		'new_parent_id'     => $second_container['data']['element_id'],
	)
);
$verify( ! is_wp_error( $moved ), 'Could not move the Elementor heading into a different container.' );

$removed = wp_get_ability( 'bc-mcp-abilities/elementor-delete-element' )->execute(
	array(
		'post_id'           => $post_id,
		'expected_revision' => $moved['data']['revision'],
		'element_id'        => $heading_id,
	)
);
$verify( ! is_wp_error( $removed ) && ! empty( $removed['data']['undo_token'] ), 'Could not delete an Elementor heading with undo.' );

$restored = wp_get_ability( 'bc-mcp-abilities/elementor-undo' )->execute(
	array(
		'post_id'           => $post_id,
		'undo_token'        => $removed['data']['undo_token'],
		'expected_revision' => $removed['data']['revision'],
	)
);
$verify( ! is_wp_error( $restored ), 'Could not undo the deleted Elementor heading.' );

$info = wp_get_ability( 'bc-mcp-abilities/elementor-get-element' )->execute(
	array(
		'post_id'    => $post_id,
		'element_id' => $heading_id,
	)
);
$verify( ! is_wp_error( $info ) && ! empty( $info['success'] ), 'The restored Elementor heading is missing.' );

$css = wp_get_ability( 'bc-mcp-abilities/elementor-regenerate-css' )->execute(
	array( 'post_id' => $post_id )
);
$verify( ! is_wp_error( $css ) && ! empty( $css['data']['regenerated'] ), 'Elementor CSS regeneration failed.' );

wp_delete_post( $post_id, true );
update_option( BC_MCP_Abilities_Admin::OPTION, $old_option );
wp_set_current_user( $old_user );

WP_CLI::success( 'Elementor integration: draft, container, heading, settings, move, delete, undo and CSS passed.' );
