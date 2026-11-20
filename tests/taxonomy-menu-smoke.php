<?php
/**
 * Disposable WordPress integration smoke for taxonomy and menu abilities.
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
$assert( ! empty( $admins ), 'Administrator test account required.' );
$previous_user = get_current_user_id();
$previous_opt  = get_option( BC_MCP_Abilities_Admin::OPTION, array() );
wp_set_current_user( $admins[0]->ID );

$read_slugs = array( 'list-taxonomies', 'list-taxonomy-terms', 'get-post-terms', 'list-menus', 'get-menu' );
$write_slugs = array( 'create-taxonomy-term', 'assign-post-terms', 'create-menu', 'add-menu-item' );
foreach ( array_merge( $read_slugs, $write_slugs ) as $slug ) {
	$assert( wp_get_ability( 'bc-mcp-abilities/' . $slug ), 'Missing ability: ' . $slug );
}
foreach ( $write_slugs as $slug ) {
	$assert( true !== wp_get_ability( 'bc-mcp-abilities/' . $slug )->check_permissions( array() ), 'Write must be off by default: ' . $slug );
}

$taxonomy_list = wp_get_ability( 'bc-mcp-abilities/list-taxonomies' )->execute( array() );
$assert( ! is_wp_error( $taxonomy_list ) && ! empty( $taxonomy_list['data']['taxonomies'] ), 'Taxonomy discovery failed.' );
$names = array_column( $taxonomy_list['data']['taxonomies'], 'slug' );
$assert( in_array( 'category', $names, true ) && in_array( 'post_tag', $names, true ), 'Core taxonomies missing.' );
$assert( is_wp_error( BC_MCP_Abilities_Taxonomies::list_terms( array( 'taxonomy' => 'acf-field-group' ) ) ), 'Private/internal taxonomy must be denied.' );

update_option( BC_MCP_Abilities_Admin::OPTION, $write_slugs );
$post_id = wp_insert_post(
	array(
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'Temporary BC taxonomy and menu smoke item',
		'post_content' => 'Disposable test content.',
	),
	true
);
$assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Test post creation failed.' );

$term = wp_get_ability( 'bc-mcp-abilities/create-taxonomy-term' )->execute(
	array(
		'taxonomy' => 'post_tag',
		'name'     => 'BC MCP temporary term smoke',
	)
);
$assert( ! is_wp_error( $term ) && ! empty( $term['data']['term']['term_id'] ), 'Term creation failed.' );
$term_id = $term['data']['term']['term_id'];

$initial = wp_get_ability( 'bc-mcp-abilities/get-post-terms' )->execute(
	array(
		'post_id'  => $post_id,
		'taxonomy' => 'post_tag',
	)
);
$assert( ! is_wp_error( $initial ) && ! empty( $initial['data']['revision'] ), 'Term revision read failed.' );
$args = array(
	'post_id'           => $post_id,
	'taxonomy'          => 'post_tag',
	'term_ids'          => array( $term_id ),
	'expected_revision' => $initial['data']['revision'],
);
$assigned = wp_get_ability( 'bc-mcp-abilities/assign-post-terms' )->execute( $args );
$assert( ! is_wp_error( $assigned ) && in_array( $term_id, $assigned['data']['term_ids'], true ), 'Term assignment failed.' );
$stale = BC_MCP_Abilities_Taxonomies::assign_post_terms( $args );
$assert( is_wp_error( $stale ), 'Stale taxonomy assignment was not rejected.' );
$bad = BC_MCP_Abilities_Taxonomies::assign_post_terms(
	array(
		'post_id'           => $post_id,
		'taxonomy'          => 'post_tag',
		'term_ids'          => array( -5 ),
		'expected_revision' => $assigned['data']['revision'],
	)
);
$assert( is_wp_error( $bad ), 'Invalid term ID should be rejected.' );

$menu = wp_get_ability( 'bc-mcp-abilities/create-menu' )->execute( array( 'name' => 'BC MCP temporary menu smoke' ) );
$assert( ! is_wp_error( $menu ) && ! empty( $menu['data']['menu_id'] ), 'Menu creation failed.' );
$menu_id = $menu['data']['menu_id'];

$added = wp_get_ability( 'bc-mcp-abilities/add-menu-item' )->execute(
	array(
		'menu_id'   => $menu_id,
		'object_id' => $post_id,
		'object'    => 'post',
	)
);
$assert( ! is_wp_error( $added ) && ! empty( $added['data']['item_id'] ), 'Menu item creation failed.' );

$detail = wp_get_ability( 'bc-mcp-abilities/get-menu' )->execute( array( 'menu_id' => $menu_id ) );
$assert( ! is_wp_error( $detail ) && ! empty( $detail['data']['items'] ), 'Menu item read failed.' );

wp_delete_nav_menu( $menu_id );
wp_delete_post( $post_id, true );
wp_delete_term( $term_id, 'post_tag' );
update_option( BC_MCP_Abilities_Admin::OPTION, $previous_opt );
wp_set_current_user( $previous_user );

WP_CLI::success( '9 taxonomy/menu abilities: permissions, term creation, revision checks and classic menu writes passed.' );
