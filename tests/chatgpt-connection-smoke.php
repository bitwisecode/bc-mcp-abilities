<?php
/**
 * Exercise ChatGPT's MCP preflight and OAuth challenge against real REST dispatch.
 *
 * Run only on a disposable WP installation: wp eval-file tests/chatgpt-connection-smoke.php
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

$previous_https = isset( $_SERVER['HTTPS'] ) ? $_SERVER['HTTPS'] : null;
$previous_mcp   = get_option( BC_MCP_Abilities_Server::OPTION, '0' );
$previous_oauth = get_option( BC_MCP_Abilities_OAuth::OPTION, '0' );
$previous_user  = get_current_user_id();

$_SERVER['HTTPS'] = 'on';
update_option( BC_MCP_Abilities_Server::OPTION, '1' );
update_option( BC_MCP_Abilities_OAuth::OPTION, '1' );
wp_set_current_user( 0 );

$call = static function ( $method, $id, $params = array() ) {
	$request = new WP_REST_Request( 'POST', '/bc-mcp/v1/server' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body(
		wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => $method,
				'params'  => $params,
			)
		)
	);
	return rest_do_request( $request );
};

$assert( BC_MCP_Abilities_OAuth::enabled(), 'Test OAuth setup failed.' );

$initialize = $call( 'initialize', 1 );
$assert( 200 === $initialize->get_status(), 'ChatGPT must initialize MCP without prior WordPress login.' );
$assert( 'bc-mcp-abilities' === $initialize->get_data()['result']['serverInfo']['name'], 'Unexpected MCP server name.' );

$list = $call( 'tools/list', 2 );
$assert( 200 === $list->get_status(), 'ChatGPT must discover tool descriptors before OAuth linking.' );
$tools = $list->get_data()['result']['tools'];
$assert( 3 === count( $tools ), 'Expected three MCP tools.' );
$assert( 'bc-list-abilities' === $tools[0]['name'], 'Expected ability discovery tool.' );
foreach ( $tools as $tool ) {
	$assert( ! empty( $tool['securitySchemes'][0]['type'] ) && 'oauth2' === $tool['securitySchemes'][0]['type'], 'MCP tool must advertise OAuth.' );
	$assert( in_array( 'mcp', $tool['securitySchemes'][0]['scopes'], true ), 'MCP tool is missing its scope.' );
}

$unauthorized = $call( 'tools/call', 3, array( 'name' => 'bc-list-abilities', 'arguments' => (object) array() ) );
$assert( 401 === $unauthorized->get_status(), 'WordPress ability execution must reject anonymous requests.' );

$invalid_method = $call( 'unexpected-activity', 4 );
$assert( 401 === $invalid_method->get_status(), 'Unknown methods must not bypass authorization.' );

$unknown = $call( 'unexpected-activity', 5 );
$assert( 401 === $unknown->get_status(), 'Unknown methods must remain OAuth-protected.' );

update_option( BC_MCP_Abilities_Server::OPTION, $previous_mcp );
update_option( BC_MCP_Abilities_OAuth::OPTION, $previous_oauth );
if ( null === $previous_https ) {
	unset( $_SERVER['HTTPS'] );
} else {
	$_SERVER['HTTPS'] = $previous_https;
}
wp_set_current_user( $previous_user );

WP_CLI::success( 'ChatGPT MCP preflight is public; protected tools remain OAuth-only.' );
