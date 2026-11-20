<?php
/**
 * OAuth login redirect, client lifecycle, single-use locks and text sanitization.
 *
 * Run only on a disposable WP installation: wp eval-file tests/oauth-lifecycle-smoke.php
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
$call   = static function ( $method, ...$args ) {
	$reflection = new ReflectionMethod( 'BC_MCP_Abilities_OAuth', $method );
	$reflection->setAccessible( true );
	return $reflection->invoke( null, ...$args );
};

// 1. The post-login return URL must survive wp-login intact (H1).
$auth = array(
	'client_id'    => 'bc_lifecycle',
	'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect',
	'challenge'    => str_repeat( 'A', 43 ),
	'resource'     => BC_MCP_Abilities_Server::endpoint(),
	'scope'        => 'mcp offline_access',
	'state'        => 'st/a+te=1&x%y',
);
$url  = BC_MCP_Abilities_OAuth::authorization_request_url( $auth );
$assert( 0 === strpos( $url, BC_MCP_Abilities_OAuth::authorize_url() . '?' ), 'Return URL must target the authorize endpoint exactly once.' );
$assert( false === strpos( $url, ' ' ), 'Return URL must be fully encoded.' );
$login = wp_login_url( $url );
parse_str( (string) wp_parse_url( $login, PHP_URL_QUERY ), $login_query );
$assert( isset( $login_query['redirect_to'] ) && $login_query['redirect_to'] === $url, 'wp-login must receive the return URL unchanged.' );
$assert( wp_sanitize_redirect( $url ) === $url, 'wp_safe_redirect must not alter the return URL.' );
parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
foreach ( array(
	'client_id'      => 'client_id',
	'redirect_uri'   => 'redirect_uri',
	'scope'          => 'scope',
	'resource'       => 'resource',
	'state'          => 'state',
	'code_challenge' => 'challenge',
) as $param => $key ) {
	$assert( isset( $query[ $param ] ) && $query[ $param ] === $auth[ $key ], 'Return URL lost or altered ' . $param . '.' );
}

// 2. Client registry lifecycle (H2).
$now     = time();
$clients = array(
	'pending_old' => array(
		'created_at'   => $now - 2 * DAY_IN_SECONDS,
		'last_used_at' => 0,
	),
	'pending_new' => array(
		'created_at'   => $now - 60,
		'last_used_at' => 0,
	),
	'active'      => array(
		'created_at'   => $now - 90 * DAY_IN_SECONDS,
		'last_used_at' => $now - DAY_IN_SECONDS,
	),
	'idle'        => array(
		'created_at'   => $now - 90 * DAY_IN_SECONDS,
		'last_used_at' => $now - 31 * DAY_IN_SECONDS,
	),
	'legacy'      => array( 'created_at' => $now - 3 * DAY_IN_SECONDS ),
	'broken'      => 'not-an-array',
);
$pruned  = BC_MCP_Abilities_OAuth::prune_clients( $clients );
$assert( array( 'pending_new', 'active' ) === array_keys( $pruned ), 'Pruning must drop stale pending, idle, legacy-unused and malformed clients only.' );
$assert( false !== has_action( 'bc_mcp_prune_activity', array( 'BC_MCP_Abilities_OAuth', 'prune_clients' ) ), 'Client pruning must run on the daily cron.' );

// 3. Single-use locks are atomic and age-aware (L1).
$hash = hash( 'sha256', 'lifecycle-' . wp_rand() );
$assert( true === $call( 'consume_lock', $hash ), 'First claim must succeed.' );
$assert( false === $call( 'consume_lock', $hash ), 'Second claim must fail.' );
$assert( 0 <= $call( 'lock_age', $hash ) && 5 > $call( 'lock_age', $hash ), 'Lock age must be measurable.' );
BC_MCP_Abilities_OAuth::remove_lock( 'bc_mcp_used_' . $hash );
$assert( null === $call( 'lock_age', $hash ), 'Removed lock must be gone.' );

// 4. Pending grants are not usable until a token exchange completes.
$user_id = wp_insert_user(
	array(
		'user_login' => 'bc_mcp_lifecycle_' . wp_rand(),
		'user_pass'  => wp_generate_password(),
		'role'       => 'editor',
	)
);
$assert( ! is_wp_error( $user_id ), 'Could not create test user.' );
$grant = $call( 'create_grant', $user_id, 'bc_lifecycle' );
$assert( false === $call( 'grant_valid', $user_id, $grant, 'bc_lifecycle' ), 'Pending grant must not authorize bearer tokens.' );
$assert( true === $call( 'grant_valid', $user_id, $grant, 'bc_lifecycle', true ), 'Pending grant must be redeemable by its code.' );
$assert( false === $call( 'grant_valid', $user_id, $grant, 'another_client', true ), 'Grant must be bound to its client.' );
$call( 'touch_grant', $user_id, $grant );
$assert( true === $call( 'grant_valid', $user_id, $grant, 'bc_lifecycle' ), 'Exchanged grant must authorize.' );
$assert( 1 === count( wp_list_filter( BC_MCP_Abilities_OAuth::all_grants(), array( 'user_id' => $user_id ) ) ), 'Admin list must show the active grant.' );
BC_MCP_Abilities_OAuth::revoke_all();
$assert( array() === BC_MCP_Abilities_OAuth::grants( $user_id ), 'Disconnect all must remove every grant.' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $user_id );

// 5. SEO text keeps template variables (M4).
foreach ( array(
	'%%title%% %%sep%% %%category%%'  => '%%title%% %%sep%% %%category%%',
	'%%date%% | %%sitename%%'         => '%%date%% | %%sitename%%',
	'%title% %sep% %category% %date%' => '%title% %sep% %category% %date%',
	"<b>Bold</b>\n  title\t"          => 'Bold title',
	'<script>x()</script>Safe'        => 'Safe',
	'5 < 6 and 100% off'              => '5 &lt; 6 and 100% off',
) as $raw => $expected ) {
	$assert( BC_MCP_Abilities::plain_text( $raw ) === $expected, 'plain_text() mismatch for: ' . $raw );
}

// 6. WooCommerce HPOS declaration and deactivation hook are wired (M5, L2).
$assert( false !== has_action( 'before_woocommerce_init' ), 'HPOS compatibility must be declared.' );
$assert( is_callable( array( 'BC_MCP_Abilities_OAuth', 'deactivate' ) ), 'Deactivation cleanup is missing.' );

WP_CLI::success( 'OAuth login return URL, client lifecycle, atomic locks, grants and SEO text sanitization passed.' );
