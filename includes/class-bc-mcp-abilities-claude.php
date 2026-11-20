<?php
/**
 * Claude Desktop one-click extension installer.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Produce a site-prefilled, credential-free MCPB package.
 */
final class BC_MCP_Abilities_Claude {
	/**
	 * Add the authenticated download handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_post_bc_mcp_download_claude', array( __CLASS__, 'download' ) );
	}

	/**
	 * Get this site's MCP Adapter HTTP endpoint.
	 *
	 * @return string
	 */
	public static function endpoint() {
		return rest_url( 'mcp/mcp-adapter-default-server' );
	}

	/**
	 * Is the official MCP Adapter active?
	 *
	 * @return bool
	 */
	public static function adapter_active() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		return in_array( 'mcp-adapter/mcp-adapter.php', $active, true );
	}

	/**
	 * Get a credential-free manifest compatible with Claude MCP Bundles.
	 *
	 * @return array
	 */
	public static function manifest() {
		$manifest = array(
			'manifest_version' => '0.3',
			'name'             => 'bc-mcp-abilities-claude',
			'display_name'     => 'BC MCP Abilities - WordPress',
			'version'          => '0.1.0',
			'description'      => 'Use WordPress abilities from Claude Desktop with the official MCP Adapter.',
			'author'           => array(
				'name' => 'BitwiseCode',
				'url'  => 'https://bitwisecode.com',
			),
			'homepage'         => 'https://github.com/bitwisecode/bc-mcp-abilities',
			'license'          => 'GPL-2.0-or-later',
			'tools_generated'  => true,
			'server'           => array(
				'type'        => 'node',
				'entry_point' => 'server/index.js',
				'mcp_config'  => array(
					'command' => 'node',
					'args'    => array( '${__dirname}/server/index.js' ),
					'env'     => array(
						'BC_MCP_URL'          => '${user_config.site_url}',
						'BC_MCP_USERNAME'     => '${user_config.username}',
						'BC_MCP_APP_PASSWORD' => '${user_config.application_password}',
					),
				),
			),
			'compatibility'    => array(
				'platforms' => array( 'darwin', 'win32' ),
				'runtimes'  => array( 'node' => '>=20.0.0' ),
			),
			'user_config'      => array(
				'site_url'             => array(
					'type'        => 'string',
					'title'       => 'WordPress MCP endpoint',
					'description' => 'This is prefilled from your website.',
					'default'     => self::endpoint(),
					'required'    => true,
				),
				'username'             => array(
					'type'     => 'string',
					'title'    => 'WordPress login username',
					'required' => true,
				),
				'application_password' => array(
					'type'        => 'string',
					'title'       => 'WordPress Application Password',
					'description' => 'Use the password from Users > Profile, not your normal login password.',
					'sensitive'   => true,
					'required'    => true,
				),
			),
		);
		$privacy  = get_privacy_policy_url();
		if ( $privacy && 'https' === wp_parse_url( $privacy, PHP_URL_SCHEME ) ) {
			$manifest['privacy_policies'] = array( $privacy );
		}
		return $manifest;
	}

	/**
	 * Generate and stream a downloadable .mcpb archive (no stored secrets).
	 *
	 * @return void
	 */
	public static function download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot download this extension.', 'bc-mcp-abilities' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bc_mcp_download_claude' );
		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( esc_html__( 'Your PHP installation must support ZipArchive to create the extension.', 'bc-mcp-abilities' ) );
		}
		$source = dirname( __DIR__ ) . '/claude-extension/server/index.js';
		if ( ! is_readable( $source ) ) {
			wp_die( esc_html__( 'Claude bridge file is missing; reinstall the full BC plugin ZIP.', 'bc-mcp-abilities' ) );
		}
		$tmp = wp_tempnam( 'bc-mcp-claude' );
		if ( ! $tmp ) {
			wp_die( esc_html__( 'Could not prepare the extension download.', 'bc-mcp-abilities' ) );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'Could not create the extension archive.', 'bc-mcp-abilities' ) );
		}
		$manifest = wp_json_encode( self::manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$ok       = $manifest && $zip->addFromString( 'manifest.json', $manifest ) && $zip->addFile( $source, 'server/index.js' );
		$closed   = $zip->close();
		if ( ! $ok || ! $closed || ! is_readable( $tmp ) ) {
			wp_delete_file( $tmp );
			wp_die( esc_html__( 'Claude extension packaging failed.', 'bc-mcp-abilities' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="bc-mcp-abilities-claude.mcpb"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming generated ZIP.
		readfile( $tmp );
		wp_delete_file( $tmp );
		exit;
	}

	/**
	 * Show administrator-friendly Claude Desktop setup steps.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=bc_mcp_download_claude' ), 'bc_mcp_download_claude' );
		?>
		<hr />
		<h2><?php esc_html_e( 'Connect to Claude Desktop', 'bc-mcp-abilities' ); ?></h2>
		<p><?php esc_html_e( 'Connect without Terminal commands, installing Node.js or editing configuration files.', 'bc-mcp-abilities' ); ?></p>
		<?php if ( ! self::adapter_active() ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php esc_html_e( 'The official WordPress MCP Adapter is not active. Install and activate it first.', 'bc-mcp-abilities' ); ?>
				<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'View plugins', 'bc-mcp-abilities' ); ?></a>
			</p></div>
		<?php endif; ?>
		<p><strong><?php esc_html_e( 'Connection URL:', 'bc-mcp-abilities' ); ?></strong> <code><?php echo esc_html( self::endpoint() ); ?></code></p>
		<ol>
			<li><?php esc_html_e( 'Activate the official MCP Adapter on your website.', 'bc-mcp-abilities' ); ?></li>
			<li><?php esc_html_e( 'Create a WordPress Application Password for Claude.', 'bc-mcp-abilities' ); ?>
				<a href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><?php esc_html_e( 'Open user profile', 'bc-mcp-abilities' ); ?></a>
			</li>
			<li><?php esc_html_e( 'Download the Claude Desktop extension below.', 'bc-mcp-abilities' ); ?></li>
			<li><?php esc_html_e( 'In Claude Desktop: Settings > Desktop app > Extensions > Manage > Advanced settings > Install Extension.', 'bc-mcp-abilities' ); ?></li>
			<li><?php esc_html_e( 'Select the .mcpb file, then enter your WordPress username and Application Password in Claude.', 'bc-mcp-abilities' ); ?></li>
		</ol>
		<p><a class="button button-primary button-hero" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Download Claude Extension (.mcpb)', 'bc-mcp-abilities' ); ?></a></p>
		<p class="description"><?php esc_html_e( 'Credentials are not included in the download or stored by BC MCP Abilities. Claude securely collects them. Use a dedicated WordPress account with minimal permissions.', 'bc-mcp-abilities' ); ?></p>
		<p class="description"><?php esc_html_e( 'The connected WordPress user can access only the actions permitted by their WordPress role and this plugin\'s settings.', 'bc-mcp-abilities' ); ?></p>
		<p><a target="_blank" rel="noopener noreferrer" href="https://support.claude.com/en/articles/10949351-getting-started-with-local-mcp-servers-on-claude-desktop">
			<?php esc_html_e( 'Claude Desktop extension help', 'bc-mcp-abilities' ); ?>
		</a></p>
		<p class="description"><?php esc_html_e( 'This optional desktop extension uses the MCP Adapter and a WordPress Application Password. For OAuth connections, use the URL guidance under Connections.', 'bc-mcp-abilities' ); ?></p>
		<?php
	}
}
