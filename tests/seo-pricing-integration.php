<?php
/**
 * Disposable integrated Yoast SEO / WooCommerce pricing regression tests.
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
$assert( defined( 'WPSEO_VERSION' ), 'Yoast SEO must be activated for this suite.' );
$assert( function_exists( 'wc_get_product' ), 'WooCommerce must be activated for this suite.' );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'ID' ) ) );
$assert( ! empty( $admins ), 'Administrator test user missing.' );
$previous_user = get_current_user_id();
$previous_opt = get_option( BC_MCP_Abilities_Admin::OPTION, array() );
wp_set_current_user( (int) $admins[0]->ID );

foreach ( array( 'yoast-seo-read', 'yoast-seo-update', 'wc-update-prices' ) as $slug ) {
	$assert( wp_get_ability( 'bc-mcp-abilities/' . $slug ), 'Integration ability missing: ' . $slug );
}
$assert( ! BC_MCP_Abilities_Admin::is_enabled( 'yoast-seo-update' ), 'Yoast write must be disabled initially.' );
$assert( ! BC_MCP_Abilities_Admin::is_enabled( 'wc-update-prices' ), 'Price write must be disabled initially.' );
update_option( BC_MCP_Abilities_Admin::OPTION, array( 'yoast-seo-update', 'wc-update-prices' ) );

$post_id = wp_insert_post(
	array(
		'post_type'   => 'post',
		'post_status' => 'draft',
		'post_title'  => 'BC MCP temporary Yoast integration test',
	),
	true
);
$assert( ! is_wp_error( $post_id ) && $post_id > 0, 'Could not create SEO test post.' );
$before = wp_get_ability( 'bc-mcp-abilities/yoast-seo-read' )->execute( array( 'post_id' => $post_id ) );
$assert( ! is_wp_error( $before ) && ! empty( $before['data']['revision'] ), 'Yoast read failed.' );
$seo_input = array(
	'post_id'           => $post_id,
	'expected_revision' => $before['data']['revision'],
	'title'             => 'Temporary SEO test title',
	'description'       => 'Temporary SEO test description',
);
$changed = wp_get_ability( 'bc-mcp-abilities/yoast-seo-update' )->execute( $seo_input );
$assert( ! is_wp_error( $changed ) && 'Temporary SEO test title' === get_post_meta( $post_id, '_yoast_wpseo_title', true ), 'Yoast update failed.' );
$assert( is_wp_error( BC_MCP_Abilities_Release_Integrations::yoast_update( $seo_input ) ), 'Stale SEO metadata revision should fail.' );

$product = new WC_Product_Simple();
$product->set_name( 'BC MCP temporary price test' );
$product->set_status( 'publish' );
$product->set_regular_price( '29.00' );
$product->set_price( '29.00' );
$product_id = $product->save();
$assert( $product_id > 0, 'Could not save temporary WooCommerce product.' );
$summary = wp_get_ability( 'bc-mcp-abilities/wc-product' )->execute( array( 'product_id' => $product_id ) );
$assert( ! is_wp_error( $summary ) && ! empty( $summary['data']['pricing_revision'] ), 'WooCommerce product must expose pricing revision.' );

$price_input = array(
	'product_id'        => $product_id,
	'expected_revision' => $summary['data']['pricing_revision'],
	'regular_price'     => '30.00',
	'sale_price'        => '25.00',
);
$updated = wp_get_ability( 'bc-mcp-abilities/wc-update-prices' )->execute( $price_input );
$assert( ! is_wp_error( $updated ) && ! empty( $updated['success'] ), 'WooCommerce pricing update failed.' );
$after = wc_get_product( $product_id );
$assert( '30' === (string) $after->get_regular_price() && '25' === (string) $after->get_sale_price(), 'WooCommerce stored incorrect prices.' );
$assert( is_wp_error( BC_MCP_Abilities_Release_Integrations::update_prices( $price_input ) ), 'Stale pricing update must fail.' );

$latest = BC_MCP_Abilities_Release_Integrations::pricing_state( $after );
$bad = BC_MCP_Abilities_Release_Integrations::update_prices(
	array(
		'product_id'        => $product_id,
		'expected_revision' => $latest['pricing_revision'],
		'sale_price'        => '35.00',
	)
);
$assert( is_wp_error( $bad ), 'Sale price greater than regular must fail.' );

wp_delete_post( $post_id, true );
wp_delete_post( $product_id, true );
update_option( BC_MCP_Abilities_Admin::OPTION, $previous_opt );
wp_set_current_user( $previous_user );

WP_CLI::success( 'Yoast real metadata edits and WooCommerce regular/sale prices, concurrency and validation passed.' );
