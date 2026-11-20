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

$unauthorized = $call(
	'tools/call',
	3,
	array(
		'name'      => 'bc-list-abilities',
		'arguments' => (object) array(),
	)
);
$assert( 401 === $unauthorized->get_status(), 'WordPress ability execution must reject anonymous requests.' );

$invalid_method = $call( 'unexpected-activity', 4 );
$assert( 401 === $invalid_method->get_status(), 'Unknown methods must not bypass authorization.' );

$unknown = $call( 'unexpected-activity', 5 );
$assert( 401 === $unknown->get_status(), 'Unknown methods must remain OAuth-protected.' );

/*
 * Regression: ChatGPT imports tool input schemas as JSON Schema. An empty PHP
 * array encodes as [] and OpenAI rejects it ("[] is not of type 'object'").
 */
$encoded = json_decode( wp_json_encode( $list->get_data() ), false );
foreach ( $encoded->result->tools as $tool ) {
	$assert( is_object( $tool->inputSchema ) && 'object' === $tool->inputSchema->type, 'Tool schema must be a JSON object: ' . $tool->name ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP wire format.
	$assert( is_object( $tool->inputSchema->properties ), 'inputSchema.properties must encode as a JSON object, not []: ' . $tool->name ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MCP wire format.
}
$assert( false !== strpos( wp_json_encode( $list->get_data() ), '"properties":{}' ), 'bc-list-abilities must advertise an empty properties object.' );

// Protocol version negotiation follows the MCP lifecycle rules.
$older = $call( 'initialize', 6, array( 'protocolVersion' => '2025-06-18' ) );
$assert( '2025-06-18' === $older->get_data()['result']['protocolVersion'], 'A supported client protocol version must be echoed.' );
$future = $call( 'initialize', 7, array( 'protocolVersion' => '2099-01-01' ) );
$assert( '2025-11-25' === $future->get_data()['result']['protocolVersion'], 'Unsupported versions must receive the latest supported version.' );

// Notifications are accepted without a JSON-RPC response body.
$notice = new WP_REST_Request( 'POST', '/bc-mcp/v1/server' );
$notice->set_header( 'Content-Type', 'application/json' );
$notice->set_body( '{"jsonrpc":"2.0","method":"notifications/initialized"}' );
$notice = rest_do_request( $notice );
$assert( 202 === $notice->get_status() && null === $notice->get_data(), 'notifications/initialized must return 202 without a body.' );

// Bearer-token outcomes, against real REST dispatch.
$editor = wp_insert_user(
	array(
		'user_login' => 'bc_mcp_smoke_editor_' . wp_rand(),
		'user_pass'  => wp_generate_password(),
		'role'       => 'editor',
	)
);
$reader = wp_insert_user(
	array(
		'user_login' => 'bc_mcp_smoke_reader_' . wp_rand(),
		'user_pass'  => wp_generate_password(),
		'role'       => 'subscriber',
	)
);
$assert( ! is_wp_error( $editor ) && ! is_wp_error( $reader ), 'Could not create smoke-test users.' );
$mint     = static function ( $user_id, $resource, $scope ) {
	$token = bin2hex( random_bytes( 32 ) );
	set_transient(
		'bc_mcp_access_' . hash( 'sha256', $token ),
		array(
			'user_id'  => $user_id,
			'resource' => $resource,
			'client'   => 'bc_smoke',
			'scope'    => $scope,
		),
		300
	);
	return $token;
};
$authed   = static function ( $token, $method, $params = array() ) {
	$request = new WP_REST_Request( 'POST', '/bc-mcp/v1/server' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_header( 'Authorization', 'Bearer ' . $token );
	$request->set_body(
		wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 9,
				'method'  => $method,
				'params'  => $params,
			)
		)
	);
	return rest_do_request( $request );
};
$endpoint = BC_MCP_Abilities_Server::endpoint();
$valid    = $mint( $editor, $endpoint, 'mcp offline_access' );
$listed   = $authed(
	$valid,
	'tools/call',
	array(
		'name'      => 'bc-list-abilities',
		'arguments' => (object) array(),
	)
);
$assert( 200 === $listed->get_status() && false === $listed->get_data()['result']['isError'], 'A valid Bearer token must run bc-list-abilities.' );
$payload = json_decode( $listed->get_data()['result']['content'][0]['text'], true );
$assert( ! empty( $payload['abilities'] ), 'The authorized editor must see permitted abilities.' );
$assert( get_current_user_id() === $editor, 'The Bearer token must set the WordPress current user.' );
$names = wp_list_pluck( $payload['abilities'], 'name' );
$assert( ! in_array( 'bc-mcp-abilities/site-info', $names, true ), 'Administrator-only abilities must stay hidden from editors.' );

$assert( 401 === $authed( str_repeat( 'a', 64 ), 'tools/call', array( 'name' => 'bc-list-abilities' ) )->get_status(), 'Unknown or expired tokens must be rejected.' );
$assert( 401 === $authed( str_repeat( 'a', 64 ), 'tools/list' )->get_status(), 'A presented invalid token must be rejected even for discovery so the client can refresh.' );
$assert( 401 === $authed( 'not-a-token', 'tools/call', array( 'name' => 'bc-list-abilities' ) )->get_status(), 'Malformed tokens must be rejected.' );
$assert( 401 === $authed( $mint( $editor, 'https://evil.example/mcp', 'mcp' ), 'tools/call', array( 'name' => 'bc-list-abilities' ) )->get_status(), 'Tokens for another resource must be rejected.' );
$assert( 403 === $authed( $mint( $editor, $endpoint, 'offline_access' ), 'tools/call', array( 'name' => 'bc-list-abilities' ) )->get_status(), 'Tokens without the mcp scope must be rejected as insufficient_scope.' );
$assert( 401 === $authed( $mint( $reader, $endpoint, 'mcp' ), 'tools/call', array( 'name' => 'bc-list-abilities' ) )->get_status(), 'Users without edit_posts must be rejected.' );
delete_transient( 'bc_mcp_access_' . hash( 'sha256', $valid ) );
$assert( 401 === $authed( $valid, 'tools/call', array( 'name' => 'bc-list-abilities' ) )->get_status(), 'Expired access tokens must be rejected.' );

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $editor );
wp_delete_user( $reader );

update_option( BC_MCP_Abilities_Server::OPTION, $previous_mcp );
update_option( BC_MCP_Abilities_OAuth::OPTION, $previous_oauth );
if ( null === $previous_https ) {
	unset( $_SERVER['HTTPS'] );
} else {
	$_SERVER['HTTPS'] = $previous_https;
}
wp_set_current_user( $previous_user );

WP_CLI::success( 'ChatGPT MCP preflight is public; protected tools remain OAuth-only.' );
