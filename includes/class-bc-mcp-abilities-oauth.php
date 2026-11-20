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
	 * Maximum number of registered public clients.
	 */
	const MAX_CLIENTS = 50;

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
	 * Add dedicated rewrite rules that don't conflict with MCP Adapter OAuth.
	 *
	 * @return void
	 */
	public static function rewrite() {
		add_rewrite_rule( '^\.well-known/oauth-protected-resource/wp-json/bc-mcp/v1/server/? 'index.php?bc_mcp_oauth_route=metadata', 'top' );
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
		if ( '1' === (string) get_option( 'bc_mcp_oauth_rewrite_version', '0' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'bc_mcp_oauth_rewrite_version', '1', false );
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
	 * Machine-readable OAuth Authorization Server Metadata.
	 *
	 * @return array
	 */
	public static function authorization_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => home_url( '/bc-mcp/oauth/authorize' ),
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
	 * Retrieve a sanitized string from POST or GET.
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

	/**
	 * Return a site-limited set of OAuth public client records.
	 *
	 * @return array
	 */
	private static function clients() {
		$clients = get_option( self::CLIENTS_OPTION, array() );
		return is_array( $clients ) ? $clients : array();
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

		$clients = self::clients();
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			self::fail( 'too_many_clients', 429 );
		}
		$id             = 'bc_' . bin2hex( random_bytes( 24 ) );
		$clients[ $id ] = array(
			'redirect_uris' => array_values( $body['redirect_uris'] ),
			'client_name'   => sanitize_text_field( (string) ( $body['client_name'] ?? 'ChatGPT' ) ),
			'created_at'    => time(),
		);
		update_option( self::CLIENTS_OPTION, $clients, false );

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

		if ( ! isset( $clients[ $client_id ] ) || ! in_array( $redirect, $clients[ $client_id ]['redirect_uris'], true ) || ! self::allowed_redirect( $redirect ) ) {
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
	 * Redirect a browser to a previously verified OAuth client callback.
	 *
	 * @param array $auth       Validated authorization state.
	 * @param array $parameters Redirect query variables.
	 * @return void
	 */
	private static function client_redirect( $auth, $parameters ) {
		$url = add_query_arg( $parameters, $auth['redirect_uri'] );
		// Only redirect to the exact URI that was validated against the registration record.
		wp_redirect( esc_url_raw( $url ), 302, 'BC MCP OAuth' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Exact registered HTTPS ChatGPT callback.
		exit;
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
		$input   = $is_post ? self::submitted() : self::query_input(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth clients use state and PKCE, POST is nonce-protected below.
		$auth    = self::validated_authorization( $input );
		if ( is_wp_error( $auth ) ) {
			self::fail( $auth->get_error_code() );
		}
		if ( ! is_user_logged_in() ) {
			$destination = home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/bc-mcp/oauth/authorize' );
			wp_safe_redirect( wp_login_url( $destination ), 302 );
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
				),
				5 * MINUTE_IN_SECONDS
			);
			self::client_redirect(
				$auth,
				array(
					'code'  => $code,
					'state' => $auth['state'],
					'iss'   => self::issuer(),
				)
			);
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		?>
		<!doctype html>
		<html lang="en">
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width,initial-scale=1">
			<title><?php esc_html_e( 'Authorize BC MCP Abilities', 'bc-mcp-abilities' ); ?></title>
			<style>
				body{background:#f6f7f7;color:#1d2327;font:16px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;margin:0;padding:40px 18px}
				main{max-width:540px;margin:7vh auto;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:32px}
				h1{font-size:24px;margin-top:0}.note{color:#50575e}button{padding:11px 20px;border:0;border-radius:5px;cursor:pointer}
				.allow{background:#2271b1;color:#fff}.deny{background:#f0f0f1;margin-left:8px}
			</style>
		</head>
		<body><main>
		<h1><?php esc_html_e( 'Connect to BC MCP Abilities', 'bc-mcp-abilities' ); ?></h1>
		<p><?php esc_html_e( 'ChatGPT is requesting permission to use WordPress abilities as your currently signed-in user.', 'bc-mcp-abilities' ); ?></p>
		<p class="note"><?php esc_html_e( 'Your existing WordPress permissions still apply. Write abilities remain disabled unless the site administrator enables them individually.', 'bc-mcp-abilities' ); ?></p>
		<p><strong><?php esc_html_e( 'Requested access:', 'bc-mcp-abilities' ); ?></strong> <?php echo esc_html( $auth['scope'] ); ?></p>
		<form method="post" action="<?php echo esc_url( home_url( '/bc-mcp/oauth/authorize' ) ); ?>">
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
		</main></body></html>
		<?php
		exit;
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
	 * @param array $auth    Validated authorization state.
	 * @param int   $user_id WordPress user ID.
	 * @return array
	 */
	private static function issue_tokens( $auth, $user_id ) {
		$access = bin2hex( random_bytes( 32 ) );
		$expiry = HOUR_IN_SECONDS;
		$record = array(
			'user_id'  => (int) $user_id,
			'resource' => $auth['resource'],
			'client'   => $auth['client_id'],
			'scope'    => $auth['scope'],
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
			set_transient( 'bc_mcp_refresh_' . hash( 'sha256', $refresh ), $record, 30 * DAY_IN_SECONDS );
			$result['refresh_token'] = $refresh;
		}
		return $result;
	}

	/**
	 * Rate-limit redemption of an OAuth one-use credential via a unique option.
	 *
	 * @param string $hash Hash of the code/token.
	 * @return bool
	 */
	private static function consume_lock( $hash ) {
		$key = 'bc_mcp_used_' . $hash;
		if ( ! add_option( $key, time(), '', false ) ) {
			return false;
		}
		wp_schedule_single_event( time() + 600, 'bc_mcp_oauth_remove_lock', array( $key ) );
		return true;
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
		if ( ! isset( self::clients()[ $client ] ) || BC_MCP_Abilities_Server::endpoint() !== $uri ) {
			self::fail( 'invalid_client' );
		}

		if ( 'authorization_code' === $grant ) {
			$code   = self::field( 'code', self::submitted() );
			$hash   = hash( 'sha256', $code );
			$stored = get_transient( 'bc_mcp_auth_code_' . $hash );
			if ( ! is_array( $stored ) || ! isset( $stored['auth'], $stored['user_id'] ) ) {
				self::fail( 'invalid_grant' );
			}
			$auth = $stored['auth'];
			if ( $auth['client_id'] !== $client || self::field( 'redirect_uri', self::submitted() ) !== $auth['redirect_uri'] || $auth['resource'] !== $uri || ! self::pkce_valid( self::field( 'code_verifier', self::submitted() ), $auth['challenge'] ) ) {
				self::fail( 'invalid_grant' );
			}
			if ( ! self::consume_lock( $hash ) ) {
				self::fail( 'invalid_grant' );
			}
			delete_transient( 'bc_mcp_auth_code_' . $hash );
			self::json( self::issue_tokens( $auth, $stored['user_id'] ) );
		}

		if ( 'refresh_token' === $grant ) {
			$refresh = self::field( 'refresh_token', self::submitted() );
			$hash    = hash( 'sha256', $refresh );
			$stored  = get_transient( 'bc_mcp_refresh_' . $hash );
			if ( ! is_array( $stored ) || ( $stored['client'] ?? '' ) !== $client || ( $stored['resource'] ?? '' ) !== $uri || ! get_userdata( $stored['user_id'] ?? 0 ) ) {
				self::fail( 'invalid_grant' );
			}
			if ( ! self::consume_lock( $hash ) ) {
				self::fail( 'invalid_grant' );
			}
			delete_transient( 'bc_mcp_refresh_' . $hash );
			$auth = array(
				'client_id' => $client,
				'resource'  => $stored['resource'],
				'scope'     => $stored['scope'],
			);
			self::json( self::issue_tokens( $auth, $stored['user_id'] ) );
		}
		self::fail( 'unsupported_grant_type' );
	}

	/**
	 * Delete an access or refresh token by hash without exposing its record.
	 *
	 * @return void
	 */
	private static function revoke() {
		if ( 'POST' !== self::request_method() ) {
			self::fail( 'invalid_request', 405 );
		}
		$client = self::field( 'client_id', self::submitted() );
		$token  = self::field( 'token', self::submitted() );
		if ( ! isset( self::clients()[ $client ] ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			self::fail( 'invalid_request' );
		}
		$hash = hash( 'sha256', $token );
		foreach ( array( 'bc_mcp_access_', 'bc_mcp_refresh_' ) as $prefix ) {
			$entry = get_transient( $prefix . $hash );
			if ( is_array( $entry ) && ( $entry['client'] ?? '' ) === $client ) {
				delete_transient( $prefix . $hash );
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
		if ( ! self::enabled() || ! is_string( $header ) || ! preg_match( '/^Bearer ([a-f0-9]{64})$/i', trim( $header ), $match ) ) {
			return new WP_Error( 'bc_mcp_oauth_invalid' );
		}
		$record = get_transient( 'bc_mcp_access_' . hash( 'sha256', $match[1] ) );
		if ( ! is_array( $record ) || ( $record['resource'] ?? '' ) !== BC_MCP_Abilities_Server::endpoint() || ! in_array( 'mcp', explode( ' ', (string) ( $record['scope'] ?? '' ) ), true ) ) {
			return new WP_Error( 'bc_mcp_oauth_invalid' );
		}
		$id = isset( $record['user_id'] ) ? absint( $record['user_id'] ) : 0;
		if ( ! $id || ! get_userdata( $id ) || ! user_can( $id, 'edit_posts' ) ) {
			return new WP_Error( 'bc_mcp_oauth_denied' );
		}
		return $id;
	}
}
, 'index.php?bc_mcp_oauth_route=protected', 'top' );
		add_rewrite_rule( '^\.well-known/oauth-authorization-server/bc-mcp/? 'index.php?bc_mcp_oauth_route=metadata', 'top' );
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
		if ( '1' === (string) get_option( 'bc_mcp_oauth_rewrite_version', '0' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'bc_mcp_oauth_rewrite_version', '1', false );
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
		if ( ! is_string( $route ) || ! in_array( $route, array( 'metadata', 'register', 'authorize', 'token', 'revoke' ), true ) ) {
			return;
		}
		if ( ! self::enabled() ) {
			status_header( 404 );
			exit;
		}

		switch ( $route ) {
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
	 * Machine-readable OAuth Authorization Server Metadata.
	 *
	 * @return array
	 */
	public static function authorization_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => home_url( '/bc-mcp/oauth/authorize' ),
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
	 * Retrieve a sanitized string from POST or GET.
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

	/**
	 * Return a site-limited set of OAuth public client records.
	 *
	 * @return array
	 */
	private static function clients() {
		$clients = get_option( self::CLIENTS_OPTION, array() );
		return is_array( $clients ) ? $clients : array();
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

		$clients = self::clients();
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			self::fail( 'too_many_clients', 429 );
		}
		$id             = 'bc_' . bin2hex( random_bytes( 24 ) );
		$clients[ $id ] = array(
			'redirect_uris' => array_values( $body['redirect_uris'] ),
			'client_name'   => sanitize_text_field( (string) ( $body['client_name'] ?? 'ChatGPT' ) ),
			'created_at'    => time(),
		);
		update_option( self::CLIENTS_OPTION, $clients, false );

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

		if ( ! isset( $clients[ $client_id ] ) || ! in_array( $redirect, $clients[ $client_id ]['redirect_uris'], true ) || ! self::allowed_redirect( $redirect ) ) {
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
	 * Redirect a browser to a previously verified OAuth client callback.
	 *
	 * @param array $auth       Validated authorization state.
	 * @param array $parameters Redirect query variables.
	 * @return void
	 */
	private static function client_redirect( $auth, $parameters ) {
		$url = add_query_arg( $parameters, $auth['redirect_uri'] );
		// Only redirect to the exact URI that was validated against the registration record.
		wp_redirect( esc_url_raw( $url ), 302, 'BC MCP OAuth' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Exact registered HTTPS ChatGPT callback.
		exit;
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
		$input   = $is_post ? self::submitted() : self::query_input(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth clients use state and PKCE, POST is nonce-protected below.
		$auth    = self::validated_authorization( $input );
		if ( is_wp_error( $auth ) ) {
			self::fail( $auth->get_error_code() );
		}
		if ( ! is_user_logged_in() ) {
			$destination = home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/bc-mcp/oauth/authorize' );
			wp_safe_redirect( wp_login_url( $destination ), 302 );
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
				),
				5 * MINUTE_IN_SECONDS
			);
			self::client_redirect(
				$auth,
				array(
					'code'  => $code,
					'state' => $auth['state'],
					'iss'   => self::issuer(),
				)
			);
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		?>
		<!doctype html>
		<html lang="en">
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width,initial-scale=1">
			<title><?php esc_html_e( 'Authorize BC MCP Abilities', 'bc-mcp-abilities' ); ?></title>
			<style>
				body{background:#f6f7f7;color:#1d2327;font:16px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;margin:0;padding:40px 18px}
				main{max-width:540px;margin:7vh auto;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:32px}
				h1{font-size:24px;margin-top:0}.note{color:#50575e}button{padding:11px 20px;border:0;border-radius:5px;cursor:pointer}
				.allow{background:#2271b1;color:#fff}.deny{background:#f0f0f1;margin-left:8px}
			</style>
		</head>
		<body><main>
		<h1><?php esc_html_e( 'Connect to BC MCP Abilities', 'bc-mcp-abilities' ); ?></h1>
		<p><?php esc_html_e( 'ChatGPT is requesting permission to use WordPress abilities as your currently signed-in user.', 'bc-mcp-abilities' ); ?></p>
		<p class="note"><?php esc_html_e( 'Your existing WordPress permissions still apply. Write abilities remain disabled unless the site administrator enables them individually.', 'bc-mcp-abilities' ); ?></p>
		<p><strong><?php esc_html_e( 'Requested access:', 'bc-mcp-abilities' ); ?></strong> <?php echo esc_html( $auth['scope'] ); ?></p>
		<form method="post" action="<?php echo esc_url( home_url( '/bc-mcp/oauth/authorize' ) ); ?>">
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
		</main></body></html>
		<?php
		exit;
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
	 * @param array $auth    Validated authorization state.
	 * @param int   $user_id WordPress user ID.
	 * @return array
	 */
	private static function issue_tokens( $auth, $user_id ) {
		$access = bin2hex( random_bytes( 32 ) );
		$expiry = HOUR_IN_SECONDS;
		$record = array(
			'user_id'  => (int) $user_id,
			'resource' => $auth['resource'],
			'client'   => $auth['client_id'],
			'scope'    => $auth['scope'],
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
			set_transient( 'bc_mcp_refresh_' . hash( 'sha256', $refresh ), $record, 30 * DAY_IN_SECONDS );
			$result['refresh_token'] = $refresh;
		}
		return $result;
	}

	/**
	 * Rate-limit redemption of an OAuth one-use credential via a unique option.
	 *
	 * @param string $hash Hash of the code/token.
	 * @return bool
	 */
	private static function consume_lock( $hash ) {
		$key = 'bc_mcp_used_' . $hash;
		if ( ! add_option( $key, time(), '', false ) ) {
			return false;
		}
		wp_schedule_single_event( time() + 600, 'bc_mcp_oauth_remove_lock', array( $key ) );
		return true;
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
		if ( ! isset( self::clients()[ $client ] ) || BC_MCP_Abilities_Server::endpoint() !== $uri ) {
			self::fail( 'invalid_client' );
		}

		if ( 'authorization_code' === $grant ) {
			$code   = self::field( 'code', self::submitted() );
			$hash   = hash( 'sha256', $code );
			$stored = get_transient( 'bc_mcp_auth_code_' . $hash );
			if ( ! is_array( $stored ) || ! isset( $stored['auth'], $stored['user_id'] ) ) {
				self::fail( 'invalid_grant' );
			}
			$auth = $stored['auth'];
			if ( $auth['client_id'] !== $client || self::field( 'redirect_uri', self::submitted() ) !== $auth['redirect_uri'] || $auth['resource'] !== $uri || ! self::pkce_valid( self::field( 'code_verifier', self::submitted() ), $auth['challenge'] ) ) {
				self::fail( 'invalid_grant' );
			}
			if ( ! self::consume_lock( $hash ) ) {
				self::fail( 'invalid_grant' );
			}
			delete_transient( 'bc_mcp_auth_code_' . $hash );
			self::json( self::issue_tokens( $auth, $stored['user_id'] ) );
		}

		if ( 'refresh_token' === $grant ) {
			$refresh = self::field( 'refresh_token', self::submitted() );
			$hash    = hash( 'sha256', $refresh );
			$stored  = get_transient( 'bc_mcp_refresh_' . $hash );
			if ( ! is_array( $stored ) || ( $stored['client'] ?? '' ) !== $client || ( $stored['resource'] ?? '' ) !== $uri || ! get_userdata( $stored['user_id'] ?? 0 ) ) {
				self::fail( 'invalid_grant' );
			}
			if ( ! self::consume_lock( $hash ) ) {
				self::fail( 'invalid_grant' );
			}
			delete_transient( 'bc_mcp_refresh_' . $hash );
			$auth = array(
				'client_id' => $client,
				'resource'  => $stored['resource'],
				'scope'     => $stored['scope'],
			);
			self::json( self::issue_tokens( $auth, $stored['user_id'] ) );
		}
		self::fail( 'unsupported_grant_type' );
	}

	/**
	 * Delete an access or refresh token by hash without exposing its record.
	 *
	 * @return void
	 */
	private static function revoke() {
		if ( 'POST' !== self::request_method() ) {
			self::fail( 'invalid_request', 405 );
		}
		$client = self::field( 'client_id', self::submitted() );
		$token  = self::field( 'token', self::submitted() );
		if ( ! isset( self::clients()[ $client ] ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			self::fail( 'invalid_request' );
		}
		$hash = hash( 'sha256', $token );
		foreach ( array( 'bc_mcp_access_', 'bc_mcp_refresh_' ) as $prefix ) {
			$entry = get_transient( $prefix . $hash );
			if ( is_array( $entry ) && ( $entry['client'] ?? '' ) === $client ) {
				delete_transient( $prefix . $hash );
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
		if ( ! self::enabled() || ! is_string( $header ) || ! preg_match( '/^Bearer ([a-f0-9]{64})$/i', trim( $header ), $match ) ) {
			return new WP_Error( 'bc_mcp_oauth_invalid' );
		}
		$record = get_transient( 'bc_mcp_access_' . hash( 'sha256', $match[1] ) );
		if ( ! is_array( $record ) || ( $record['resource'] ?? '' ) !== BC_MCP_Abilities_Server::endpoint() || ! in_array( 'mcp', explode( ' ', (string) ( $record['scope'] ?? '' ) ), true ) ) {
			return new WP_Error( 'bc_mcp_oauth_invalid' );
		}
		$id = isset( $record['user_id'] ) ? absint( $record['user_id'] ) : 0;
		if ( ! $id || ! get_userdata( $id ) || ! user_can( $id, 'edit_posts' ) ) {
			return new WP_Error( 'bc_mcp_oauth_denied' );
		}
		return $id;
	}
}
, 'index.php?bc_mcp_oauth_route=metadata', 'top' );
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
		if ( '1' === (string) get_option( 'bc_mcp_oauth_rewrite_version', '0' ) ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'bc_mcp_oauth_rewrite_version', '1', false );
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
		if ( ! is_string( $route ) || ! in_array( $route, array( 'metadata', 'register', 'authorize', 'token', 'revoke' ), true ) ) {
			return;
		}
		if ( ! self::enabled() ) {
			status_header( 404 );
			exit;
		}

		switch ( $route ) {
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
	 * Machine-readable OAuth Authorization Server Metadata.
	 *
	 * @return array
	 */
	public static function authorization_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => home_url( '/bc-mcp/oauth/authorize' ),
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
	 * Retrieve a sanitized string from POST or GET.
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

	/**
	 * Return a site-limited set of OAuth public client records.
	 *
	 * @return array
	 */
	private static function clients() {
		$clients = get_option( self::CLIENTS_OPTION, array() );
		return is_array( $clients ) ? $clients : array();
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

		$clients = self::clients();
		if ( count( $clients ) >= self::MAX_CLIENTS ) {
			self::fail( 'too_many_clients', 429 );
		}
		$id             = 'bc_' . bin2hex( random_bytes( 24 ) );
		$clients[ $id ] = array(
			'redirect_uris' => array_values( $body['redirect_uris'] ),
			'client_name'   => sanitize_text_field( (string) ( $body['client_name'] ?? 'ChatGPT' ) ),
			'created_at'    => time(),
		);
		update_option( self::CLIENTS_OPTION, $clients, false );

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

		if ( ! isset( $clients[ $client_id ] ) || ! in_array( $redirect, $clients[ $client_id ]['redirect_uris'], true ) || ! self::allowed_redirect( $redirect ) ) {
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
	 * Redirect a browser to a previously verified OAuth client callback.
	 *
	 * @param array $auth       Validated authorization state.
	 * @param array $parameters Redirect query variables.
	 * @return void
	 */
	private static function client_redirect( $auth, $parameters ) {
		$url = add_query_arg( $parameters, $auth['redirect_uri'] );
		// Only redirect to the exact URI that was validated against the registration record.
		wp_redirect( esc_url_raw( $url ), 302, 'BC MCP OAuth' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Exact registered HTTPS ChatGPT callback.
		exit;
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
		$input   = $is_post ? self::submitted() : self::query_input(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth clients use state and PKCE, POST is nonce-protected below.
		$auth    = self::validated_authorization( $input );
		if ( is_wp_error( $auth ) ) {
			self::fail( $auth->get_error_code() );
		}
		if ( ! is_user_logged_in() ) {
			$destination = home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/bc-mcp/oauth/authorize' );
			wp_safe_redirect( wp_login_url( $destination ), 302 );
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
				),
				5 * MINUTE_IN_SECONDS
			);
			self::client_redirect(
				$auth,
				array(
					'code'  => $code,
					'state' => $auth['state'],
					'iss'   => self::issuer(),
				)
			);
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		?>
		<!doctype html>
		<html lang="en">
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width,initial-scale=1">
			<title><?php esc_html_e( 'Authorize BC MCP Abilities', 'bc-mcp-abilities' ); ?></title>
			<style>
				body{background:#f6f7f7;color:#1d2327;font:16px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;margin:0;padding:40px 18px}
				main{max-width:540px;margin:7vh auto;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:32px}
				h1{font-size:24px;margin-top:0}.note{color:#50575e}button{padding:11px 20px;border:0;border-radius:5px;cursor:pointer}
				.allow{background:#2271b1;color:#fff}.deny{background:#f0f0f1;margin-left:8px}
			</style>
		</head>
		<body><main>
		<h1><?php esc_html_e( 'Connect to BC MCP Abilities', 'bc-mcp-abilities' ); ?></h1>
		<p><?php esc_html_e( 'ChatGPT is requesting permission to use WordPress abilities as your currently signed-in user.', 'bc-mcp-abilities' ); ?></p>
		<p class="note"><?php esc_html_e( 'Your existing WordPress permissions still apply. Write abilities remain disabled unless the site administrator enables them individually.', 'bc-mcp-abilities' ); ?></p>
		<p><strong><?php esc_html_e( 'Requested access:', 'bc-mcp-abilities' ); ?></strong> <?php echo esc_html( $auth['scope'] ); ?></p>
		<form method="post" action="<?php echo esc_url( home_url( '/bc-mcp/oauth/authorize' ) ); ?>">
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
		</main></body></html>
		<?php
		exit;
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
	 * @param array $auth    Validated authorization state.
	 * @param int   $user_id WordPress user ID.
	 * @return array
	 */
	private static function issue_tokens( $auth, $user_id ) {
		$access = bin2hex( random_bytes( 32 ) );
		$expiry = HOUR_IN_SECONDS;
		$record = array(
			'user_id'  => (int) $user_id,
			'resource' => $auth['resource'],
			'client'   => $auth['client_id'],
			'scope'    => $auth['scope'],
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
			set_transient( 'bc_mcp_refresh_' . hash( 'sha256', $refresh ), $record, 30 * DAY_IN_SECONDS );
			$result['refresh_token'] = $refresh;
		}
		return $result;
	}

	/**
	 * Rate-limit redemption of an OAuth one-use credential via a unique option.
	 *
	 * @param string $hash Hash of the code/token.
	 * @return bool
	 */
	private static function consume_lock( $hash ) {
		$key = 'bc_mcp_used_' . $hash;
		if ( ! add_option( $key, time(), '', false ) ) {
			return false;
		}
		wp_schedule_single_event( time() + 600, 'bc_mcp_oauth_remove_lock', array( $key ) );
		return true;
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
		if ( ! isset( self::clients()[ $client ] ) || BC_MCP_Abilities_Server::endpoint() !== $uri ) {
			self::fail( 'invalid_client' );
		}

		if ( 'authorization_code' === $grant ) {
			$code   = self::field( 'code', self::submitted() );
			$hash   = hash( 'sha256', $code );
			$stored = get_transient( 'bc_mcp_auth_code_' . $hash );
			if ( ! is_array( $stored ) || ! isset( $stored['auth'], $stored['user_id'] ) ) {
				self::fail( 'invalid_grant' );
			}
			$auth = $stored['auth'];
			if ( $auth['client_id'] !== $client || self::field( 'redirect_uri', self::submitted() ) !== $auth['redirect_uri'] || $auth['resource'] !== $uri || ! self::pkce_valid( self::field( 'code_verifier', self::submitted() ), $auth['challenge'] ) ) {
				self::fail( 'invalid_grant' );
			}
			if ( ! self::consume_lock( $hash ) ) {
				self::fail( 'invalid_grant' );
			}
			delete_transient( 'bc_mcp_auth_code_' . $hash );
			self::json( self::issue_tokens( $auth, $stored['user_id'] ) );
		}

		if ( 'refresh_token' === $grant ) {
			$refresh = self::field( 'refresh_token', self::submitted() );
			$hash    = hash( 'sha256', $refresh );
			$stored  = get_transient( 'bc_mcp_refresh_' . $hash );
			if ( ! is_array( $stored ) || ( $stored['client'] ?? '' ) !== $client || ( $stored['resource'] ?? '' ) !== $uri || ! get_userdata( $stored['user_id'] ?? 0 ) ) {
				self::fail( 'invalid_grant' );
			}
			if ( ! self::consume_lock( $hash ) ) {
				self::fail( 'invalid_grant' );
			}
			delete_transient( 'bc_mcp_refresh_' . $hash );
			$auth = array(
				'client_id' => $client,
				'resource'  => $stored['resource'],
				'scope'     => $stored['scope'],
			);
			self::json( self::issue_tokens( $auth, $stored['user_id'] ) );
		}
		self::fail( 'unsupported_grant_type' );
	}

	/**
	 * Delete an access or refresh token by hash without exposing its record.
	 *
	 * @return void
	 */
	private static function revoke() {
		if ( 'POST' !== self::request_method() ) {
			self::fail( 'invalid_request', 405 );
		}
		$client = self::field( 'client_id', self::submitted() );
		$token  = self::field( 'token', self::submitted() );
		if ( ! isset( self::clients()[ $client ] ) || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			self::fail( 'invalid_request' );
		}
		$hash = hash( 'sha256', $token );
		foreach ( array( 'bc_mcp_access_', 'bc_mcp_refresh_' ) as $prefix ) {
			$entry = get_transient( $prefix . $hash );
			if ( is_array( $entry ) && ( $entry['client'] ?? '' ) === $client ) {
				delete_transient( $prefix . $hash );
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
		if ( ! self::enabled() || ! is_string( $header ) || ! preg_match( '/^Bearer ([a-f0-9]{64})$/i', trim( $header ), $match ) ) {
			return new WP_Error( 'bc_mcp_oauth_invalid' );
		}
		$record = get_transient( 'bc_mcp_access_' . hash( 'sha256', $match[1] ) );
		if ( ! is_array( $record ) || ( $record['resource'] ?? '' ) !== BC_MCP_Abilities_Server::endpoint() || ! in_array( 'mcp', explode( ' ', (string) ( $record['scope'] ?? '' ) ), true ) ) {
			return new WP_Error( 'bc_mcp_oauth_invalid' );
		}
		$id = isset( $record['user_id'] ) ? absint( $record['user_id'] ) : 0;
		if ( ! $id || ! get_userdata( $id ) || ! user_can( $id, 'edit_posts' ) ) {
			return new WP_Error( 'bc_mcp_oauth_denied' );
		}
		return $id;
	}
}
