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
		return '1' === (string) $value ? '1' : '0';
	}

	/**
	 * Render easy, independent ChatGPT connector instructions.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$server_on = BC_MCP_Abilities_Server::enabled();
		$oauth_on  = BC_MCP_Abilities_OAuth::enabled();
		$endpoint  = BC_MCP_Abilities_Server::endpoint();
		?>
		<hr />
		<h2><?php esc_html_e( 'Connect ChatGPT', 'bc-mcp-abilities' ); ?></h2>
		<p><?php esc_html_e( 'Connect ChatGPT to your WordPress website with secure sign-in, without installing a separate server.', 'bc-mcp-abilities' ); ?></p>

		<?php if ( ! is_ssl() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'HTTPS is required. OAuth cannot be enabled over HTTP.', 'bc-mcp-abilities' ); ?></p></div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'bc_mcp_connections' ); ?>
			<input type="hidden" name="<?php echo esc_attr( BC_MCP_Abilities_Server::OPTION ); ?>" value="0">
			<input type="hidden" name="<?php echo esc_attr( BC_MCP_Abilities_OAuth::OPTION ); ?>" value="0">
			<p>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( BC_MCP_Abilities_Server::OPTION ); ?>" value="1" <?php checked( $server_on ); ?>>
					<strong><?php esc_html_e( 'Enable MCP connection', 'bc-mcp-abilities' ); ?></strong>
				</label>
			</p>
			<p>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( BC_MCP_Abilities_OAuth::OPTION ); ?>" value="1" <?php checked( '1', get_option( BC_MCP_Abilities_OAuth::OPTION, '0' ) ); ?> <?php disabled( ! is_ssl() ); ?>>
					<strong><?php esc_html_e( 'Enable secure WordPress sign-in', 'bc-mcp-abilities' ); ?></strong>
				</label>
			</p>
			<input type="hidden" name="bc_mcp_oauth_debug_enabled" value="0">
			<p>
				<label>
					<input type="checkbox" name="bc_mcp_oauth_debug_enabled" value="1" <?php checked( '1', get_option( 'bc_mcp_oauth_debug_enabled', '0' ) ); ?>>
					<strong><?php esc_html_e( 'Enable connection troubleshooting', 'bc-mcp-abilities' ); ?></strong>
				</label>
			</p>
			<p class="description"><?php esc_html_e( 'Diagnostic events record only fixed labels: stage, result, MCP method, whether an Authorization header was present, the token check outcome and a timestamp. No authorization codes, tokens, passwords, cookies, client IDs, URLs, user IDs, IP addresses or request bodies are recorded. The last 50 events remain for up to 24 hours and are deleted when diagnostics are turned off.', 'bc-mcp-abilities' ); ?></p>
			<?php submit_button( __( 'Save connection settings', 'bc-mcp-abilities' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( $oauth_on ) : ?>
			<p><strong><?php esc_html_e( 'Connection settings enabled', 'bc-mcp-abilities' ); ?></strong></p>
		<?php else : ?>
			<p><em><?php esc_html_e( 'Enable the MCP connection and WordPress sign-in above before connecting an assistant.', 'bc-mcp-abilities' ); ?></em></p>
		<?php endif; ?>

		<?php if ( '1' === (string) get_option( 'bc_mcp_oauth_debug_enabled', '0' ) ) : ?>
			<h3><?php esc_html_e( 'Recent connection activity', 'bc-mcp-abilities' ); ?></h3>
			<?php
			$events = get_option( 'bc_mcp_oauth_debug_events', array() );
			$events = is_array( $events ) ? array_reverse( array_slice( $events, -BC_MCP_Abilities_OAuth::DEBUG_MAX_EVENTS ) ) : array();
			?>
			<?php if ( empty( $events ) ) : ?>
				<p><?php esc_html_e( 'No connection events have been recorded yet. New events will appear when an assistant connects.', 'bc-mcp-abilities' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr>
						<th><?php esc_html_e( 'Time (UTC)', 'bc-mcp-abilities' ); ?></th>
						<th><?php esc_html_e( 'Stage', 'bc-mcp-abilities' ); ?></th>
						<th><?php esc_html_e( 'Result', 'bc-mcp-abilities' ); ?></th>
						<th><?php esc_html_e( 'MCP method', 'bc-mcp-abilities' ); ?></th>
						<th><?php esc_html_e( 'Authorization', 'bc-mcp-abilities' ); ?></th>
						<th><?php esc_html_e( 'Detail', 'bc-mcp-abilities' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $events as $event ) : ?>
							<?php if ( is_array( $event ) && ! empty( $event['time'] ) && time() - absint( $event['time'] ) < DAY_IN_SECONDS ) : ?>
								<tr>
									<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', absint( $event['time'] ) ) ); ?></td>
									<td><?php echo esc_html( sanitize_key( (string) ( $event['event'] ?? '' ) ) ); ?></td>
									<td><?php echo esc_html( sanitize_key( (string) ( $event['result'] ?? '' ) ) ); ?></td>
									<td><code><?php echo esc_html( (string) ( $event['method'] ?? '' ) ); ?></code></td>
									<td><?php echo esc_html( (string) ( $event['auth'] ?? '' ) ); ?></td>
									<td><?php echo esc_html( (string) ( $event['detail'] ?? '' ) ); ?></td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>
		<p><strong><?php esc_html_e( 'MCP server URL', 'bc-mcp-abilities' ); ?></strong></p>
		<p><input type="text" readonly class="regular-text code" style="max-width:100%;width:650px" id="bc-mcp-standalone-url" value="<?php echo esc_attr( $endpoint ); ?>" onclick="this.select()"></p>

		<ol>
			<li><?php esc_html_e( 'Enable the standalone MCP endpoint and OAuth using the settings above.', 'bc-mcp-abilities' ); ?></li>
			<li><?php esc_html_e( 'In ChatGPT on the web, open Plugins and choose Add custom MCP server.', 'bc-mcp-abilities' ); ?></li>
			<li><?php esc_html_e( 'Paste the MCP server URL shown above and select automatic OAuth registration (Dynamic Client Registration).', 'bc-mcp-abilities' ); ?></li>
			<li><?php esc_html_e( 'Complete the WordPress sign-in and choose Authorize on the permission screen.', 'bc-mcp-abilities' ); ?></li>
			<li><?php esc_html_e( 'Review available read-only actions before granting permission to edit your website.', 'bc-mcp-abilities' ); ?></li>
		</ol>
		<p><strong><?php esc_html_e( 'Claude connection:', 'bc-mcp-abilities' ); ?></strong>
			<?php esc_html_e( 'Claude may use a separate compatible MCP provider. Manage its connection under Connections.', 'bc-mcp-abilities' ); ?>
		</p>
		<p class="description"><?php esc_html_e( 'Custom MCP connection availability depends on your AI assistant account and workspace settings.', 'bc-mcp-abilities' ); ?></p>
		<?php
	}
}
