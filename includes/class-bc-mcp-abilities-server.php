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
	 * Register WordPress REST routes.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
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
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'respond' ),
				'permission_callback' => array( __CLASS__, 'authorize' ),
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
	 * Permit only authenticated and scoped requests for this MCP resource.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool|WP_Error
	 */
	public static function authorize( $request ) {
		if ( ! self::enabled() || ! BC_MCP_Abilities_OAuth::enabled() ) {
			return new WP_Error( 'bc_mcp_disabled', __( 'Standalone MCP is disabled.', 'bc-mcp-abilities' ), array( 'status' => 404 ) );
		}

		$bearer = $request->get_header( 'authorization' );
		$user   = BC_MCP_Abilities_OAuth::bearer_user( $bearer );
		if ( is_wp_error( $user ) || ! $user ) {
			self::challenge();
			return new WP_Error( 'bc_mcp_unauthorized', __( 'OAuth authorization required.', 'bc-mcp-abilities' ), array( 'status' => 401 ) );
		}

		wp_set_current_user( (int) $user );
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error( 'bc_mcp_forbidden', __( 'This user lacks WordPress editing permissions.', 'bc-mcp-abilities' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Emit the OAuth discovery hint required by remote MCP clients.
	 *
	 * @return void
	 */
	public static function challenge() {
		$metadata = esc_url_raw( rest_url( 'bc-mcp/v1/oauth/resource' ) );
		header( 'WWW-Authenticate: Bearer resource_metadata="' . $metadata . '", scope="mcp"' );
	}

	/**
	 * Create the JSON-RPC response envelope.
	 *
	 * @param mixed $id     Request identifier.
	 * @param mixed $result Result content.
	 * @return WP_REST_Response
	 */
	private static function success( $id, $result ) {
		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
		$response->header( 'MCP-Protocol-Version', '2025-11-25' );
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
		return self::success(
			$id,
			array(
				'error' => $message,
			)
		);
	}

	/**
	 * Return three discoverable tools; abilities are invoked through them.
	 *
	 * @return array
	 */
	private static function tools() {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);

		return array(
			array(
				'name'        => 'bc-list-abilities',
				'title'       => 'List BC WordPress Abilities',
				'description' => 'Discover permitted WordPress abilities registered by BC MCP Abilities.',
				'inputSchema' => $schema,
				'annotations' => array( 'readOnlyHint' => true ),
			),
			array(
				'name'        => 'bc-get-ability-info',
				'title'       => 'Describe a BC WordPress Ability',
				'description' => 'Get the input schema, output schema and description of one BC ability before using it.',
				'inputSchema' => array(
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
				'annotations' => array( 'readOnlyHint' => true ),
			),
			array(
				'name'        => 'bc-execute-ability',
				'title'       => 'Execute a Permitted BC WordPress Ability',
				'description' => 'Execute a BC ability. WordPress permission and admin write opt-in controls always apply. Inspect the schema and confirm writes before calling.',
				'inputSchema' => array(
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
				'annotations' => array(
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
		$body = $request->get_body();
		if ( strlen( (string) $body ) > 1024 * 1024 ) {
			return self::success( null, array( 'error' => 'Request exceeds 1 MB.' ) );
		}
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || '2.0' !== ( $data['jsonrpc'] ?? '' ) || ! isset( $data['method'] ) ) {
			return new WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32600,
						'message' => 'Invalid JSON-RPC request.',
					),
				),
				400
			);
		}
		$id     = array_key_exists( 'id', $data ) ? $data['id'] : null;
		$method = (string) $data['method'];
		$params = isset( $data['params'] ) && is_array( $data['params'] ) ? $data['params'] : array();

		switch ( $method ) {
			case 'initialize':
				return self::success(
					$id,
					array(
						'protocolVersion' => '2025-11-25',
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array(
							'name'    => 'bc-mcp-abilities',
							'version' => BC_MCP_ABILITIES_VERSION,
						),
						'instructions'    => 'Use bc-list-abilities first. Inspect schemas before invoking. Never change WordPress content without explicit user approval.',
					)
				);
			case 'notifications/initialized':
				return new WP_REST_Response( null, 202 );
			case 'ping':
				return self::success( $id, (object) array() );
			case 'tools/list':
				return self::success( $id, array( 'tools' => self::tools() ) );
			case 'tools/call':
				$name = isset( $params['name'] ) ? (string) $params['name'] : '';
				$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
				if ( 'bc-list-abilities' === $name ) {
					return self::success( $id, self::tool_result( array( 'abilities' => self::list_abilities() ) ) );
				}
				$target  = isset( $args['ability_name'] ) ? $args['ability_name'] : '';
				$ability = self::bc_ability( $target );
				if ( ! $ability ) {
					return self::success( $id, self::tool_result( array( 'error' => 'Unknown BC ability.' ), true ) );
				}
				if ( 'bc-get-ability-info' === $name ) {
					return self::success( $id, self::tool_result( self::ability_info( $ability ) ) );
				}
				if ( 'bc-execute-ability' === $name ) {
					$slug   = substr( (string) $target, strlen( 'bc-mcp-abilities/' ) );
					$writes = array_keys( BC_MCP_Abilities_Admin::writable_abilities() );
					if ( in_array( $slug, $writes, true ) && true !== ( $args['confirmed'] ?? false ) ) {
						return self::success( $id, self::tool_result( array( 'error' => 'Explicit user confirmation is required for WordPress writes.' ), true ) );
					}
					$input  = isset( $args['parameters'] ) && is_array( $args['parameters'] ) ? $args['parameters'] : array();
					$output = $ability->execute( $input );
					if ( is_wp_error( $output ) ) {
						return self::success( $id, self::tool_result( array( 'error' => $output->get_error_message() ), true ) );
					}
					return self::success( $id, self::tool_result( $output ) );
				}
				return self::success( $id, self::tool_result( array( 'error' => 'Unknown MCP tool.' ), true ) );
		}

		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => -32601,
					'message' => 'Method not found.',
				),
			),
			200
		);
	}
}
