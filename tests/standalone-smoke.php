<?php
/**
 * Non-destructive standalone MCP and OAuth contract tests.
 *
 * Run on a disposable installation: wp eval-file tests/standalone-smoke.php
 *
 * @package BCMCPAbilities
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

$expect = static function ( $valid, $description ) {
	if ( ! $valid ) {
		WP_CLI::error( $description );
	}
};

$expect( class_exists( 'BC_MCP_Abilities_Server' ), 'Standalone MCP server class is missing.' );
$expect( class_exists( 'BC_MCP_Abilities_OAuth' ), 'Standalone OAuth class is missing.' );
$expect( ! BC_MCP_Abilities_Server::enabled(), 'MCP must be disabled by default on new installs.' );
$expect( ! BC_MCP_Abilities_OAuth::enabled(), 'OAuth must be disabled by default on new installs.' );

$endpoint = BC_MCP_Abilities_Server::endpoint();
$expect( false !== strpos( $endpoint, '/wp-json/bc-mcp/v1/server' ), 'Standalone endpoint is incorrect.' );
$expect( false !== strpos( BC_MCP_Abilities_OAuth::issuer(), '/bc-mcp' ), 'Standalone OAuth issuer is incorrect.' );
$metadata = BC_MCP_Abilities_OAuth::authorization_metadata();
$expect( $metadata['issuer'] === BC_MCP_Abilities_OAuth::issuer(), 'OAuth issuer mismatch.' );
$expect( 'S256' === $metadata['code_challenge_methods_supported'][0], 'Strong PKCE must be advertised.' );
$expect( 'none' === $metadata['token_endpoint_auth_methods_supported'][0], 'OAuth public client method mismatch.' );
$expect( ! empty( $metadata['registration_endpoint'] ), 'Dynamic client registration endpoint missing.' );

$allow = array(
	'https://chatgpt.com/connector/oauth/testcallbackid123',
	'https://chatgpt.com/connector_platform_oauth_redirect',
);
foreach ( $allow as $uri ) {
	$expect( BC_MCP_Abilities_OAuth::allowed_redirect( $uri ), 'Expected safe OAuth callback rejected.' );
}
$block = array(
	'http://chatgpt.com/connector/oauth/testcallbackid123',
	'https://chatgpt.com.evil.example/connector/oauth/testcallbackid123',
	'https://evil.example/connector/oauth/testcallbackid123',
	'https://chatgpt.com/connector/oauth/testcallbackid123?next=https://evil.example',
	'https://chatgpt.com/connector/oauth/../../evil',
	'http://claude.ai/api/mcp/auth_callback',
	'https://claude.ai.evil.example/api/mcp/auth_callback',
	'https://claude.ai/api/mcp/auth_callback/extra',
	'https://claude.ai/api/mcp/auth_callback?next=https://evil.example',
	'https://claude.ai/api/mcp/auth_callback#fragment',
	'https://claude.ai:443/api/mcp/auth_callback',
	'https://claude.ai@evil.example/api/mcp/auth_callback',
	'https://evil.example/api/mcp/auth_callback',
);
foreach ( $block as $uri ) {
	$expect( ! BC_MCP_Abilities_OAuth::allowed_redirect( $uri ), 'Unsafe callback was accepted.' );
}

$canonical = BC_MCP_Abilities_Server::endpoint();
$expect( BC_MCP_Abilities_OAuth::valid_token_resource( $canonical, $canonical ), 'Matching resource must be permitted on token exchange.' );
$expect( BC_MCP_Abilities_OAuth::valid_token_resource( '', $canonical ), 'Omitted token resource must retain the authorized audience.' );
$expect( ! BC_MCP_Abilities_OAuth::valid_token_resource( 'https://evil.example/mcp', $canonical ), 'Token exchange must not switch resources.' );
$expect( ! BC_MCP_Abilities_OAuth::valid_token_resource( '', 'https://evil.example/mcp' ), 'Original authorization must be restricted to this MCP server.' );

$verifier  = str_repeat( 'v', 64 );
$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
$expect( BC_MCP_Abilities_OAuth::pkce_valid( $verifier, $challenge ), 'Correct OAuth PKCE S256 verifier failed.' );
$expect( ! BC_MCP_Abilities_OAuth::pkce_valid( str_repeat( 'x', 64 ), $challenge ), 'Incorrect OAuth PKCE verifier accepted.' );
$expect( ! BC_MCP_Abilities_OAuth::pkce_valid( 'short', $challenge ), 'Short OAuth PKCE verifier accepted.' );

$server = rest_get_server();
$routes = $server->get_routes();
$expect( isset( $routes['/bc-mcp/v1/server'] ), 'Native WordPress MCP REST route did not register.' );
$expect( isset( $routes['/bc-mcp/v1/oauth/resource'] ), 'OAuth protected-resource REST route did not register.' );
$rewrite_version = get_option( 'bc_mcp_oauth_rewrite_version', '0' );
BC_MCP_Abilities_OAuth::activate();
$expect( BC_MCP_Abilities_OAuth::REWRITE_VERSION === (string) get_option( 'bc_mcp_oauth_rewrite_version', '0' ), 'Activation did not refresh OAuth rewrite version.' );
global $wp_rewrite;
$registered = array_keys( (array) $wp_rewrite->extra_rules_top );
$found = false;
foreach ( $registered as $rule ) {
	if ( false !== strpos( $rule, 'oauth-protected-resource/wp-json/bc-mcp/v1/server' ) ) {
		$found = true;
		break;
	}
}
$expect( $found, 'OAuth well-known protected-resource rewrite was not registered.' );


$request = new WP_REST_Request( 'POST', '/bc-mcp/v1/server' );
$request->set_body(
	wp_json_encode(
		array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(),
		)
	)
);
$request->set_header( 'Content-Type', 'application/json' );
$response = BC_MCP_Abilities_Server::respond( $request );
$expect( 200 === $response->get_status(), 'Native MCP initialize failed.' );
$expect( 'bc-mcp-abilities' === $response->get_data()['result']['serverInfo']['name'], 'Unexpected MCP server name.' );

$request->set_body( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => array() ) ) );
$response = BC_MCP_Abilities_Server::respond( $request );
$tools    = $response->get_data()['result']['tools'];
$expect( 3 === count( $tools ), 'Expected three MCP tools with layered ability discovery.' );

WP_CLI::success( 'Standalone native MCP, OAuth metadata, callback allowlist and PKCE smoke tests passed.' );
