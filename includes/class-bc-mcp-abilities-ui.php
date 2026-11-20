<?php
/**
 * Premium, accessible settings dashboard for BC MCP Abilities.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * View-only administration. All settings continue using the original
 * WordPress Settings API registrations, sanitizers, and permission checks.
 */
final class BC_MCP_Abilities_UI {

	/**
	 * Render one human-readable connection status without claiming a live probe.
	 *
	 * @param bool $enabled Whether the feature is enabled in configuration.
	 * @return void
	 */
	private static function status( $enabled ) {
		printf(
			'<span class="bc-status %1$s"><span class="bc-status-dot" aria-hidden="true"></span>%2$s</span>',
			$enabled ? 'bc-status-on' : 'bc-status-off',
			esc_html( $enabled ? __( 'Enabled', 'bc-mcp-abilities' ) : __( 'Disabled', 'bc-mcp-abilities' ) )
		);
	}

	/**
	 * Render a permission group with searchable controls.
	 *
	 * @param string $title Group title.
	 * @param array  $slugs Registered ability keys.
	 * @param array  $abilities All registered writable abilities.
	 * @return void
	 */
	private static function ability_group( $title, $slugs, $abilities ) {
		$available = array_intersect_key( $abilities, array_flip( $slugs ) );
		if ( ! $available ) {
			return;
		}
		?>
		<section class="bc-permission-group" data-bc-group>
			<div class="bc-group-heading">
				<h3><?php echo esc_html( $title ); ?></h3>
				<span><?php echo esc_html( (string) count( $available ) ); ?> <?php esc_html_e( 'permissions', 'bc-mcp-abilities' ); ?></span>
			</div>
			<div class="bc-permission-list">
				<?php foreach ( $available as $slug => $description ) : ?>
					<?php $enabled = BC_MCP_Abilities_Admin::is_enabled( $slug ); ?>
					<label class="bc-permission" data-bc-permission data-bc-search-text="<?php echo esc_attr( strtolower( $slug . ' ' . $description ) ); ?>">
						<span class="bc-permission-copy">
							<strong><?php echo esc_html( ucwords( str_replace( '-', ' ', $slug ) ) ); ?></strong>
							<span class="bc-permission-description"><?php echo esc_html( $description ); ?></span>
							<code><?php echo esc_html( 'bc-mcp-abilities/' . $slug ); ?></code>
						</span>
						<span class="bc-switch">
							<input type="checkbox" name="<?php echo esc_attr( BC_MCP_Abilities_Admin::OPTION ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $enabled ); ?> />
							<span class="bc-switch-track" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: ability name. */ __( 'Allow %s', 'bc-mcp-abilities' ), $slug ) ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Render the WordPress Settings API backed connection form.
	 *
	 * @return void
	 */
	private static function connections() {
		$server_on    = BC_MCP_Abilities_Server::enabled();
		$oauth_on     = '1' === (string) get_option( BC_MCP_Abilities_OAuth::OPTION, '0' );
		$debug_on     = '1' === (string) get_option( 'bc_mcp_oauth_debug_enabled', '0' );
		$endpoint     = BC_MCP_Abilities_Server::endpoint();
		$claude_oauth = rest_url( 'mcp/mcp-oauth-server' );
		?>
		<div class="bc-section-header">
			<div>
				<p class="bc-eyebrow"><?php esc_html_e( 'Connection settings', 'bc-mcp-abilities' ); ?></p>
				<h2><?php esc_html_e( 'Connections', 'bc-mcp-abilities' ); ?></h2>
				<p><?php esc_html_e( 'Manage OAuth connections for ChatGPT and Claude in one place. Both use MCP server URLs; no desktop extension download is required for the OAuth method.', 'bc-mcp-abilities' ); ?></p>
			</div>
			<?php self::status( $server_on && $oauth_on && is_ssl() ); ?>
		</div>
		<?php if ( ! is_ssl() ) : ?>
			<div class="bc-alert bc-alert-danger"><?php esc_html_e( 'HTTPS is required. Enable TLS for WordPress before turning on OAuth.', 'bc-mcp-abilities' ); ?></div>
		<?php endif; ?>
		<div class="bc-card">
			<div class="bc-card-title"><h3><?php esc_html_e( 'MCP server configuration', 'bc-mcp-abilities' ); ?></h3><p><?php esc_html_e( 'Settings use WordPress nonce validation and existing secure option storage.', 'bc-mcp-abilities' ); ?></p></div>
			<form method="post" action="options.php">
				<?php settings_fields( 'bc_mcp_connections' ); ?>
				<input type="hidden" name="<?php echo esc_attr( BC_MCP_Abilities_Server::OPTION ); ?>" value="0" />
				<input type="hidden" name="<?php echo esc_attr( BC_MCP_Abilities_OAuth::OPTION ); ?>" value="0" />
				<input type="hidden" name="bc_mcp_oauth_debug_enabled" value="0" />
				<label class="bc-setting-row">
					<span><strong><?php esc_html_e( 'Built-in MCP server', 'bc-mcp-abilities' ); ?></strong><small><?php esc_html_e( 'Expose the built-in REST MCP endpoint used by ChatGPT.', 'bc-mcp-abilities' ); ?></small></span>
					<span class="bc-switch"><input type="checkbox" name="<?php echo esc_attr( BC_MCP_Abilities_Server::OPTION ); ?>" value="1" <?php checked( $server_on ); ?> /><span class="bc-switch-track" aria-hidden="true"></span></span>
				</label>
				<label class="bc-setting-row">
					<span><strong><?php esc_html_e( 'WordPress OAuth', 'bc-mcp-abilities' ); ?></strong><small><?php esc_html_e( 'Enable authorization codes and scoped tokens for the built-in MCP endpoint.', 'bc-mcp-abilities' ); ?></small></span>
					<span class="bc-switch"><input type="checkbox" name="<?php echo esc_attr( BC_MCP_Abilities_OAuth::OPTION ); ?>" value="1" <?php checked( $oauth_on ); ?> <?php disabled( ! is_ssl() ); ?> /><span class="bc-switch-track" aria-hidden="true"></span></span>
				</label>
				<label class="bc-setting-row">
					<span><strong><?php esc_html_e( 'Temporary diagnostics', 'bc-mcp-abilities' ); ?></strong><small><?php esc_html_e( 'Capture sanitized protocol events for troubleshooting. Records expire after 24 hours and are removed when switched off.', 'bc-mcp-abilities' ); ?></small></span>
					<span class="bc-switch"><input type="checkbox" name="bc_mcp_oauth_debug_enabled" value="1" <?php checked( $debug_on ); ?> /><span class="bc-switch-track" aria-hidden="true"></span></span>
				</label>
				<div class="bc-form-actions">
					<?php submit_button( __( 'Save connection settings', 'bc-mcp-abilities' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<div class="bc-client-grid">
			<div class="bc-card bc-client-card">
				<div class="bc-client-heading">
					<span class="bc-client-logo" aria-hidden="true">GPT</span>
					<div><p class="bc-eyebrow"><?php esc_html_e( 'Built-in OAuth connection', 'bc-mcp-abilities' ); ?></p><h3><?php esc_html_e( 'ChatGPT', 'bc-mcp-abilities' ); ?></h3></div>
				</div>
				<p class="bc-client-description"><?php esc_html_e( 'Connect ChatGPT directly to the standalone BC MCP endpoint using OAuth and Dynamic Client Registration.', 'bc-mcp-abilities' ); ?></p>
				<label class="bc-client-field-label" for="bc-mcp-server-url"><?php esc_html_e( 'MCP server URL', 'bc-mcp-abilities' ); ?></label>
				<div class="bc-copy-field">
					<input id="bc-mcp-server-url" type="text" value="<?php echo esc_attr( $endpoint ); ?>" readonly aria-label="<?php esc_attr_e( 'ChatGPT MCP server URL', 'bc-mcp-abilities' ); ?>" />
					<button class="button bc-copy-button" type="button" data-bc-copy="bc-mcp-server-url"><?php esc_html_e( 'Copy URL', 'bc-mcp-abilities' ); ?></button>
				</div>
				<ol class="bc-steps">
					<li><?php esc_html_e( 'Enable the built-in MCP server and WordPress OAuth above.', 'bc-mcp-abilities' ); ?></li>
					<li><?php esc_html_e( 'In ChatGPT, add a custom MCP server with this URL and automatic OAuth registration.', 'bc-mcp-abilities' ); ?></li>
					<li><?php esc_html_e( 'Approve the WordPress consent screen and test a read-only ability.', 'bc-mcp-abilities' ); ?></li>
				</ol>
			</div>
			<div class="bc-card bc-client-card">
				<div class="bc-client-heading">
					<span class="bc-client-logo bc-client-logo-claude" aria-hidden="true">C</span>
					<div><p class="bc-eyebrow"><?php esc_html_e( 'OAuth-compatible MCP provider', 'bc-mcp-abilities' ); ?></p><h3><?php esc_html_e( 'Claude', 'bc-mcp-abilities' ); ?></h3></div>
				</div>
				<p class="bc-client-description"><?php esc_html_e( 'Use the MCP URL from your existing OAuth-capable WordPress MCP provider. Claude connects directly through its MCP integration—no .mcpb attachment or Application Password is needed for that flow.', 'bc-mcp-abilities' ); ?></p>
				<label class="bc-client-field-label" for="bc-claude-oauth-url"><?php esc_html_e( 'OAuth MCP provider URL (when installed)', 'bc-mcp-abilities' ); ?></label>
				<div class="bc-copy-field">
					<input id="bc-claude-oauth-url" type="text" value="<?php echo esc_attr( $claude_oauth ); ?>" readonly aria-label="<?php esc_attr_e( 'Claude OAuth MCP provider URL', 'bc-mcp-abilities' ); ?>" />
					<button class="button bc-copy-button" type="button" data-bc-copy="bc-claude-oauth-url"><?php esc_html_e( 'Copy URL', 'bc-mcp-abilities' ); ?></button>
				</div>
				<ol class="bc-steps">
					<li><?php esc_html_e( 'Make sure the separate OAuth MCP provider exposing this URL is active.', 'bc-mcp-abilities' ); ?></li>
					<li><?php esc_html_e( 'Add the remote MCP URL in Claude and complete the WordPress OAuth sign-in.', 'bc-mcp-abilities' ); ?></li>
					<li><?php esc_html_e( 'If you already have a working Claude OAuth URL, keep using that exact URL.', 'bc-mcp-abilities' ); ?></li>
				</ol>
				<p class="bc-client-note"><?php esc_html_e( 'This URL belongs to an optional OAuth MCP provider; BC MCP Abilities does not create that provider route. The Application Password-only MCP Adapter endpoint is different.', 'bc-mcp-abilities' ); ?></p>
			</div>
		</div>
		<p class="bc-muted"><?php esc_html_e( 'The configuration badge above is not a live connectivity test. OAuth support depends on the specific endpoint and client.', 'bc-mcp-abilities' ); ?></p>
		<?php
	}

	/**
	 * Display bounded sanitized diagnostic history.
	 *
	 * @return void
	 */
	private static function diagnostics() {
		$enabled = '1' === (string) get_option( 'bc_mcp_oauth_debug_enabled', '0' );
		$events  = get_option( 'bc_mcp_oauth_debug_events', array() );
		$events  = is_array( $events ) ? array_reverse( array_slice( $events, -BC_MCP_Abilities_OAuth::DEBUG_MAX_EVENTS ) ) : array();
		?>
		<div class="bc-section-header">
			<div><p class="bc-eyebrow"><?php esc_html_e( 'Troubleshooting', 'bc-mcp-abilities' ); ?></p><h2><?php esc_html_e( 'Connection activity', 'bc-mcp-abilities' ); ?></h2><p><?php esc_html_e( 'Recent sanitized OAuth and MCP requests. No credentials, tokens, or request bodies are recorded.', 'bc-mcp-abilities' ); ?></p></div>
			<?php self::status( $enabled ); ?>
		</div>
		<div class="bc-card">
			<div class="bc-card-title"><h3><?php esc_html_e( 'Recent events', 'bc-mcp-abilities' ); ?></h3><p><?php esc_html_e( 'Maximum 50 events retained for up to 24 hours, newest first.', 'bc-mcp-abilities' ); ?></p></div>
			<?php if ( ! $enabled ) : ?>
				<div class="bc-empty"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><strong><?php esc_html_e( 'Diagnostics are off', 'bc-mcp-abilities' ); ?></strong><p><?php esc_html_e( 'Turn on temporary diagnostics under Connections to investigate issues.', 'bc-mcp-abilities' ); ?></p><button type="button" class="button" data-bc-goto="connections"><?php esc_html_e( 'Open connection settings', 'bc-mcp-abilities' ); ?></button></div>
			<?php elseif ( empty( $events ) ) : ?>
				<div class="bc-empty"><strong><?php esc_html_e( 'No events yet', 'bc-mcp-abilities' ); ?></strong><p><?php esc_html_e( 'Reconnect your MCP client, then refresh this screen.', 'bc-mcp-abilities' ); ?></p></div>
			<?php else : ?>
				<div class="bc-table-scroll"><table class="widefat bc-events"><thead><tr>
					<th><?php esc_html_e( 'Time (UTC)', 'bc-mcp-abilities' ); ?></th><th><?php esc_html_e( 'Stage', 'bc-mcp-abilities' ); ?></th><th><?php esc_html_e( 'Result', 'bc-mcp-abilities' ); ?></th><th><?php esc_html_e( 'MCP method', 'bc-mcp-abilities' ); ?></th><th><?php esc_html_e( 'Authorization', 'bc-mcp-abilities' ); ?></th><th><?php esc_html_e( 'Detail', 'bc-mcp-abilities' ); ?></th>
				</tr></thead><tbody>
					<?php foreach ( $events as $event ) : ?>
						<?php
						if ( ! is_array( $event ) || empty( $event['time'] ) || time() - absint( $event['time'] ) >= DAY_IN_SECONDS ) {
							continue;
						}
						?>
						<tr>
							<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', absint( $event['time'] ) ) ); ?></td>
							<td><?php echo esc_html( sanitize_key( (string) ( $event['event'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( sanitize_key( (string) ( $event['result'] ?? '' ) ) ); ?></td>
							<td><code><?php echo esc_html( (string) ( $event['method'] ?? '' ) ); ?></code></td>
							<td><?php echo esc_html( (string) ( $event['auth'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $event['detail'] ?? '' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody></table></div>
			<?php endif; ?>
			<p class="bc-muted"><?php esc_html_e( 'Disable diagnostics when finished. Turning it off immediately deletes stored events.', 'bc-mcp-abilities' ); ?></p>
		</div>
		<?php
	}


	/**
	 * Render a transparent inventory of registered read-only operations.
	 *
	 * @return void
	 */
	private static function read_only_catalog() {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return;
		}
		$items       = array();
		$write_slugs = BC_MCP_Abilities_Admin::writable_abilities();
		foreach ( (array) wp_get_abilities() as $ability ) {
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'get_name' ) ) {
				continue;
			}
			$name = $ability->get_name();
			if ( 0 !== strpos( $name, 'bc-mcp-abilities/' ) ) {
				continue;
			}
			$slug = substr( $name, strlen( 'bc-mcp-abilities/' ) );
			if ( isset( $write_slugs[ $slug ] ) ) {
				continue;
			}
			$items[] = $ability;
		}
		?>
		<details class="bc-card bc-readonly-catalog">
			<summary>
				<span><strong><?php esc_html_e( 'Read-only ability catalog', 'bc-mcp-abilities' ); ?></strong><small><?php esc_html_e( 'Explore what authenticated WordPress users can inspect without changing data.', 'bc-mcp-abilities' ); ?></small></span>
				<span class="bc-readonly-number"><?php echo esc_html( (string) count( $items ) ); ?> <?php esc_html_e( 'abilities', 'bc-mcp-abilities' ); ?></span>
			</summary>
			<div class="bc-readonly-list">
				<?php foreach ( $items as $ability ) : ?>
					<div class="bc-readonly-item">
						<strong><?php echo esc_html( $ability->get_label() ); ?></strong>
						<code><?php echo esc_html( $ability->get_name() ); ?></code>
						<p><?php echo esc_html( $ability->get_description() ); ?></p>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="bc-muted"><?php esc_html_e( 'Read-only access is enforced through each operation’s WordPress capability checks. No extra permission switch is provided here.', 'bc-mcp-abilities' ); ?></p>
		</details>
		<?php
	}

	/**
	 * Render the dashboard.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$writes  = BC_MCP_Abilities_Admin::writable_abilities();
		$enabled = array_filter( array_keys( $writes ), array( 'BC_MCP_Abilities_Admin', 'is_enabled' ) );
		$total   = 0;
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( (array) wp_get_abilities() as $ability ) {
				if ( is_object( $ability ) && method_exists( $ability, 'get_name' ) && 0 === strpos( $ability->get_name(), 'bc-mcp-abilities/' ) ) {
					++$total;
				}
			}
		}
		$server_on = BC_MCP_Abilities_Server::enabled();
		$oauth_on  = BC_MCP_Abilities_OAuth::enabled();
		$groups    = array(
			__( 'Content & posts', 'bc-mcp-abilities' ) => array( 'create-draft', 'update-content', 'restore-revision', 'create-custom-draft', 'update-custom-content' ),
			__( 'Elementor', 'bc-mcp-abilities' )       => array( 'elementor-update-text', 'elementor-undo', 'elementor-create-document', 'elementor-update-settings', 'elementor-add-element', 'elementor-delete-element', 'elementor-move-element', 'elementor-regenerate-css' ),
			__( 'WooCommerce', 'bc-mcp-abilities' )     => array( 'wc-update-stock' ),
			__( 'Taxonomies & menus', 'bc-mcp-abilities' ) => array( 'create-taxonomy-term', 'assign-post-terms', 'create-menu', 'add-menu-item' ),
			__( 'SEO & media', 'bc-mcp-abilities' )     => array( 'rank-math-update', 'upload-image', 'update-media-alt' ),
		);

		$grouped = array();
		foreach ( $groups as $slugs ) {
			$grouped = array_merge( $grouped, $slugs );
		}
		$other = array_diff( array_keys( $writes ), $grouped );
		if ( $other ) {
			$groups[ __( 'Additional permissions', 'bc-mcp-abilities' ) ] = $other;
		}
		?>
		<div class="wrap bc-mcp-admin" id="bc-mcp-admin">
			<div class="bc-hero">
				<div class="bc-hero-brand"><div class="bc-brand-mark" aria-hidden="true">BC</div><span><?php esc_html_e( 'BITWISECODE / WordPress', 'bc-mcp-abilities' ); ?></span></div>
				<div class="bc-hero-row">
					<div><h1><?php esc_html_e( 'MCP Abilities', 'bc-mcp-abilities' ); ?></h1><p><?php esc_html_e( 'Your control center for secure AI-powered WordPress workflows.', 'bc-mcp-abilities' ); ?></p></div>
					<div class="bc-hero-version"><?php echo esc_html( BC_MCP_ABILITIES_VERSION ); ?> <span><?php esc_html_e( 'Developer preview', 'bc-mcp-abilities' ); ?></span></div>
				</div>
			</div>
			<div class="bc-layout">
				<nav class="bc-sidebar" role="tablist" aria-label="<?php esc_attr_e( 'MCP settings sections', 'bc-mcp-abilities' ); ?>" aria-orientation="vertical">
					<p class="bc-sidebar-label"><?php esc_html_e( 'WORKSPACE', 'bc-mcp-abilities' ); ?></p>
					<button type="button" role="tab" class="bc-tab is-active" id="bc-tab-overview" data-bc-tab="overview" aria-controls="bc-panel-overview" aria-selected="true"><span class="dashicons dashicons-dashboard" aria-hidden="true"></span><?php esc_html_e( 'Overview', 'bc-mcp-abilities' ); ?></button>
					<button type="button" role="tab" class="bc-tab" id="bc-tab-connections" data-bc-tab="connections" aria-controls="bc-panel-connections" aria-selected="false"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><?php esc_html_e( 'Connections', 'bc-mcp-abilities' ); ?></button>
					<button type="button" role="tab" class="bc-tab" id="bc-tab-abilities" data-bc-tab="abilities" aria-controls="bc-panel-abilities" aria-selected="false"><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span><?php esc_html_e( 'Abilities & permissions', 'bc-mcp-abilities' ); ?></button>
					<button type="button" role="tab" class="bc-tab" id="bc-tab-diagnostics" data-bc-tab="diagnostics" aria-controls="bc-panel-diagnostics" aria-selected="false"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><?php esc_html_e( 'Diagnostics', 'bc-mcp-abilities' ); ?></button>
					<div class="bc-sidebar-footer"><span class="dashicons dashicons-lock" aria-hidden="true"></span><span><?php esc_html_e( 'Write access is off by default. Existing WordPress capabilities always apply.', 'bc-mcp-abilities' ); ?></span></div>
				</nav>
				<main class="bc-workspace">
					<section class="bc-tab-panel" id="bc-panel-overview" role="tabpanel" aria-labelledby="bc-tab-overview">
						<div class="bc-section-header"><div><p class="bc-eyebrow"><?php esc_html_e( 'Workspace overview', 'bc-mcp-abilities' ); ?></p><h2><?php esc_html_e( 'Everything at a glance', 'bc-mcp-abilities' ); ?></h2><p><?php esc_html_e( 'Review your setup, enable only the operations you need, and inspect connection events.', 'bc-mcp-abilities' ); ?></p></div></div>
						<div class="bc-stats">
							<div class="bc-stat"><span class="bc-stat-icon dashicons dashicons-admin-network" aria-hidden="true"></span><strong><?php echo esc_html( (string) $total ); ?></strong><span><?php esc_html_e( 'Registered abilities', 'bc-mcp-abilities' ); ?></span></div>
							<div class="bc-stat"><span class="bc-stat-icon dashicons dashicons-lock" aria-hidden="true"></span><strong><?php echo esc_html( (string) count( $enabled ) ); ?> <small>/ <?php echo esc_html( (string) count( $writes ) ); ?></small></strong><span><?php esc_html_e( 'Enabled write abilities', 'bc-mcp-abilities' ); ?></span></div>
							<div class="bc-stat"><span class="bc-stat-icon dashicons dashicons-admin-links" aria-hidden="true"></span><strong><?php echo esc_html( $server_on && $oauth_on ? __( 'Ready', 'bc-mcp-abilities' ) : __( 'Setup', 'bc-mcp-abilities' ) ); ?></strong><span><?php esc_html_e( 'Connection configuration', 'bc-mcp-abilities' ); ?></span></div>
						</div>
						<div class="bc-overview-grid">
							<div class="bc-card">
								<div class="bc-card-title"><h3><?php esc_html_e( 'Connection health', 'bc-mcp-abilities' ); ?></h3><p><?php esc_html_e( 'Configuration overview, not a live network check.', 'bc-mcp-abilities' ); ?></p></div>
								<div class="bc-overview-row"><span><?php esc_html_e( 'MCP endpoint', 'bc-mcp-abilities' ); ?></span><?php self::status( $server_on ); ?></div>
								<div class="bc-overview-row"><span><?php esc_html_e( 'OAuth authorization', 'bc-mcp-abilities' ); ?></span><?php self::status( $oauth_on ); ?></div>
								<div class="bc-overview-row"><span><?php esc_html_e( 'HTTPS website', 'bc-mcp-abilities' ); ?></span><?php self::status( is_ssl() ); ?></div>
								<button type="button" class="button bc-outline-button" data-bc-goto="connections"><?php esc_html_e( 'Manage connections', 'bc-mcp-abilities' ); ?></button>
							</div>
							<div class="bc-card bc-card-accent">
								<div class="bc-card-title"><h3><?php esc_html_e( 'Manage capabilities', 'bc-mcp-abilities' ); ?></h3><p><?php esc_html_e( 'Readable abilities are controlled by WordPress permissions. Writes require explicit opt-in and user confirmation.', 'bc-mcp-abilities' ); ?></p></div>
								<p class="bc-spotlight-number"><?php echo esc_html( (string) count( $writes ) ); ?></p>
								<p class="bc-muted"><?php esc_html_e( 'Available write permission switches', 'bc-mcp-abilities' ); ?></p>
								<button type="button" class="button button-primary" data-bc-goto="abilities"><?php esc_html_e( 'Manage abilities', 'bc-mcp-abilities' ); ?></button>
							</div>
						</div>
						<div class="bc-alert"><span class="dashicons dashicons-info" aria-hidden="true"></span><p><?php esc_html_e( 'This is a development preview. Test new permissions on staging before enabling changes to a production website.', 'bc-mcp-abilities' ); ?></p></div>
					</section>
					<section class="bc-tab-panel" id="bc-panel-connections" role="tabpanel" aria-labelledby="bc-tab-connections"><?php self::connections(); ?></section>
					<section class="bc-tab-panel" id="bc-panel-abilities" role="tabpanel" aria-labelledby="bc-tab-abilities">
						<div class="bc-section-header"><div><p class="bc-eyebrow"><?php esc_html_e( 'Permission controls', 'bc-mcp-abilities' ); ?></p><h2><?php esc_html_e( 'Abilities & access', 'bc-mcp-abilities' ); ?></h2><p><?php esc_html_e( 'Select exactly which write operations can be invoked by authenticated MCP clients.', 'bc-mcp-abilities' ); ?></p></div></div>
						<div class="bc-alert"><span class="dashicons dashicons-shield" aria-hidden="true"></span><p><?php esc_html_e( 'Every write also requires WordPress administrator permissions and a confirmed tool call. Enabling a permission does not itself execute an operation.', 'bc-mcp-abilities' ); ?></p></div>
						<?php self::read_only_catalog(); ?>
						<div class="bc-card">
							<form method="post" action="options.php" id="bc-permissions-form">
								<?php settings_fields( 'bc_mcp_abilities' ); ?>
								<input type="hidden" name="<?php echo esc_attr( BC_MCP_Abilities_Admin::OPTION ); ?>" value="" />
								<div class="bc-permissions-toolbar">
									<div class="bc-search-wrap"><span class="dashicons dashicons-search" aria-hidden="true"></span><input type="search" data-bc-search placeholder="<?php esc_attr_e( 'Search abilities...', 'bc-mcp-abilities' ); ?>" aria-label="<?php esc_attr_e( 'Search write abilities', 'bc-mcp-abilities' ); ?>" /></div>
									<span class="bc-count"><strong data-bc-enabled-count><?php echo esc_html( (string) count( $enabled ) ); ?></strong> / <?php echo esc_html( (string) count( $writes ) ); ?> <?php esc_html_e( 'enabled', 'bc-mcp-abilities' ); ?></span>
								</div>
								<?php foreach ( $groups as $title => $slugs ) : ?>
									<?php self::ability_group( $title, $slugs, $writes ); ?>
								<?php endforeach; ?>
								<p class="bc-search-empty" data-bc-no-results hidden><?php esc_html_e( 'No matching permissions found.', 'bc-mcp-abilities' ); ?></p>
								<div class="bc-sticky-actions"><p><?php esc_html_e( 'Save changes to apply permission settings.', 'bc-mcp-abilities' ); ?></p><?php submit_button( __( 'Save permissions', 'bc-mcp-abilities' ), 'primary', 'submit', false ); ?></div>
							</form>
						</div>
					</section>
					<section class="bc-tab-panel" id="bc-panel-diagnostics" role="tabpanel" aria-labelledby="bc-tab-diagnostics"><?php self::diagnostics(); ?></section>
				</main>
			</div>
		</div>
		<?php
	}
}
