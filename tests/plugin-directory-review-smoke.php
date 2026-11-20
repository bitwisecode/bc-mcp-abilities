<?php
/**
 * WordPress.org reviewer feedback regression checks on disposable WordPress.
 *
 * @package BCMCPAbilities
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		WP_CLI::error( $message );
	}
};

$assert( defined( 'WP_DEBUG' ) && WP_DEBUG, 'Clean-install plugin smoke tests must run with WP_DEBUG enabled.' );

$plugin_dir = dirname( __DIR__ );
$readme     = file_get_contents( $plugin_dir . '/readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file for a disposable CI check.
$assert( is_string( $readme ) && '' !== $readme, 'WordPress.org readme missing.' );

foreach (
	array(
		'Contributors: bitwisecode',
		'== Privacy & External Services ==',
		'https://developers.google.com/terms',
		'https://policies.google.com/privacy',
		'https://openai.com/policies/terms-of-use/',
		'https://openai.com/policies/privacy-policy/',
		'https://www.anthropic.com/legal/consumer-terms',
		'https://www.anthropic.com/legal/privacy',
		'that the user is authorized to access',
		'No other images are imported automatically.',
	) as $required
) {
	$assert( false !== strpos( $readme, $required ), 'Required public documentation is missing: ' . $required );
}

$header = get_file_data( $plugin_dir . '/bc-mcp-abilities.php', array( 'Version' => 'Version' ) );
$assert( isset( $header['Version'] ) && BC_MCP_ABILITIES_VERSION === $header['Version'], 'Plugin version header and constant must agree.' );
$assert( false !== strpos( $readme, 'Stable tag: ' . $header['Version'] ), 'Readme stable tag and plugin header must agree.' );
$assert( '1.0.0' === $header['Version'], 'The WordPress.org submission build must declare stable version 1.0.0.' );
$assert( false !== strpos( $readme, '= 1.0.0 =' ), 'First public changelog must match version 1.0.0.' );

BC_MCP_Abilities_Admin::register_setting();
BC_MCP_Abilities_Connections::register_settings();
$settings = get_registered_settings();
foreach (
	array(
		'bc_mcp_abilities_enabled_writes',
		'bc_mcp_abilities_disabled_reads',
		'bc_mcp_standalone_enabled',
		'bc_mcp_oauth_enabled',
		'bc_mcp_oauth_debug_enabled',
	) as $option
) {
	$assert( ! empty( $settings[ $option ]['sanitize_callback'] ), 'Registered setting missing a sanitize callback: ' . $option );
}

$assert( array( 'create-draft' ) === BC_MCP_Abilities_Admin::sanitize( array( 'create-draft', array( 'nested' ), 'invalid-action' ) ), 'Write allowlist sanitizer must reject nested and unknown values.' );
$assert( array( 'site-info' ) === BC_MCP_Abilities_Admin::sanitize_disabled_reads( array( 'site-info', array( 'nested' ), 'invalid-action' ) ), 'Read denylist sanitizer must reject nested and unknown values.' );
$assert( '0' === BC_MCP_Abilities_Connections::sanitize_toggle( array( '1' ) ), 'Connection flags must reject array input without conversion warnings.' );

$js = file_get_contents( $plugin_dir . '/assets/admin.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled file for a disposable CI check.
$assert( is_string( $js ) && false !== strpos( $js, '(function () {' ), 'Admin JavaScript must not create an unrestricted global namespace.' );

WP_CLI::success( 'Reviewer-derived prefix, disclosure, version, settings sanitation and WP_DEBUG regression gates passed.' );
