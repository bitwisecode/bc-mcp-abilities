<?php
/**
 * Standalone, WordPress-hosted OAuth 2.1 authorization for the BC MCP endpoint.
 *
 * No WordPress MCP Adapter, external Node server or external identity provider.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * PKCE-only OAuth authorization code server with restricted client registration.
 */
final class BC_MCP_Abilities_OAuth {

	/**
	 * OAuth enablement setting, default disabled.
	 */
	const OPTION = 'bc_mcp_oauth_enabled';

	/**
	 * DCR client option.
	 */
	const CLIENTS_OPTION = 'bc_mcp_oauth_registered_clients';

	/**
	 * Per-site user option holding each user's active OAuth grants.
	 */
	const GRANTS_OPTION = 'bc_mcp_oauth_grants';

	/**
	 * Maximum number of registered public clients.
	 */
	const MAX_CLIENTS = 50;

	/**
	 * Registrations that never completed a token exchange expire after this.
	 */
	const PENDING_CLIENT_TTL = DAY_IN_SECONDS;

	/**
	 * Clients whose tokens were not used for this long are removed.
	 */
	const IDLE_CLIENT_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Refresh token lifetime.
	 */
	const REFRESH_TTL = 30 * DAY_IN_SECONDS;

	/**
	 * Concurrent use of one refresh token within this window is treated as a
	 * client race, not a theft signal, so the grant is not revoked.
	 */
	const REUSE_GRACE = 30;

	/**
	 * Rewrite rule generation; bump whenever rewrite() changes.
	 */
	const REWRITE_VERSION = '4';

	/**
	 * Register custom routes, discovery metadata and cleanup.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrite' ), 9 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 0 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 22 );
		add_action( 'bc_mcp_oauth_remove_lock', array( __CLASS__, 'remove_lock' ) );
		add_action( 'bc_mcp_prune_activity', array( __CLASS__, 'prune_clients' ) );

		// Credential changes and role changes end every AI connection of that user.
		add_action( 'wp_set_password', array( __CLASS__, 'on_set_password' ), 10, 3 );
		add_action( 'after_password_reset', array( __CLASS__, 'on_password_reset' ) );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ), 10, 2 );
		add_action( 'set_user_role', array( __CLASS__, 'revoke_user' ) );

		// Turning the connection off disconnects everyone; re-enabling never revives old tokens.
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'on_toggle' ), 10, 2 );
		add_action( 'update_option_' . BC_MCP_Abilities_Server::OPTION, array( __CLASS__, 'on_toggle' ), 10, 2 );

		add_action( 'admin_post_bc_mcp_oauth_revoke_grant', array( __CLASS__, 'admin_revoke_grant' ) );
		add_action( 'admin_post_bc_mcp_oauth_revoke_all', array( __CLASS__, 'admin_revoke_all' ) );
	}

	/**
	 * Whether OAuth is explicitly enabled by the site administrator.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return BC_MCP_Abilities_Server::enabled() && '1' === (string) get_option( self::OPTION, '0' ) && is_ssl();
	}

	/**
	 * Return the canonical authorization server issuer URL.
	 *
	 * @return string
	 */
	public static function issuer() {
		return untrailingslashit( home_url( '/bc-mcp' ) );
	}

	/**
	 * Path, relative to the home URL, of the protected-resource metadata document.
	 *
	 * @return string
	 */
	public static function resource_metadata_path() {
		return '.well-known/oauth-protected-resource/' . trim( rest_get_url_prefix(), '/' ) . '/bc-mcp/v1/server';
	}

	/**
	 * Add dedicated rewrite rules that don't conflict with MCP Adapter OAuth.
	 *
	 * @return void
	 */
	public static function rewrite() {
		add_rewrite_rule( '^' . str_replace( '.', '\\.', self::resource_metadata_path() ) . '/?$', 'index.php?bc_mcp_oauth_route=protected', 'top' );
		add_rewrite_rule( '^\.well-known/oauth-authorization-server/bc-mcp/?$', 'index.php?bc_mcp_oauth_route=metadata', 'top' );
		add_rewrite_rule( '^bc-mcp/\.well-known/oauth-authorization-server/?$', 'index.php?bc_mcp_oauth_route=metadata', 'top' );
		add_rewrite_rule( '^bc-mcp/oauth/(authorize|token|register|revoke)/?$', 'index.php?bc_mcp_oauth_route=$matches[1]', 'top' );
	}

	/**
	 * Add one custom request query variable.
	 *
	 * @param string[] $vars Allowed WordPress query variables.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'bc_mcp_oauth_route';
		return $vars;
	}

	/**
	 * Flush custom rewrites only once per code version.
	 *
	 * @return void
	 */
	public static function maybe_flush() {
		if ( self::REWRITE_VERSION === (string) get_option( 'bc_mcp_oauth_rewrite_version', '0' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'bc_mcp_oauth_rewrite_version', self::REWRITE_VERSION, false );
	}

	/**
	 * Ensure the OAuth discovery routes are registered after activation,
	 * including reactivation when old rewrite-version options survive.
	 * Flushes once rather than on every request.
	 *
	 * @return void
	 */
	public static function activate() {
		self::rewrite();
		flush_rewrite_rules( false );
		update_option( 'bc_mcp_oauth_rewrite_version', self::REWRITE_VERSION, false );
	}

	/**
	 * Remove this plugin's scheduled events and rewrite rules on deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'bc_mcp_prune_activity' );
		wp_unschedule_hook( 'bc_mcp_oauth_remove_lock' );
		delete_option( 'bc_mcp_oauth_rewrite_version' );
		// Rules registered during this request would survive a flush, so let
		// WordPress rebuild them on the next request without this plugin.
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Determine the HTTP method safely.
	 *
	 * @return string
	 */
	private static function request_method() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return '';
		}
		return strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) );
	}

	/**
	 * Read OAuth protocol POST fields. OAuth clients cannot send WP nonces.
	 * Authorization-code + PKCE and the form's own nonce secure the flow.
	 * Every field is strictly validated by the caller before use.
	 *
	 * @return array
	 */
	private static function submitted() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- OAuth token/registration endpoints require PKCE and client validation; consent POST verifies its nonce.
		return $_POST;
	}

	/**
	 * Read the incoming OAuth GET authorization request before consent.
	 *
	 * @return array
	 */
	private static function query_input() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public OAuth authorization requests include client state/PKCE; consent action uses a nonce.
		return $_GET;
	}

	/**
	 * Route OAuth requests and respond with JSON or a consent screen.
	 *
	 * @return void
	 */
	public static function route() {
		$route = get_query_var( 'bc_mcp_oauth_route', '' );
		if ( ! is_string( $route ) || ! in_array( $route, array( 'protected', 'metadata', 'register', 'authorize', 'token', 'revoke' ), true ) ) {
			return;
		}
		if ( ! self::enabled() ) {
			status_header( 404 );
			exit;
		}

		switch ( $route ) {
			case 'protected':
				if ( 'GET' !== self::request_method() ) {
					self::fail( 'invalid_request', 405 );
				}
				$metadata = self::resource_metadata();
				self::json( (array) $metadata->get_data(), $metadata->get_status() );
				break;
			case 'metadata':
				if ( 'GET' !== self::request_method() ) {
					self::json( array( 'error' => 'invalid_request' ), 405 );
				}
				self::json( self::authorization_metadata() );
				break;
			case 'register':
				self::register_client();
				break;
			case 'authorize':
				self::authorize();
				break;
			case 'token':
				self::token();
				break;
			case 'revoke':
				self::revoke();
				break;
		}
	}

	/**
	 * Canonical authorization endpoint.
	 *
	 * @return string
	 */
	public static function authorize_url() {
		return home_url( '/bc-mcp/oauth/authorize' );
	}

	/**
	 * Machine-readable OAuth Authorization Server Metadata.
	 *
	 * @return array
	 */
	public static function authorization_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::authorize_url(),
			'token_endpoint'                        => home_url( '/bc-mcp/oauth/token' ),
			'registration_endpoint'                 => home_url( '/bc-mcp/oauth/register' ),
			'revocation_endpoint'                   => home_url( '/bc-mcp/oauth/revoke' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => array( 'mcp', 'offline_access' ),
			'authorization_response_iss_parameter_supported' => true,
		);
	}

	/**
	 * Return metadata required by remote MCP OAuth clients.
	 *
	 * @return WP_REST_Response
	 */
	public static function resource_metadata() {
		if ( ! self::enabled() ) {
			return new WP_REST_Response( array( 'error' => 'disabled' ), 404 );
		}
		$response = new WP_REST_Response(
			array(
				'resource'                 => BC_MCP_Abilities_Server::endpoint(),
				'authorization_servers'    => array( self::issuer() ),
				'bearer_methods_supported' => array( 'header' ),
				'scopes_supported'         => array( 'mcp', 'offline_access' ),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Send JSON without caching tokens or OAuth configuration.
	 *
	 * @param array $data   Response payload.
	 * @param int   $status HTTP status.
	 * @return void
	 */
	private static function json( $data, $status = 200 ) {
		status_header( $status );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Cache-Control: no-store' );
		header( 'Pragma: no-cache' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded API response.
		exit;
	}

	/**
	 * Return a protocol-safe OAuth error.
	 *
	 * @param string $code        Error code.
	 * @param int    $http_status HTTP status.
	 * @return void
	 */
	private static function fail( $code, $http_status = 400 ) {
		self::json( array( 'error' => $code ), $http_status );
	}

	/**
	 * Retrieve an unslashed, trimmed string from POST or GET.
	 *
	 * @param string $key  Request property name.
	 * @param array  $from Raw request array.
	 * @return string
	 */
	private static function field( $key, $from ) {
		if ( ! isset( $from[ $key ] ) || ! is_string( $from[ $key ] ) ) {
			return '';
		}
		return trim( (string) wp_unslash( $from[ $key ] ) );
	}

	/**
	 * Match an exact allowed ChatGPT callback URL, never wildcard the host.
	 *
	 * @param string $uri Registered callback URL.
	 * @return bool
	 */
	public static function allowed_redirect( $uri ) {
		if ( ! is_string( $uri ) || strlen( $uri ) > 2048 ) {
			return false;
		}
		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || 'chatgpt.com' !== ( $parts['host'] ?? '' ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		return 1 === preg_match( '~^/connector/oauth/[a-zA-Z0-9_-]{6,128}$~', (string) ( $parts['path'] ?? '' ) )
			|| '/connector_platform_oauth_redirect' === ( $parts['path'] ?? '' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Client registry lifecycle.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Return a site-limited set of OAuth public client records.
	 *
	 * @return array
	 */
	public static function clients() {
		$clients = get_option( self::CLIENTS_OPTION, array() );
		return is_array( $clients ) ? $clients : array();
	}

	/**
	 * Persist the client registry.
	 *
	 * @param array $clients Client records.
	 * @return void
	 */
	private static function save_clients( $clients ) {
		update_option( self::CLIENTS_OPTION, $clients, false );
	}

	/**
	 * Drop registrations that never completed a token exchange within a day,
	 * and clients whose tokens have not been used for 30 days.
	 *
	 * @param array|null $clients Registry to prune, or null to load and save.
	 * @return array Pruned registry.
	 */
	public static function prune_clients( $clients = null ) {
		$persist = ! is_array( $clients );
		$clients = $persist ? self::clients() : $clients;
		$now     = time();
		$changed = false;
		foreach ( $clients as $id => $client ) {
			$created = is_array( $client ) ? absint( $client['created_at'] ?? 0 ) : 0;
			$used    = is_array( $client ) ? absint( $client['last_used_at'] ?? 0 ) : 0;
			$expired = ! is_array( $client )
				|| ( 0 === $used && $now - $created > self::PENDING_CLIENT_TTL )
				|| ( $used > 0 && $now - $used > self::IDLE_CLIENT_TTL );
			if ( $expired ) {
				unset( $clients[ $id ] );
				$changed = true;
			}
		}
		if ( $persist && $changed ) {
			self::save_clients( $clients );
		}
		return $clients;
	}

	/**
	 * Record that a client completed a token exchange or refresh.
	 * Writes at most once per hour per client.
	 *
	 * @param string $client_id Client identifier.
	 * @return void
	 */
	private static function touch_client( $client_id ) {
		$clients = self::clients();
		if ( ! isset( $clients[ $client_id ] ) || ! is_array( $clients[ $client_id ] ) ) {
			return;
		}
		if ( time() - absint( $clients[ $client_id ]['last_used_at'] ?? 0 ) < HOUR_IN_SECONDS ) {
			return;
		}
		$clients[ $client_id ]['last_used_at'] = time();
		self::save_clients( $clients );
	}

	/**
	 * Register a public OAuth client with exact ChatGPT redirect allowlisting.
	 *
	 * @return void
	 */
	private static function register_client() {
		if ( 'POST' !== self::request_method() ) {
			self::fail( 'invalid_request', 405 );
		}
		$raw = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- OAuth JSON request body.
		if ( ! is_string( $raw ) || strlen( $raw ) > 16384 ) {
			self::fail( 'invalid_client_metadata' );
		}
		$body = json_decode( $raw, true );
		if ( ! is_array( $body ) || ! isset( $body['redirect_uris'] ) || ! is_array( $body['redirect_uris'] ) || ! count( $body['redirect_uris'] ) || count( $body['redirect_uris'] ) > 4 ) {
			self::fail( 'invalid_client_metadata' );
		}
		foreach ( $body['redirect_uris'] as $uri ) {
			if ( ! self::allowed_redirect( $uri ) ) {
				self::fail( 'invalid_redirect_uri' );
			}
		}
		if ( isset( $body['token_endpoint_auth_method'] ) && 'none' !== $body['token_endpoint_auth_method'] ) {
			self::fail( 'invalid_client_metadata' );
		}

		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$rate    = 'bc_mcp_reg_rate_' . hash( 'sha256', $address );
		$count   = (int) get_transient( $rate );
		if ( $count >= 10 ) {
			self::fail( 'rate_limited', 429 );
		}
		set_transient( $rate, $count + 1, HOUR_IN_SECONDS );

		$clients = self::prune_clients( self::clients() );
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			// Evict the oldest registration that never completed a token
			// exchange. Clients with working connections are never evicted.
			$oldest = '';
			$time   = PHP_INT_MAX;
			foreach ( $clients as $id => $client ) {
				if ( 0 === absint( $client['last_used_at'] ?? 0 ) && absint( $client['created_at'] ?? 0 ) < $time ) {
					$oldest = $id;
					$time   = absint( $client['created_at'] ?? 0 );
				}
			}
			if ( '' === $oldest ) {
				self::save_clients( $clients );
				self::fail( 'too_many_clients', 429 );
			}
			unset( $clients[ $oldest ] );
		}
		$id             = 'bc_' . bin2hex( random_bytes( 24 ) );
		$name           = sanitize_text_field( (string) ( is_string( $body['client_name'] ?? null ) ? $body['client_name'] : 'ChatGPT' ) );
		$clients[ $id ] = array(
			'redirect_uris' => array_values( $body['redirect_uris'] ),
			'client_name'   => '' !== $name ? mb_substr( $name, 0, 100 ) : 'ChatGPT',
			'created_at'    => time(),
			'last_used_at'  => 0,
		);
		self::save_clients( $clients );
		self::trace( 'registration', 'registered' );

		self::json(
			array(
				'client_id'                  => $id,
				'client_name'                => $clients[ $id ]['client_name'],
				'redirect_uris'              => $clients[ $id ]['redirect_uris'],
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'token_endpoint_auth_method' => 'none',
				'client_id_issued_at'        => time(),
			),
			201
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Grants: one per user approval. Every token belongs to a grant, so
	 * revoking a grant immediately invalidates its access and refresh tokens.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Read a user's grants on this site.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function grants( $user_id ) {
		$grants = get_user_option( self::GRANTS_OPTION, (int) $user_id );
		return is_array( $grants ) ? $grants : array();
	}

	/**
	 * Store a user's grants on this site.
	 *
	 * @param int   $user_id User ID.
	 * @param array $grants  Grants.
	 * @return void
	 */
	private static function save_grants( $user_id, $grants ) {
		if ( $grants ) {
			update_user_option( (int) $user_id, self::GRANTS_OPTION, $grants );
		} else {
			delete_user_option( (int) $user_id, self::GRANTS_OPTION );
		}
	}

	/**
	 * Create a grant for an approved authorization request.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $client_id Client identifier.
	 * @return string Grant identifier.
	 */
	private static function create_grant( $user_id, $client_id ) {
		$grants = self::grants( $user_id );
		// Drop approvals whose code was never exchanged.
		foreach ( $grants as $id => $grant ) {
			if ( ! empty( $grant['pending'] ) && time() - absint( $grant['created_at'] ?? 0 ) > 15 * MINUTE_IN_SECONDS ) {
				unset( $grants[ $id ] );
			}
		}
		$id            = bin2hex( random_bytes( 16 ) );
		$grants[ $id ] = array(
			'client'       => (string) $client_id,
			'created_at'   => time(),
			'last_used_at' => 0,
			'pending'      => true,
		);
		self::save_grants( $user_id, $grants );
		return $id;
	}

	/**
	 * Whether a grant exists, is active and belongs to this client.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $grant_id  Grant identifier.
	 * @param string $client_id Client identifier.
	 * @param bool   $allow_pending Whether a not-yet-exchanged grant is acceptable.
	 * @return bool
	 */
	private static function grant_valid( $user_id, $grant_id, $client_id, $allow_pending = false ) {
		if ( ! is_string( $grant_id ) || '' === $grant_id ) {
			return false;
		}
		$grants = self::grants( $user_id );
		if ( ! isset( $grants[ $grant_id ] ) || ( $grants[ $grant_id ]['client'] ?? '' ) !== $client_id ) {
			return false;
		}
		return $allow_pending || empty( $grants[ $grant_id ]['pending'] );
	}

	/**
	 * Mark a grant active and record its use (at most once per hour).
	 *
	 * @param int    $user_id  User ID.
	 * @param string $grant_id Grant identifier.
	 * @return void
	 */
	private static function touch_grant( $user_id, $grant_id ) {
		$grants = self::grants( $user_id );
		if ( ! isset( $grants[ $grant_id ] ) ) {
			return;
		}
		if ( empty( $grants[ $grant_id ]['pending'] ) && time() - absint( $grants[ $grant_id ]['last_used_at'] ?? 0 ) < HOUR_IN_SECONDS ) {
			return;
		}
		unset( $grants[ $grant_id ]['pending'] );
		$grants[ $grant_id ]['last_used_at'] = time();
		self::save_grants( $user_id, $grants );
	}

	/**
	 * Revoke one grant and, with it, all its tokens.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $grant_id Grant identifier.
	 * @return void
	 */
	public static function revoke_grant( $user_id, $grant_id ) {
		$grants = self::grants( $user_id );
		if ( isset( $grants[ $grant_id ] ) ) {
			unset( $grants[ $grant_id ] );
			self::save_grants( $user_id, $grants );
		}
	}

	/**
	 * Revoke every AI connection of one user on this site.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function revoke_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id ) {
			delete_user_option( $user_id, self::GRANTS_OPTION );
		}
	}

	/**
	 * Revoke every AI connection of every user on this site.
	 *
	 * @return void
	 */
	public static function revoke_all() {
		global $wpdb;
		delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . self::GRANTS_OPTION, '', true );
	}

	/**
	 * All active grants on this site, for the administration screen.
	 *
	 * @return array[] Each with user_id, grant_id, client, created_at, last_used_at.
	 */
	public static function all_grants() {
		global $wpdb;
		$users = get_users(
			array(
				'meta_key' => $wpdb->get_blog_prefix() . self::GRANTS_OPTION, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Admin screen only, bounded.
				'number'   => 200,
				'fields'   => 'ID',
			)
		);
		$rows  = array();
		foreach ( $users as $user_id ) {
			foreach ( self::grants( $user_id ) as $grant_id => $grant ) {
				if ( ! empty( $grant['pending'] ) ) {
					continue;
				}
				$rows[] = array(
					'user_id'      => (int) $user_id,
					'grant_id'     => (string) $grant_id,
					'client'       => (string) ( $grant['client'] ?? '' ),
					'created_at'   => absint( $grant['created_at'] ?? 0 ),
					'last_used_at' => absint( $grant['last_used_at'] ?? 0 ),
				);
			}
		}
		return $rows;
	}

	/**
	 * Revoke a user's connections when their password changes.
	 *
	 * WordPress also calls wp_set_password() to re-hash an unchanged password
	 * at login; that is detected and does not disconnect the user.
	 *
	 * @param string       $password Plain password.
	 * @param int          $user_id  User ID.
	 * @param WP_User|null $old_user User data before the change (WordPress 6.7+).
	 * @return void
	 */
	public static function on_set_password( $password, $user_id, $old_user = null ) {
		if ( $old_user instanceof WP_User && '' !== (string) $old_user->user_pass && wp_check_password( $password, $old_user->user_pass, $user_id ) ) {
			return;
		}
		self::revoke_user( $user_id );
	}

	/**
	 * Revoke a user's connections after a password reset.
	 *
	 * @param WP_User $user User.
	 * @return void
	 */
	public static function on_password_reset( $user ) {
		if ( $user instanceof WP_User ) {
			self::revoke_user( $user->ID );
		}
	}

	/**
	 * Revoke a user's connections when their password changes via profile edit.
	 *
	 * @param int     $user_id  User ID.
	 * @param WP_User $old_user Previous user data.
	 * @return void
	 */
	public static function on_profile_update( $user_id, $old_user ) {
		$current = get_userdata( $user_id );
		if ( $current && $old_user instanceof WP_User && $current->user_pass !== $old_user->user_pass ) {
			self::revoke_user( $user_id );
		}
	}

	/**
	 * Disconnect all AI clients when the MCP connection or sign-in is switched off.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	public static function on_toggle( $old_value, $value ) {
		if ( '1' === (string) $old_value && '1' !== (string) $value ) {
			self::revoke_all();
		}
	}

	/**
	 * Administrator action: revoke one connection.
	 *
	 * @return void
	 */
	public static function admin_revoke_grant() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage AI connections.', 'bc-mcp-abilities' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bc_mcp_oauth_revoke_grant' );
		$user_id  = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$grant_id = isset( $_POST['grant_id'] ) ? sanitize_key( wp_unslash( $_POST['grant_id'] ) ) : '';
		if ( $user_id && $grant_id ) {
			self::revoke_grant( $user_id, $grant_id );
		}
		wp_safe_redirect( add_query_arg( 'page', 'bc-mcp-abilities', admin_url( 'options-general.php' ) ) . '#bc-panel-connections' );
		exit;
	}

	/**
	 * Administrator action: revoke every connection and registered client.
	 *
	 * @return void
	 */
	public static function admin_revoke_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot manage AI connections.', 'bc-mcp-abilities' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bc_mcp_oauth_revoke_all' );
		self::revoke_all();
		delete_option( self::CLIENTS_OPTION );
		wp_safe_redirect( add_query_arg( 'page', 'bc-mcp-abilities', admin_url( 'options-general.php' ) ) . '#bc-panel-connections' );
		exit;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Authorization endpoint.
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Validate a full authorization request and return a safe normalized state.
	 *
	 * @param array $input Raw GET/POST data.
	 * @return array|WP_Error
	 */
	private static function validated_authorization( $input ) {
		$client_id = self::field( 'client_id', $input );
		$redirect  = self::field( 'redirect_uri', $input );
		$challenge = self::field( 'code_challenge', $input );
		$method    = self::field( 'code_challenge_method', $input );
		$resource  = self::field( 'resource', $input );
		$scope     = self::field( 'scope', $input );
		$state     = self::field( 'state', $input );
		$response  = self::field( 'response_type', $input );
		$clients   = self::clients();

		if ( ! isset( $clients[ $client_id ] ) || ! in_array( $redirect, (array) ( $clients[ $client_id ]['redirect_uris'] ?? array() ), true ) || ! self::allowed_redirect( $redirect ) ) {
			return new WP_Error( 'invalid_client' );
		}
		if ( 'code' !== $response || 'S256' !== $method || ! preg_match( '/^[a-zA-Z0-9_-]{43}$/', $challenge ) || BC_MCP_Abilities_Server::endpoint() !== $resource ) {
			return new WP_Error( 'invalid_request' );
		}
		$scopes = array_values( array_filter( preg_split( '/\s+/', $scope ) ) );
		if ( ! in_array( 'mcp', $scopes, true ) || array_diff( $scopes, array( 'mcp', 'offline_access' ) ) || strlen( $state ) > 2048 ) {
			return new WP_Error( 'invalid_scope' );
		}
		return array(
			'client_id'    => $client_id,
			'redirect_uri' => $redirect,
			'challenge'    => $challenge,
			'resource'     => $resource,
			'scope'        => implode( ' ', $scopes ),
			'state'        => $state,
		);
	}

	/**
	 * Rebuild the authorization request URL from validated values only.
	 *
	 * Raw REQUEST_URI is never reused: sanitize_text_field() would strip its
	 * percent-encoding, and prefixing it with home_url() doubles the path on
	 * subdirectory installs.
	 *
	 * @param array $auth Validated authorization state.
	 * @return string
	 */
	public static function authorization_request_url( $auth ) {
		$query = array(
			'response_type'         => 'code',
			'client_id'             => $auth['client_id'],
			'redirect_uri'          => $auth['redirect_uri'],
			'scope'                 => $auth['scope'],
			'resource'              => $auth['resource'],
			'code_challenge'        => $auth['challenge'],
			'code_challenge_method' => 'S256',
		);
		if ( '' !== $auth['state'] ) {
			$query['state'] = $auth['state'];
		}
		return add_query_arg( rawurlencode_deep( $query ), self::authorize_url() );
	}

	/**
	 * Redirect a browser to a previously verified OAuth client callback.
	 *
	 * @param array $auth       Validated authorization state.
	 * @param array $parameters Redirect query variables.
	 * @return void
	 */
	private static function client_redirect( $auth, $parameters ) {
		$url = add_query_arg( rawurlencode_deep( $parameters ), $auth['redirect_uri'] );
		// Only redirect to the exact URI that was validated against the registration record.
		wp_redirect( esc_url_raw( $url ), 302, 'BC MCP OAuth' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Exact registered HTTPS ChatGPT callback.
		exit;
	}

	/**
	 * Headers that keep the consent page out of frames and referrers.
	 *
	 * @return void
	 */
	private static function consent_headers() {
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * Render a WordPress consent form and issue one-use PKCE codes.
	 *
	 * @return void
	 */
	private static function authorize() {
		if ( ! in_array( self::request_method(), array( 'GET', 'POST' ), true ) ) {
			self::fail( 'invalid_request', 405 );
		}
		$is_post = 'POST' === self::request_method();
		$input   = $is_post ? self::submitted() : self::query_input();
		$auth    = self::validated_authorization( $input );
		if ( is_wp_error( $auth ) ) {
			self::trace( 'authorization', 'invalid_request', array( 'detail' => $auth->get_error_code() ) );
			self::fail( $auth->get_error_code() );
		}
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::authorization_request_url( $auth ) ), 302 );
			exit;
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			self::fail( 'access_denied', 403 );
		}
		if ( $is_post ) {
			$nonce = self::field( '_wpnonce', self::submitted() );
			if ( ! wp_verify_nonce( $nonce, 'bc_mcp_oauth_consent_' . $auth['client_id'] ) ) {
				self::fail( 'invalid_request', 403 );
			}
			$choice = self::field( 'bc_decision', self::submitted() );
			if ( 'approve' !== $choice ) {
				self::trace( 'authorization', 'denied' );
				self::client_redirect(
					$auth,
					array(
						'error' => 'access_denied',
						'state' => $auth['state'],
						'iss'   => self::issuer(),
					)
				);
			}
			$code = bin2hex( random_bytes( 32 ) );
			set_transient(
				'bc_mcp_auth_code_' . hash( 'sha256', $code ),
				array(
					'auth'    => $auth,
					'user_id' => get_current_user_id(),
					'grant'   => self::create_grant( get_current_user_id(), $auth['client_id'] ),
				),
				5 * MINUTE_IN_SECONDS
			);
			self::trace( 'authorization', 'approved' );
			self::client_redirect(
				$auth,
				array(
					'code'  => $code,
					'state' => $auth['state'],
					'iss'   => self::issuer(),
				)
			);
		}

		$client  = self::clients()[ $auth['client_id'] ];
		$user    = wp_get_current_user();
		$created = absint( $client['created_at'] ?? 0 );
		self::consent_headers();
		?>
		<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width,initial-scale=1">
			<meta name="robots" content="noindex,nofollow">
			<title><?php esc_html_e( 'Authorize BC MCP Abilities', 'bc-mcp-abilities' ); ?></title>
			<style>
				body{background:#f6f7f7;color:#1d2327;font:16px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;margin:0;padding:40px 18px}
				main{max-width:560px;margin:5vh auto;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:32px}
				h1{font-size:24px;margin-top:0}.note{color:#50575e}button{padding:11px 20px;border:0;border-radius:5px;cursor:pointer}
				.allow{background:#2271b1;color:#fff}.deny{background:#f0f0f1;margin-left:8px}
				dl{display:grid;grid-template-columns:max-content 1fr;gap:6px 14px;margin:18px 0;font-size:14px}dt{color:#50575e}dd{margin:0;word-break:break-all}
				.warn{background:#fcf9e8;border-left:4px solid #dba617;padding:10px 14px;font-size:14px}
			</style>
		</head>
		<body><main>
		<h1><?php esc_html_e( 'Connect an AI assistant to this website', 'bc-mcp-abilities' ); ?></h1>
		<p>
			<?php
			printf(
				/* translators: 1: OAuth client name, 2: site name. */
				esc_html__( '%1$s is requesting permission to use WordPress abilities on %2$s as your signed-in user.', 'bc-mcp-abilities' ),
				'<strong>' . esc_html( (string) ( $client['client_name'] ?? 'ChatGPT' ) ) . '</strong>',
				'<strong>' . esc_html( get_bloginfo( 'name' ) ) . '</strong>'
			);
			?>
		</p>
		<dl>
			<dt><?php esc_html_e( 'Signed in as', 'bc-mcp-abilities' ); ?></dt>
			<dd><?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?></dd>
			<dt><?php esc_html_e( 'Website', 'bc-mcp-abilities' ); ?></dt>
			<dd><?php echo esc_html( home_url( '/' ) ); ?></dd>
			<dt><?php esc_html_e( 'Returns to', 'bc-mcp-abilities' ); ?></dt>
			<dd><code><?php echo esc_html( $auth['redirect_uri'] ); ?></code></dd>
			<dt><?php esc_html_e( 'Client registered', 'bc-mcp-abilities' ); ?></dt>
			<dd><?php echo esc_html( $created ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $created ) : '—' ); ?></dd>
			<dt><?php esc_html_e( 'Requested access', 'bc-mcp-abilities' ); ?></dt>
			<dd><?php echo esc_html( $auth['scope'] ); ?></dd>
		</dl>
		<p class="warn"><?php esc_html_e( 'Only approve if you started this connection yourself, just now, from your AI assistant. If you did not, choose Deny.', 'bc-mcp-abilities' ); ?></p>
		<p class="note"><?php esc_html_e( 'Your existing WordPress permissions still apply. Write abilities remain disabled unless the site administrator enables them individually. An administrator can disconnect assistants at any time.', 'bc-mcp-abilities' ); ?></p>
		<form method="post" action="<?php echo esc_url( self::authorize_url() ); ?>">
			<?php
			foreach ( array( 'client_id', 'redirect_uri', 'resource', 'scope', 'state' ) as $field ) {
				printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $field ), esc_attr( $auth[ $field ] ) );
			}
			?>
			<input type="hidden" name="response_type" value="code">
			<input type="hidden" name="code_challenge" value="<?php echo esc_attr( $auth['challenge'] ); ?>">
			<input type="hidden" name="code_challenge_method" value="S256">
			<?php wp_nonce_field( 'bc_mcp_oauth_consent_' . $auth['client_id'] ); ?>
			<button class="allow" type="submit" name="bc_decision" value="approve"><?php esc_html_e( 'Authorize', 'bc-mcp-abilities' ); ?></button>
			<button class="deny" type="submit" name="bc_decision" value="deny"><?php esc_html_e( 'Deny', 'bc-mcp-abilities' ); ?></button>
		</form>
		<p class="note"><a href="<?php echo esc_url( wp_logout_url( self::authorization_request_url( $auth ) ) ); ?>"><?php esc_html_e( 'Not you? Sign in as a different user', 'bc-mcp-abilities' ); ?></a></p>
		</main></body></html>
		<?php
		exit;
	}

	/**
	 * Enforce resource binding without requiring the optional RFC 8707 token parameter.
	 *
	 * A missing token-request resource retains the originally authorized
	 * resource. A supplied resource must match that grant exactly.
	 *
	 * @param string $requested Resource from the token request, possibly empty.
	 * @param string $granted   Original authorization's resource.
	 * @return bool
	 */
	public static function valid_token_resource( $requested, $granted ) {
		if ( ! is_string( $requested ) || ! is_string( $granted ) || BC_MCP_Abilities_Server::endpoint() !== $granted ) {
			return false;
		}
		return '' === $requested || hash_equals( $granted, $requested );
	}

	/**
	 * Diagnostic events kept, newest last.
	 */
	const DEBUG_MAX_EVENTS = 50;

	/**
	 * Write a bounded diagnostic event, without credentials or request identifiers.
	 *
	 * Only fixed vocabulary is stored: the stage, its result and optional
	 * method/auth/detail labels chosen by this plugin. Never tokens, codes,
	 * client IDs, cookies, user IDs, IP addresses, URLs or request bodies.
	 *
	 * @param string $event   Known protocol stage.
	 * @param string $result  Known stage outcome.
	 * @param array  $context Optional method, auth and detail labels.
	 * @return void
	 */
	public static function trace( $event, $result, $context = array() ) {
		if ( '1' !== (string) get_option( 'bc_mcp_oauth_debug_enabled', '0' ) ) {
			return;
		}

		$allowed_events  = array( 'registration', 'authorization', 'token', 'refresh', 'revoke', 'mcp' );
		$allowed_results = array( 'received', 'registered', 'approved', 'denied', 'invalid_client', 'invalid_grant', 'invalid_resource', 'invalid_pkce_or_redirect', 'replay', 'issued', 'refreshed', 'revoked', 'unsupported_grant', 'initialized', 'listed', 'called', 'call_succeeded', 'call_failed', 'unauthorized', 'forbidden', 'invalid_request', 'unknown_method' );
		if ( ! in_array( $event, $allowed_events, true ) || ! in_array( $result, $allowed_results, true ) ) {
			return;
		}

		$entry = array(
			'time'   => time(),
			'event'  => $event,
			'result' => $result,
		);
		foreach ( array( 'method', 'auth', 'detail' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_string( $context[ $key ] ) && preg_match( '~^[a-z0-9_/.-]{1,64}$~', $context[ $key ] ) ) {
				$entry[ $key ] = $context[ $key ];
			}
		}

		$events   = get_option( 'bc_mcp_oauth_debug_events', array() );
		$events   = is_array( $events ) ? $events : array();
		$events   = array_values(
			array_filter(
				$events,
				static function ( $item ) {
					return is_array( $item ) && isset( $item['time'] ) && time() - absint( $item['time'] ) < DAY_IN_SECONDS;
				}
			)
		);
		$events[] = $entry;
		update_option( 'bc_mcp_oauth_debug_events', array_slice( $events, -self::DEBUG_MAX_EVENTS ), false );
	}

	/**
	 * Read a PKCE token verifier and validate against a stored S256 challenge.
	 *
	 * @param string $verifier  Client verifier.
	 * @param string $challenge Issued code challenge.
	 * @return bool
	 */
	public static function pkce_valid( $verifier, $challenge ) {
		if ( ! is_string( $verifier ) || 1 !== preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) {
			return false;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 PKCE S256 uses base64url of a SHA256 digest, never PHP code.
		$actual = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		return is_string( $challenge ) && hash_equals( $challenge, $actual );
	}

	/**
	 * Create opaque short-lived access and rotating refresh tokens.
	 *
	 * @param array  $auth     Validated authorization state.
	 * @param int    $user_id  WordPress user ID.
	 * @param string $grant_id Grant the tokens belong to.
	 * @return array
	 */
	private static function issue_tokens( $auth, $user_id, $grant_id ) {
		$access = bin2hex( random_bytes( 32 ) );
		$expiry = HOUR_IN_SECONDS;
		$record = array(
			'user_id'  => (int) $user_id,
			'resource' => $auth['resource'],
			'client'   => $auth['client_id'],
			'scope'    => $auth['scope'],
			'grant'    => (string) $grant_id,
		);
		set_transient( 'bc_mcp_access_' . hash( 'sha256', $access ), $record, $expiry );
		$result = array(
			'access_token' => $access,
			'token_type'   => 'Bearer',
			'expires_in'   => $expiry,
			'scope'        => $auth['scope'],
		);
		if ( false !== strpos( ' ' . $auth['scope'] . ' ', ' offline_access ' ) ) {
			$refresh = bin2hex( random_bytes( 32 ) );
			set_transient( 'bc_mcp_refresh_' . hash( 'sha256', $refresh ), $record, self::REFRESH_TTL );
			$result['refresh_token'] = $refresh;
		}
		self::touch_grant( $user_id, $grant_id );
		self::touch_client( $auth['client_id'] );
		return $result;
	}

	/**
	 * Atomically claim a one-use credential.
	 *
	 * Core add_option() is not suitable: its INSERT … ON DUPLICATE KEY UPDATE
	 * reports success to a second racing request whenever the stored value
	 * differs. INSERT IGNORE affects exactly one row for exactly one caller.
	 *
	 * @param string $hash Hash of the code/token.
	 * @return bool True for the single caller that claimed it.
	 */
	private static function consume_lock( $hash ) {
		global $wpdb;
		$key = 'bc_mcp_used_' . $hash;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic single-use claim; must bypass the options cache.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $key, (string) time() ) );
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return false;
		}
		wp_schedule_single_event( time() + 600, 'bc_mcp_oauth_remove_lock', array( $key ) );
		return true;
	}

	/**
	 * Seconds since a one-use credential was claimed, or null when unclaimed.
	 *
	 * @param string $hash Hash of the code/token.
	 * @return int|null
	 */
	private static function lock_age( $hash ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the atomic claim written above.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'bc_mcp_used_' . $hash ) );
		return null === $value ? null : max( 0, time() - (int) $value );
	}

	/**
	 * Remove an expired single-use lock.
	 *
	 * @param string $key Option to delete.
	 * @return void
	 */
	public static function remove_lock( $key ) {
		if ( is_string( $key ) && 0 === strpos( $key, 'bc_mcp_used_' ) ) {
			delete_option( $key );
		}
	}

	/**
	 * Issue tokens with PKCE, rotate refresh tokens, and reject replay.
	 *
	 * @return void
	 */
	private static function token() {
		if ( 'POST' !== self::request_method() ) {
			self::fail( 'invalid_request', 405 );
		}
		$grant  = self::field( 'grant_type', self::submitted() );
		$client = self::field( 'client_id', self::submitted() );
		$uri    = self::field( 'resource', self::submitted() );
		if ( 'authorization_code' === $grant ) {
			self::trace( 'token', 'received' );
		} elseif ( 'refresh_token' === $grant ) {
			self::trace( 'refresh', 'received' );
		} else {
			self::trace( 'token', 'unsupported_grant' );
		}

		$clients = self::clients();
		if ( ! isset( $clients[ $client ] ) ) {
			self::trace( 'token', 'invalid_client' );
			self::fail( 'invalid_client', 401 );
		}
		if ( '' !== $uri && BC_MCP_Abilities_Server::endpoint() !== $uri ) {
			self::trace( 'token', 'invalid_resource' );
			self::fail( 'invalid_target' );
		}

		if ( 'authorization_code' === $grant ) {
			$code   = self::field( 'code', self::submitted() );
			$hash   = hash( 'sha256', $code );
			$stored = get_transient( 'bc_mcp_auth_code_' . $hash );
			if ( ! is_array( $stored ) || ! isset( $stored['auth'], $stored['user_id'], $stored['grant'] ) ) {
				self::trace( 'token', 'invalid_grant' );
				self::fail( 'invalid_grant' );
			}
			$auth = $stored['auth'];
			if ( ! self::valid_token_resource( $uri, $auth['resource'] ) ) {
				self::trace( 'token', 'invalid_resource' );
				self::fail( 'invalid_target' );
			}
			if ( $auth['client_id'] !== $client || self::field( 'redirect_uri', self::submitted() ) !== $auth['redirect_uri'] || ! self::pkce_valid( self::field( 'code_verifier', self::submitted() ), $auth['challenge'] ) ) {
				self::trace( 'token', 'invalid_pkce_or_redirect' );
				self::fail( 'invalid_grant' );
			}
			if ( ! self::consume_lock( $hash ) ) {
				// RFC 6749 §4.1.2: a reused code revokes what it issued.
				self::revoke_grant( (int) $stored['user_id'], (string) $stored['grant'] );
				self::trace( 'token', 'replay' );
				self::fail( 'invalid_grant' );
			}
			delete_transient( 'bc_mcp_auth_code_' . $hash );
			if ( ! self::grant_valid( (int) $stored['user_id'], (string) $stored['grant'], $client, true ) ) {
				self::trace( 'token', 'invalid_grant' );
				self::fail( 'invalid_grant' );
			}
			$issued = self::issue_tokens( $auth, $stored['user_id'], $stored['grant'] );
			self::trace( 'token', 'issued' );
			self::json( $issued );
		}

		if ( 'refresh_token' === $grant ) {
			$refresh = self::field( 'refresh_token', self::submitted() );
			$hash    = hash( 'sha256', $refresh );
			$key     = 'bc_mcp_refresh_' . $hash;
			$stored  = get_transient( $key );
			if ( ! is_array( $stored ) || ( $stored['client'] ?? '' ) !== $client ) {
				self::trace( 'refresh', 'invalid_grant' );
				self::fail( 'invalid_grant' );
			}
			$user_id  = absint( $stored['user_id'] ?? 0 );
			$grant_id = (string) ( $stored['grant'] ?? '' );
			if ( ! empty( $stored['used'] ) ) {
				// A rotated-out refresh token came back: treat as theft and end
				// the whole grant, unless it is a near-simultaneous client retry.
				$age = self::lock_age( $hash );
				if ( null === $age || $age > self::REUSE_GRACE ) {
					self::revoke_grant( $user_id, $grant_id );
				}
				self::trace( 'refresh', 'replay' );
				self::fail( 'invalid_grant' );
			}
			if ( ! get_userdata( $user_id ) || ! self::grant_valid( $user_id, $grant_id, $client ) ) {
				self::trace( 'refresh', 'invalid_grant' );
				self::fail( 'invalid_grant' );
			}
			if ( ! self::valid_token_resource( $uri, $stored['resource'] ) ) {
				self::trace( 'refresh', 'invalid_resource' );
				self::fail( 'invalid_target' );
			}
			if ( ! self::consume_lock( $hash ) ) {
				self::trace( 'refresh', 'replay' );
				self::fail( 'invalid_grant' );
			}
			// Keep a tombstone so later reuse is recognised as replay.
			set_transient(
				$key,
				array(
					'used'    => true,
					'client'  => $client,
					'user_id' => $user_id,
					'grant'   => $grant_id,
				),
				self::REFRESH_TTL
			);
			$auth   = array(
				'client_id' => $client,
				'resource'  => $stored['resource'],
				'scope'     => $stored['scope'],
			);
			$issued = self::issue_tokens( $auth, $user_id, $grant_id );
			self::trace( 'refresh', 'refreshed' );
			self::json( $issued );
		}
		self::fail( 'unsupported_grant_type' );
	}

	/**
	 * Delete an access or refresh token by hash without exposing its record.
	 *
	 * Revoking a refresh token ends its whole grant (RFC 7009 §2.1).
	 *
	 * @return void
	 */
	private static function revoke() {
		if ( 'POST' !== self::request_method() ) {
			self::fail( 'invalid_request', 405 );
		}
		$client = self::field( 'client_id', self::submitted() );
		$token  = self::field( 'token', self::submitted() );
		if ( ! isset( self::clients()[ $client ] ) ) {
			self::fail( 'invalid_client', 401 );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			// RFC 7009: an unknown token is not an error.
			self::json( array() );
		}
		$hash = hash( 'sha256', $token );
		foreach ( array( 'bc_mcp_access_', 'bc_mcp_refresh_' ) as $prefix ) {
			$entry = get_transient( $prefix . $hash );
			if ( is_array( $entry ) && ( $entry['client'] ?? '' ) === $client ) {
				delete_transient( $prefix . $hash );
				if ( 'bc_mcp_refresh_' === $prefix && empty( $entry['used'] ) ) {
					self::revoke_grant( absint( $entry['user_id'] ?? 0 ), (string) ( $entry['grant'] ?? '' ) );
				}
				self::trace( 'revoke', 'revoked' );
			}
		}
		self::json( array() );
	}

	/**
	 * Validate an OAuth Bearer token and resolve its WordPress user.
	 *
	 * @param string $header Authorization request header.
	 * @return int|WP_Error
	 */
	public static function bearer_user( $header ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'bc_mcp_oauth_disabled' );
		}
		if ( ! is_string( $header ) || '' === trim( $header ) ) {
			return new WP_Error( 'bc_mcp_oauth_missing' );
		}
		if ( ! preg_match( '/^Bearer ([a-f0-9]{64})$/i', trim( $header ), $match ) ) {
			return new WP_Error( 'bc_mcp_oauth_malformed' );
		}
		$record = get_transient( 'bc_mcp_access_' . hash( 'sha256', strtolower( $match[1] ) ) );
		if ( ! is_array( $record ) ) {
			return new WP_Error( 'bc_mcp_oauth_unknown' );
		}
		// A stored token alone is not enough: the issuing client must still
		// exist in this installation's OAuth client registry. This also makes
		// tokens from a previously uninstalled instance unusable after reinstall.
		$client_id = isset( $record['client'] ) && is_string( $record['client'] ) ? $record['client'] : '';
		if ( '' === $client_id || ! isset( self::clients()[ $client_id ] ) ) {
			return new WP_Error( 'bc_mcp_oauth_client_removed' );
		}
		if ( ( $record['resource'] ?? '' ) !== BC_MCP_Abilities_Server::endpoint() ) {
			return new WP_Error( 'bc_mcp_oauth_wrong_target' );
		}
		if ( ! in_array( 'mcp', explode( ' ', (string) ( $record['scope'] ?? '' ) ), true ) ) {
			return new WP_Error( 'bc_mcp_oauth_scope' );
		}
		$id = isset( $record['user_id'] ) ? absint( $record['user_id'] ) : 0;
		if ( ! $id || ! get_userdata( $id ) || ! user_can( $id, 'edit_posts' ) ) {
			return new WP_Error( 'bc_mcp_oauth_denied' );
		}
		// The grant is the revocation handle: password changes, role changes,
		// admin disconnects and refresh-token replay all remove it.
		if ( ! self::grant_valid( $id, (string) ( $record['grant'] ?? '' ), $client_id ) ) {
			return new WP_Error( 'bc_mcp_oauth_revoked' );
		}
		return $id;
	}
}
