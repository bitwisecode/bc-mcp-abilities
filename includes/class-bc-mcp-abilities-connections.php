<?php
/**
 * Standalone ChatGPT/remote MCP connection settings screen.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keep standalone connection settings separate from ability write permissions.
 */
final class BC_MCP_Abilities_Connections {

	/**
	 * Register settings using the WordPress Settings API.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'update_option_bc_mcp_oauth_debug_enabled', array( __CLASS__, 'clear_diagnostics' ), 10, 2 );
	}

	/**
	 * Delete stored diagnostic events as soon as diagnostics are switched off.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	public static function clear_diagnostics( $old_value, $value ) {
		unset( $old_value );
		if ( '1' !== (string) $value ) {
			delete_option( 'bc_mcp_oauth_debug_events' );
		}
	}

	/**
	 * Register default-off transport and OAuth toggles.
	 *
	 * @return void
	 */
	public static function register_settings() {
		foreach ( array( BC_MCP_Abilities_Server::OPTION, BC_MCP_Abilities_OAuth::OPTION, 'bc_mcp_oauth_debug_enabled' ) as $option ) {
			register_setting(
				'bc_mcp_connections',
				$option,
				array(
					'type'              => 'string',
					'description'       => __( 'Enable the standalone BC MCP connection.', 'bc-mcp-abilities' ),
					'default'           => '0',
					'show_in_rest'      => false,
					'sanitize_callback' => array( __CLASS__, 'sanitize_toggle' ),
				)
			);
		}
	}

	/**
	 * Normalize checkbox input.
	 *
	 * @param mixed $value Posted form value.
	 * @return string
	 */
	public static function sanitize_toggle( $value ) {
		return ( is_string( $value ) || is_int( $value ) ) && '1' === (string) $value ? '1' : '0';
	}
}
