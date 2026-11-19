<?php
/**
 * Optional ChatGPT OAuth client metadata integration and connection guidance.
 *
 * Integrates with the separately installed wp-media/mcp-oauth provider when
 * available. The native WordPress abilities remain fully independent.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Allow an administrator to opt in to an exact ChatGPT OAuth client identity.
 */
final class BC_MCP_Abilities_ChatGPT {
	/**
	 * Whether to allow ChatGPT OAuth through a compatible provider.
	 */
	const ENABLED_OPTION = 'bc_mcp_abilities_chatgpt_enabled';

	/**
	 * The exact, administrator-approved ChatGPT client metadata URL.
	 */
	const CLIENT_ID_OPTION = 'bc_mcp_abilities_chatgpt_client_id';

	/**
	 * Official OpenAI-hosted, stable ChatGPT client metadata URL.
	 */
	const DEFAULT_CLIENT_ID = 'https://chatgpt.com/oauth/client.json';

	/**
	 * Initialize optional OAuth integration and settings.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'wpmedia_mcp_oauth_trusted_publishers', array( __CLASS__, 'trusted_publishers' ) );
	}

	/**
	 * Register two allowlisted settings using the WordPress Settings API.
	 *
	 * @return void
	 */
	public static function register_settings() {
		register_setting(
			'bc_mcp_chatgpt',
			self::ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_enabled' ),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'bc_mcp_chatgpt',
			self::CLIENT_ID_OPTION,
			array(
				'type'              => 'string',
				'default'           => self::DEFAULT_CLIENT_ID,
				'sanitize_callback' => array( __CLASS__, 'sanitize_client_id' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitize the opt-in setting.
	 *
	 * @param mixed $value Submitted value.
	 * @return bool
	 */
	public static function sanitize_enabled( $value ) {
		return '1' === (string) $value || true === $value;
	}

	/**
	 * Accept only an exact OpenAI-owned ChatGPT CIMD URL.
	 *
	 * A callback-specific client ID must be pasted explicitly by the
	 * administrator; no wildcard is added to the trusted publisher list.
	 *
	 * @param mixed $value Submitted client metadata URL.
	 * @return string
	 */
	public static function sanitize_client_id( $value ) {
		if ( ! is_string( $value ) ) {
			return self::DEFAULT_CLIENT_ID;
		}

		$client_id = trim( $value );
		if ( self::DEFAULT_CLIENT_ID === $client_id ) {
			return $client_id;
		}

		$parsed = wp_parse_url( $client_id );
		if ( ! is_array( $parsed ) || 'https' !== ( $parsed['scheme'] ?? '' ) || 'chatgpt.com' !== ( $parsed['host'] ?? '' ) ) {
			return self::DEFAULT_CLIENT_ID;
		}

		if ( isset( $parsed['port'] ) || isset( $parsed['user'] ) || isset( $parsed['pass'] ) || isset( $parsed['query'] ) || isset( $parsed['fragment'] ) ) {
			return self::DEFAULT_CLIENT_ID;
		}

		$path = (string) ( $parsed['path'] ?? '' );
		if ( ! preg_match( '#^/oauth/[A-Za-z0-9_-]{6,128}/client[.]json$#D', $path ) ) {
			return self::DEFAULT_CLIENT_ID;
		}

		return $client_id;
	}

	/**
	 * Whether ChatGPT may be added to a compatible OAuth provider.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) get_option( self::ENABLED_OPTION, false );
	}

	/**
	 * Return the exact approved ChatGPT client ID.
	 *
	 * @return string
	 */
	public static function client_id() {
		$stored = get_option( self::CLIENT_ID_OPTION, self::DEFAULT_CLIENT_ID );
		return self::sanitize_client_id( $stored );
	}

	/**
	 * Extend an OAuth provider's trusted CIMD publishers only when enabled.
	 *
	 * The OAuth library must still fetch the HTTPS metadata, verify its
	 * client_id, enforce the published redirect URI and perform PKCE.
	 * No arbitrary OAuth clients or URL patterns are registered.
	 *
	 * @param array $publishers Current OAuth publisher allowlist.
	 * @return array
	 */
	public static function trusted_publishers( $publishers ) {
		if ( ! is_array( $publishers ) || ! self::enabled() ) {
			return $publishers;
		}

		$publishers['chatgpt'] = array(
			'client_ids' => array( self::client_id() ),
			'host'       => 'chatgpt.com',
		);

		return $publishers;
	}

	/**
	 * Detect the supported, separately installed WordPress OAuth provider.
	 *
	 * @return bool
	 */
	public static function oauth_provider_available() {
		return class_exists( '\WPMedia\MCP\OAuth\Auth\ClaudeClientVerifier' );
	}

	/**
	 * Render a simple, explicit ChatGPT connection section.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$endpoint = rest_url( 'mcp/mcp-oauth-server' );
		?>
		<hr />
		<h2><?php esc_html_e( 'Connect to ChatGPT', 'bc-mcp-abilities' ); ?></h2>
		<p><?php esc_html_e( 'Use your WordPress site directly. No additional hosted MCP server, API key, or Terminal setup is required.', 'bc-mcp-abilities' ); ?></p>
		<?php if ( ! self::oauth_provider_available() ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'A compatible WordPress OAuth provider was not detected. This setting does not install or replace an OAuth server. Claude and ChatGPT browser connections need an active, compatible OAuth 2.1 MCP endpoint.', 'bc-mcp-abilities' ); ?></p>
			</div>
		<?php endif; ?>
		<p>
			<strong><?php esc_html_e( 'OAuth-enabled MCP endpoint:', 'bc-mcp-abilities' ); ?></strong>
			<input type="text" class="regular-text code" readonly="readonly" aria-label="<?php esc_attr_e( 'ChatGPT MCP connection URL', 'bc-mcp-abilities' ); ?>" value="<?php echo esc_attr( $endpoint ); ?>" />
		</p>
		<p><?php esc_html_e( 'ChatGPT supports OAuth Client ID Metadata Documents (CIMD). This optional compatibility setting asks a supported OAuth provider to trust an exact OpenAI-hosted client identity.', 'bc-mcp-abilities' ); ?></p>
		<form action="options.php" method="post">
			<?php settings_fields( 'bc_mcp_chatgpt' ); ?>
			<input type="hidden" name="<?php echo esc_attr( self::ENABLED_OPTION ); ?>" value="0" />
			<p>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( self::ENABLED_OPTION ); ?>" value="1" <?php checked( self::enabled() ); ?> />
					<strong><?php esc_html_e( 'Enable trusted ChatGPT OAuth client (CIMD)', 'bc-mcp-abilities' ); ?></strong>
				</label>
			</p>
			<p>
				<label for="bc-mcp-chatgpt-client-id"><strong><?php esc_html_e( 'ChatGPT Client Metadata URL', 'bc-mcp-abilities' ); ?></strong></label>
				<input type="url" id="bc-mcp-chatgpt-client-id" class="large-text code" name="<?php echo esc_attr( self::CLIENT_ID_OPTION ); ?>" value="<?php echo esc_attr( self::client_id() ); ?>" />
				<span class="description"><?php esc_html_e( 'Use the OpenAI-hosted client ID shown by ChatGPT. Only exact chatgpt.com metadata URLs are accepted. This setting does not permit arbitrary client registration.', 'bc-mcp-abilities' ); ?></span>
			</p>
			<?php submit_button( __( 'Save ChatGPT Connection Settings', 'bc-mcp-abilities' ), 'secondary' ); ?>
		</form>
		<h3><?php esc_html_e( 'Connect in ChatGPT', 'bc-mcp-abilities' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'In ChatGPT, open Plugins, select Add (+), then Add custom MCP server if that option is available in your account.', 'bc-mcp-abilities' ); ?></li>
			<li><?php esc_html_e( 'Enter BC MCP Abilities as the name and paste the OAuth-enabled MCP endpoint above.', 'bc-mcp-abilities' ); ?></li>
			<li><?php esc_html_e( 'Choose OAuth authentication and the Client ID Metadata Document (CIMD) method when offered.', 'bc-mcp-abilities' ); ?></li>
			<li><?php esc_html_e( 'Review the connector details, authorize access with WordPress, and approve the consent prompt.', 'bc-mcp-abilities' ); ?></li>
			<li><?php esc_html_e( 'Ask ChatGPT to discover the abilities beginning with bc-mcp-abilities/. Keep write permissions disabled during your first test.', 'bc-mcp-abilities' ); ?></li>
		</ol>
		<p class="description"><?php esc_html_e( 'Availability of custom MCP connections depends on your ChatGPT plan and workspace. This setting enables only a supported OAuth provider integration; it does not guarantee that ChatGPT will accept a connection.', 'bc-mcp-abilities' ); ?></p>
		<p class="description"><?php esc_html_e( 'Disabling this setting does not immediately revoke previously issued OAuth tokens. To revoke existing access, disconnect the ChatGPT connection and revoke its access in the WordPress account used to authorize it.', 'bc-mcp-abilities' ); ?></p>
		<p><a target="_blank" rel="noopener noreferrer" href="https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt"><?php esc_html_e( 'ChatGPT custom MCP connection instructions', 'bc-mcp-abilities' ); ?></a></p>
		<?php
	}
}
