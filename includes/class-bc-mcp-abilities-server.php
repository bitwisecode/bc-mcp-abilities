<?php
/**
 * Built-in, adapter-independent MCP HTTP transport for BC WordPress abilities.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * A bounded stateless MCP transport that uses only the WordPress REST API.
 */
final class BC_MCP_Abilities_Server {

	/**
	 * Server enablement option. Default: disabled.
	 */
	const OPTION = 'bc_mcp_standalone_enabled';

	/**
	 * MCP protocol revisions this stateless JSON transport implements, newest first.
	 */
	const PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26' );

	/**
	 * Protocol methods that return only static server metadata, never site data.
	 */
	const PUBLIC_METHODS = array( 'initialize', 'notifications/initialized', 'tools/list', 'ping' );

	/**
	 * Whether the current request presented a valid Bearer token (diagnostics only).
	 *
	 * @var bool
	 */
	private static $authenticated = false;

	/**
	 * Request already recorded by authorize(). WordPress re-runs permission
	 * callbacks while building the Allow header (rest_send_allow_header), which
	 * would otherwise log every rejected request twice.
	 *
	 * @var string
	 */
	private static $traced_request = '';

	/**
	 * Register WordPress REST routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'end_request' ), 99 );
	}

	/**
	 * Reset per-request diagnostic state after the Allow header is built.
	 *
	 * @param WP_HTTP_Response $response Response.
	 * @return WP_HTTP_Response
	 */
	public static function end_request( $response ) {
		self::$traced_request = '';
		self::$authenticated  = false;
		return $response;
	}

	/**
	 * Negotiate the protocol revision per the MCP lifecycle rules: echo the
	 * client's requested version when supported, otherwise offer the latest.
	 *
	 * @param mixed $requested Client-requested protocol version.
	 * @return string
	 */
	public static function negotiate_version( $requested ) {
		if ( is_string( $requested ) && in_array( $requested, self::PROTOCOL_VERSIONS, true ) ) {
			return $requested;
		}
		return self::PROTOCOL_VERSIONS[0];
	}

	/**
	 * Map a JSON-RPC method to a fixed diagnostic label; never log arbitrary input.
	 *
	 * @param mixed $method JSON-RPC method.
	 * @return string
	 */
	public static function method_label( $method ) {
		$known = array( 'initialize', 'notifications/initialized', 'notifications/cancelled', 'ping', 'tools/list', 'tools/call', 'resources/list', 'resources/templates/list', 'prompts/list', 'logging/setLevel', 'completion/complete' );
		if ( ! is_string( $method ) || '' === $method ) {
			return 'none';
		}
		if ( in_array( $method, $known, true ) ) {
			return $method;
		}
		return 0 === strpos( $method, 'notifications/' ) ? 'notifications/other' : 'other';
	}

	/**
	 * Read the Authorization header, including the copies that some Apache and
	 * LiteSpeed CGI setups place in REDIRECT_HTTP_AUTHORIZATION.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return string
	 */
	public static function authorization_header( $request ) {
		$header = (string) $request->get_header( 'authorization' );
		if ( '' !== $header ) {
			return $header;
		}
		// Only fall back to the live HTTP request for the route it is serving.
		if ( '/bc-mcp/v1/server' !== $request->get_route() ) {
			return '';
		}
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ) {
				return trim( wp_unslash( $_SERVER[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Opaque credential, validated by strict regex in bearer_user().
			}
		}
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( is_string( $name ) && 'authorization' === strtolower( $name ) && is_string( $value ) ) {
					return trim( $value );
				}
			}
		}
		return '';
	}

	/**
	 * Whether standalone MCP connections are enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return '1' === (string) get_option( self::OPTION, '0' );
	}

	/**
	 * The canonical MCP resource URL.
	 *
	 * @return string
	 */
	public static function endpoint() {
		return rest_url( 'bc-mcp/v1/server' );
	}

	/**
	 * Register the MCP resource route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			'bc-mcp/v1',
			'/server',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'respond' ),
					'permission_callback' => array( __CLASS__, 'authorize' ),
				),
				array(
					'methods'             => 'GET, DELETE',
					'callback'            => array( __CLASS__, 'unsupported_method' ),
					'permission_callback' => '__return_true',
				),
			)
		);
		register_rest_route(
			'bc-mcp/v1',
			'/oauth/resource',
			array(
				'methods'             => 'GET',
				'callback'            => array( 'BC_MCP_Abilities_OAuth', 'resource_metadata' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * A stateless MCP server has no event stream or session to delete.
	 *
	 * @return WP_REST_Response
	 */
	public static function unsupported_method() {
		$response = new WP_REST_Response( array( 'error' => 'Method not allowed.' ), 405 );
		$response->header( 'Allow', 'POST' );
		return $response;
	}

	/**
	 * Permit only authenticated and scoped requests for this MCP resource.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool|WP_Error
	 */
	public static function authorize( $request ) {
		if ( ! self::enabled() || ! BC_MCP_Abilities_OAuth::enabled() ) {
			return new WP_Error( 'bc_mcp_disabled', __( 'Standalone MCP is disabled.', 'bc-mcp-abilities' ), array( 'status' => 404 ) );
		}

		$message = $request->get_json_params();
		$method  = is_array( $message ) && isset( $message['method'] ) && is_string( $message['method'] ) ? $message['method'] : '';
		$label   = self::method_label( $method );
		$bearer  = self::authorization_header( $request );
		$auth    = '' === $bearer ? 'missing' : ( 0 === stripos( $bearer, 'Bearer ' ) ? 'bearer' : 'other_scheme' );

		// ChatGPT scans MCP tools before linking the WordPress account. These
		// protocol methods only return static server metadata, never site data,
		// so they are public when no credential is presented. Every other
		// method, including tools/call, requires a valid OAuth Bearer token.
		$public              = is_array( $message ) && '2.0' === ( $message['jsonrpc'] ?? '' ) && in_array( $method, self::PUBLIC_METHODS, true );
		self::$authenticated = false;
		if ( $public && 'missing' === $auth ) {
			return true;
		}

		$user = BC_MCP_Abilities_OAuth::bearer_user( $bearer );
		if ( is_wp_error( $user ) || ! $user ) {
			$reason = is_wp_error( $user ) ? $user->get_error_code() : '';
			$detail = array(
				'bc_mcp_oauth_missing'        => 'token_missing',
				'bc_mcp_oauth_malformed'      => 'token_malformed',
				'bc_mcp_oauth_unknown'        => 'token_unknown_or_expired',
				'bc_mcp_oauth_wrong_target'   => 'token_resource_mismatch',
				'bc_mcp_oauth_scope'          => 'token_scope_insufficient',
				'bc_mcp_oauth_denied'         => 'user_not_permitted',
				'bc_mcp_oauth_disabled'       => 'oauth_disabled',
				'bc_mcp_oauth_revoked'        => 'token_revoked',
				'bc_mcp_oauth_client_removed' => 'client_removed',
			);
			$detail = $detail[ $reason ] ?? 'token_invalid';
			// RFC 6750 section 3.1: no error code when the request carried no credential.
			self::challenge( 'missing' === $auth ? '' : ( 'token_scope_insufficient' === $detail ? 'insufficient_scope' : 'invalid_token' ) );
			self::trace_once(
				$request,
				'unauthorized',
				array(
					'method' => $label,
					'auth'   => $auth,
					'detail' => $detail,
				)
			);
			if ( 'token_scope_insufficient' === $detail ) {
				return new WP_Error( 'bc_mcp_insufficient_scope', __( 'The access token lacks the mcp scope.', 'bc-mcp-abilities' ), array( 'status' => 403 ) );
			}
			return new WP_Error( 'bc_mcp_unauthorized', __( 'OAuth authorization required.', 'bc-mcp-abilities' ), array( 'status' => 401 ) );
		}

		wp_set_current_user( (int) $user );
		if ( ! current_user_can( 'edit_posts' ) ) {
			self::trace_once(
				$request,
				'forbidden',
				array(
					'method' => $label,
					'auth'   => $auth,
					'detail' => 'user_not_permitted',
				)
			);
			return new WP_Error( 'bc_mcp_forbidden', __( 'This user lacks WordPress editing permissions.', 'bc-mcp-abilities' ), array( 'status' => 403 ) );
		}
		self::$authenticated = true;
		return true;
	}

	/**
	 * Record an authorization outcome once per HTTP request.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @param string          $result  Diagnostic result.
	 * @param array           $context Diagnostic labels.
	 * @return void
	 */
	private static function trace_once( $request, $result, $context ) {
		$key = spl_object_hash( $request );
		if ( self::$traced_request === $key ) {
			return;
		}
		self::$traced_request = $key;
		BC_MCP_Abilities_OAuth::trace( 'mcp', $result, $context );
	}

	/**
	 * Emit the OAuth discovery hint required by remote MCP clients.
	 *
	 * @param string $error RFC 6750 error code, or empty when no credential was sent.
	 * @return void
	 */
	public static function challenge( $error = '' ) {
		$metadata = esc_url_raw( home_url( '/' . BC_MCP_Abilities_OAuth::resource_metadata_path() ) );
		$value    = 'Bearer resource_metadata="' . $metadata . '", scope="mcp"';
		if ( in_array( $error, array( 'invalid_token', 'insufficient_scope' ), true ) ) {
			$value .= ', error="' . $error . '"';
		}
		header( 'WWW-Authenticate: ' . $value );
	}

	/**
	 * Create the JSON-RPC response envelope.
	 *
	 * @param mixed  $id      Request identifier.
	 * @param mixed  $result  Result content.
	 * @param string $version Negotiated protocol version for the response header.
	 * @return WP_REST_Response
	 */
	private static function success( $id, $result, $version = '' ) {
		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
		$response->header( 'MCP-Protocol-Version', '' !== $version ? $version : self::PROTOCOL_VERSIONS[0] );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Create a JSON-RPC error.
	 *
	 * @param mixed  $id      Request identifier.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Error message.
	 * @return WP_REST_Response
	 */
	private static function error( $id, $code, $message ) {
		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => (int) $code,
					'message' => (string) $message,
				),
			),
			200
		);
		$response->header( 'MCP-Protocol-Version', self::PROTOCOL_VERSIONS[0] );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Return three discoverable tools; abilities are invoked through them.
	 *
	 * @return array
	 */
	private static function tools() {
		// JSON Schema requires "properties" to be an object. An empty PHP array
		// encodes as [] which OpenAI/ChatGPT reject when importing tools.
		$schema = array(
			'type'                 => 'object',
			'properties'           => new stdClass(),
			'additionalProperties' => false,
		);
		// ChatGPT can read the three tool descriptors without authenticating,
		// then present WordPress OAuth before executing any of them.
		$auth_scheme = array(
			array(
				'type'   => 'oauth2',
				'scopes' => array( 'mcp' ),
			),
		);

		return array(
			array(
				'name'            => 'bc-list-abilities',
				'title'           => 'List BC WordPress Abilities',
				'description'     => 'Discover permitted WordPress abilities registered by BC MCP Abilities.',
				'inputSchema'     => $schema,
				'securitySchemes' => $auth_scheme,
				'annotations'     => array( 'readOnlyHint' => true ),
			),
			array(
				'name'            => 'bc-get-ability-info',
				'title'           => 'Describe a BC WordPress Ability',
				'description'     => 'Get the input schema, output schema and description of one BC ability before using it.',
				'inputSchema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'ability_name' => array(
							'type'      => 'string',
							'minLength' => 3,
						),
					),
					'required'             => array( 'ability_name' ),
					'additionalProperties' => false,
				),
				'securitySchemes' => $auth_scheme,
				'annotations'     => array( 'readOnlyHint' => true ),
			),
			array(
				'name'            => 'bc-execute-ability',
				'title'           => 'Execute a Permitted BC WordPress Ability',
				'description'     => 'Execute a BC ability. WordPress permission and admin write opt-in controls always apply. Inspect the schema and confirm writes before calling.',
				'inputSchema'     => array(
					'type'                 => 'object',
					'properties'           => array(
						'ability_name' => array(
							'type'      => 'string',
							'minLength' => 3,
						),
						'parameters'   => array(
							'type'                 => 'object',
							'additionalProperties' => true,
						),
						'confirmed'    => array(
							'type'        => 'boolean',
							'description' => 'True only after the user explicitly approves this WordPress write.',
						),
					),
					'required'             => array( 'ability_name', 'parameters' ),
					'additionalProperties' => false,
				),
				'securitySchemes' => $auth_scheme,
				'annotations'     => array(
					'readOnlyHint'    => false,
					'destructiveHint' => true,
				),
			),
		);
	}

	/**
	 * Resolve only BC abilities and never expose other plugins' operations.
	 *
	 * @param string $name Complete ability name.
	 * @return WP_Ability|null
	 */
	private static function bc_ability( $name ) {
		if ( ! is_string( $name ) || 0 !== strpos( $name, 'bc-mcp-abilities/' ) ) {
			return null;
		}
		if ( ! function_exists( 'wp_get_ability' ) || ! preg_match( '~^bc-mcp-abilities/[a-z0-9-]+$~', $name ) ) {
			return null;
		}
		return wp_get_ability( $name );
	}

	/**
	 * Describe a BC ability.
	 *
	 * @param WP_Ability $ability Resolved ability instance.
	 * @return array
	 */
	private static function ability_info( $ability ) {
		return array(
			'name'          => $ability->get_name(),
			'label'         => $ability->get_label(),
			'description'   => $ability->get_description(),
			'input_schema'  => $ability->get_input_schema(),
			'output_schema' => $ability->get_output_schema(),
		);
	}

	/**
	 * List abilities that the user is permitted to run.
	 *
	 * @return array
	 */
	private static function list_abilities() {
		$items = array();
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return $items;
		}
		foreach ( wp_get_abilities() as $ability ) {
			if ( 0 !== strpos( $ability->get_name(), 'bc-mcp-abilities/' ) ) {
				continue;
			}
			$permitted = $ability->check_permissions( array() );
			if ( true !== $permitted ) {
				continue;
			}
			$items[] = array(
				'name'        => $ability->get_name(),
				'label'       => $ability->get_label(),
				'description' => $ability->get_description(),
			);
		}
		return $items;
	}

	/**
	 * Return an MCP tool result without exposing PHP details.
	 *
	 * @param mixed $value   Tool result.
	 * @param bool  $is_error Whether execution failed.
	 * @return array
	 */
	private static function tool_result( $value, $is_error = false ) {
		$json = wp_json_encode( $value );
		if ( false !== $json && strlen( $json ) > 2 * 1024 * 1024 ) {
			$json     = '{"error":"Result too large. Narrow the WordPress query."}';
			$is_error = true;
		}
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => false !== $json ? $json : '{}',
				),
			),
			'isError' => (bool) $is_error,
		);
	}

	/**
	 * Handle the JSON-RPC MCP request.
	 *
	 * @param WP_REST_Request $request WordPress REST request.
	 * @return WP_REST_Response
	 */
	public static function respond( $request ) {
		$auth = self::$authenticated ? 'token_valid' : 'anonymous';
		$body = $request->get_body();
		if ( strlen( (string) $body ) > 1024 * 1024 ) {
			BC_MCP_Abilities_OAuth::trace(
				'mcp',
				'invalid_request',
				array(
					'auth'   => $auth,
					'detail' => 'too_large',
				)
			);
			return self::error( null, -32600, 'Request exceeds 1 MB.' );
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || '2.0' !== ( $data['jsonrpc'] ?? '' ) || ! isset( $data['method'] ) || ! is_string( $data['method'] ) ) {
			BC_MCP_Abilities_OAuth::trace(
				'mcp',
				'invalid_request',
				array(
					'auth'   => $auth,
					'detail' => is_array( $data ) && array_key_exists( 0, $data ) ? 'batch_unsupported' : 'not_jsonrpc',
				)
			);
			return self::error( null, -32600, 'Invalid JSON-RPC request.' );
		}
		$id     = array_key_exists( 'id', $data ) ? $data['id'] : null;
		$method = (string) $data['method'];
		$params = isset( $data['params'] ) && is_array( $data['params'] ) ? $data['params'] : array();
		$label  = self::method_label( $method );
		$trace  = array(
			'method' => $label,
			'auth'   => $auth,
		);

		// JSON-RPC notifications never receive a response body: 202 Accepted.
		if ( 0 === strpos( $method, 'notifications/' ) ) {
			return new WP_REST_Response( null, 202 );
		}

		switch ( $method ) {
			case 'initialize':
				$requested       = $params['protocolVersion'] ?? '';
				$version         = self::negotiate_version( $requested );
				$trace['detail'] = $version === $requested ? 'protocol_' . $version : 'protocol_mismatch_offered_' . $version;
				BC_MCP_Abilities_OAuth::trace( 'mcp', 'initialized', $trace );
				return self::success(
					$id,
					array(
						'protocolVersion' => $version,
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array(
							'name'    => 'bc-mcp-abilities',
							'version' => BC_MCP_ABILITIES_VERSION,
						),
						'instructions'    => 'Use bc-list-abilities first. Inspect schemas before invoking. Never change WordPress content without explicit user approval.',
					),
					$version
				);
			case 'ping':
				return self::success( $id, (object) array() );
			case 'tools/list':
				BC_MCP_Abilities_OAuth::trace( 'mcp', 'listed', $trace );
				return self::success( $id, array( 'tools' => self::tools() ) );
			case 'tools/call':
				$name            = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';
				$args            = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
				$result          = self::call_tool( $name, $args );
				$trace['detail'] = in_array( $name, array( 'bc-list-abilities', 'bc-get-ability-info', 'bc-execute-ability' ), true ) ? $name : 'unknown_tool';
				BC_MCP_Abilities_OAuth::trace( 'mcp', $result['isError'] ? 'call_failed' : 'call_succeeded', $trace );
				return self::success( $id, $result );
		}

		BC_MCP_Abilities_OAuth::trace( 'mcp', 'unknown_method', $trace );
		return self::error( $id, -32601, 'Method not found.' );
	}

	/**
	 * Run one of the three MCP tools for the authenticated WordPress user.
	 *
	 * @param string $name Tool name.
	 * @param array  $args Tool arguments.
	 * @return array MCP tool result.
	 */
	private static function call_tool( $name, $args ) {
		if ( 'bc-list-abilities' === $name ) {
			return self::tool_result( array( 'abilities' => self::list_abilities() ) );
		}
		$target  = isset( $args['ability_name'] ) ? $args['ability_name'] : '';
		$ability = self::bc_ability( $target );
		if ( ! $ability ) {
			return self::tool_result( array( 'error' => 'Unknown BC ability.' ), true );
		}
		if ( 'bc-get-ability-info' === $name ) {
			if ( true !== $ability->check_permissions( array() ) ) {
				return self::tool_result( array( 'error' => 'This ability is not available to the connected WordPress user.' ), true );
			}
			return self::tool_result( self::ability_info( $ability ) );
		}
		if ( 'bc-execute-ability' === $name ) {
			$slug   = substr( (string) $target, strlen( 'bc-mcp-abilities/' ) );
			$writes = array_keys( BC_MCP_Abilities_Admin::writable_abilities() );
			if ( in_array( $slug, $writes, true ) && true !== ( $args['confirmed'] ?? false ) ) {
				return self::tool_result( array( 'error' => 'Explicit user confirmation is required for WordPress writes.' ), true );
			}
			$input  = isset( $args['parameters'] ) && is_array( $args['parameters'] ) ? $args['parameters'] : array();
			$output = $ability->execute( $input );
			if ( is_wp_error( $output ) ) {
				return self::tool_result( array( 'error' => $output->get_error_message() ), true );
			}
			return self::tool_result( $output );
		}
		return self::tool_result( array( 'error' => 'Unknown MCP tool.' ), true );
	}
}
