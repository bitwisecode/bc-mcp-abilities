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
	 * Return a friendly, translatable label for each available editing action.
	 *
	 * Keep the underlying ability slug visible separately for technical users.
	 *
	 * @param string $slug First-party ability slug.
	 * @return string
	 */
	private static function write_ability_label( $slug ) {
		$labels = array(
			'create-draft'              => __( 'Create a draft', 'bc-mcp-abilities' ),
			'update-content'            => __( 'Edit a post or page', 'bc-mcp-abilities' ),
			'restore-revision'          => __( 'Restore an earlier version', 'bc-mcp-abilities' ),
			'elementor-update-text'     => __( 'Edit Elementor text', 'bc-mcp-abilities' ),
			'elementor-undo'            => __( 'Undo Elementor changes', 'bc-mcp-abilities' ),
			'wc-update-stock'           => __( 'Update WooCommerce stock', 'bc-mcp-abilities' ),
			'create-taxonomy-term'      => __( 'Create categories or tags', 'bc-mcp-abilities' ),
			'assign-post-terms'         => __( 'Assign categories or tags', 'bc-mcp-abilities' ),
			'create-menu'               => __( 'Create a navigation menu', 'bc-mcp-abilities' ),
			'add-menu-item'             => __( 'Add a menu item', 'bc-mcp-abilities' ),
			'rank-math-update'          => __( 'Update Rank Math SEO', 'bc-mcp-abilities' ),
			'yoast-seo-update'          => __( 'Update Yoast SEO', 'bc-mcp-abilities' ),
			'wc-update-prices'          => __( 'Edit WooCommerce prices', 'bc-mcp-abilities' ),
			'upload-image'              => __( 'Import an image', 'bc-mcp-abilities' ),
			'update-media-alt'          => __( 'Edit image alt text', 'bc-mcp-abilities' ),
			'create-custom-draft'       => __( 'Create a custom content draft', 'bc-mcp-abilities' ),
			'update-custom-content'     => __( 'Edit custom content', 'bc-mcp-abilities' ),
			'elementor-create-document' => __( 'Create an Elementor draft', 'bc-mcp-abilities' ),
			'elementor-update-settings' => __( 'Edit Elementor settings', 'bc-mcp-abilities' ),
			'elementor-add-element'     => __( 'Add an Elementor element', 'bc-mcp-abilities' ),
			'elementor-delete-element'  => __( 'Remove an Elementor element', 'bc-mcp-abilities' ),
			'elementor-move-element'    => __( 'Move an Elementor element', 'bc-mcp-abilities' ),
			'elementor-regenerate-css'  => __( 'Refresh Elementor styles', 'bc-mcp-abilities' ),
		);
		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : ucwords( str_replace( '-', ' ', $slug ) );
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
					<label class="bc-permission" data-bc-permission data-bc-search-text="<?php echo esc_attr( strtolower( self::write_ability_label( $slug ) . ' ' . $slug . ' ' . $description ) ); ?>">
						<span class="bc-permission-copy">
							<strong><?php echo esc_html( self::write_ability_label( $slug ) ); ?></strong>
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
				<p><?php esc_html_e( 'Connect your WordPress website with supported AI assistants and manage how they access your content.', 'bc-mcp-abilities' ); ?></p>
			</div>
			<?php self::status( $server_on && $oauth_on && is_ssl() ); ?>
		</div>
		<?php if ( ! is_ssl() ) : ?>
			<div class="bc-alert bc-alert-danger"><?php esc_html_e( 'A secure HTTPS connection is required. Enable HTTPS on your website to use WordPress sign-in.', 'bc-mcp-abilities' ); ?></div>
		<?php endif; ?>
		<div class="bc-card">
			<div class="bc-card-title"><h3><?php esc_html_e( 'Connection preferences', 'bc-mcp-abilities' ); ?></h3><p><?php esc_html_e( 'Choose how AI assistants can connect to your WordPress website.', 'bc-mcp-abilities' ); ?></p></div>
			<form method="post" action="options.php">
				<?php settings_fields( 'bc_mcp_connections' ); ?>
				<input type="hidden" name="<?php echo esc_attr( BC_MCP_Abilities_Server::OPTION ); ?>" value="0" />
				<input type="hidden" name="<?php echo esc_attr( BC_MCP_Abilities_OAuth::OPTION ); ?>" value="0" />
				<input type="hidden" name="bc_mcp_oauth_debug_enabled" value="0" />
				<label class="bc-setting-row">
					<span><strong><?php esc_html_e( 'Enable MCP connection', 'bc-mcp-abilities' ); ?></strong><small><?php esc_html_e( 'Allow supported AI assistants to communicate securely with your website.', 'bc-mcp-abilities' ); ?></small></span>
					<span class="bc-switch"><input type="checkbox" name="<?php echo esc_attr( BC_MCP_Abilities_Server::OPTION ); ?>" value="1" <?php checked( $server_on ); ?> /><span class="bc-switch-track" aria-hidden="true"></span></span>
				</label>
				<label class="bc-setting-row">
					<span><strong><?php esc_html_e( 'Secure WordPress sign-in', 'bc-mcp-abilities' ); ?></strong><small><?php esc_html_e( 'Allow users to sign in with WordPress and approve access when connecting an AI assistant.', 'bc-mcp-abilities' ); ?></small></span>
					<span class="bc-switch"><input type="checkbox" name="<?php echo esc_attr( BC_MCP_Abilities_OAuth::OPTION ); ?>" value="1" <?php checked( $oauth_on ); ?> <?php disabled( ! is_ssl() ); ?> /><span class="bc-switch-track" aria-hidden="true"></span></span>
				</label>
				<label class="bc-setting-row">
					<span><strong><?php esc_html_e( 'Connection troubleshooting', 'bc-mcp-abilities' ); ?></strong><small><?php esc_html_e( 'Record limited connection events to help diagnose issues. Records expire after 24 hours and are deleted when this option is turned off.', 'bc-mcp-abilities' ); ?></small></span>
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
					<div><p class="bc-eyebrow"><?php esc_html_e( 'WordPress connection', 'bc-mcp-abilities' ); ?></p><h3><?php esc_html_e( 'ChatGPT', 'bc-mcp-abilities' ); ?></h3></div>
				</div>
				<p class="bc-client-description"><?php esc_html_e( 'Connect ChatGPT to your website using your WordPress account and secure authorization.', 'bc-mcp-abilities' ); ?></p>
				<label class="bc-client-field-label" for="bc-mcp-server-url"><?php esc_html_e( 'MCP server URL', 'bc-mcp-abilities' ); ?></label>
				<div class="bc-copy-field">
					<input id="bc-mcp-server-url" type="text" value="<?php echo esc_attr( $endpoint ); ?>" readonly aria-label="<?php esc_attr_e( 'ChatGPT MCP server URL', 'bc-mcp-abilities' ); ?>" />
					<button class="button bc-copy-button" type="button" data-bc-copy="bc-mcp-server-url"><?php esc_html_e( 'Copy URL', 'bc-mcp-abilities' ); ?></button>
				</div>
				<ol class="bc-steps">
					<li><?php esc_html_e( 'Turn on MCP connection and secure WordPress sign-in above.', 'bc-mcp-abilities' ); ?></li>
					<li><?php esc_html_e( 'In ChatGPT, add a custom MCP connection and enter the URL shown here.', 'bc-mcp-abilities' ); ?></li>
					<li><?php esc_html_e( 'Sign in to WordPress and approve the requested access to complete the connection.', 'bc-mcp-abilities' ); ?></li>
				</ol>
			</div>
			<div class="bc-card bc-client-card">
				<div class="bc-client-heading">
					<span class="bc-client-logo bc-client-logo-claude" aria-hidden="true">C</span>
					<div><p class="bc-eyebrow"><?php esc_html_e( 'Additional connection option', 'bc-mcp-abilities' ); ?></p><h3><?php esc_html_e( 'Claude', 'bc-mcp-abilities' ); ?></h3></div>
				</div>
				<p class="bc-client-description"><?php esc_html_e( 'Connect Claude using a compatible WordPress MCP provider that supports secure sign-in.', 'bc-mcp-abilities' ); ?></p>
				<label class="bc-client-field-label" for="bc-claude-oauth-url"><?php esc_html_e( 'Claude connection URL', 'bc-mcp-abilities' ); ?></label>
				<div class="bc-copy-field">
					<input id="bc-claude-oauth-url" type="text" value="<?php echo esc_attr( $claude_oauth ); ?>" readonly aria-label="<?php esc_attr_e( 'Claude OAuth MCP provider URL', 'bc-mcp-abilities' ); ?>" />
					<button class="button bc-copy-button" type="button" data-bc-copy="bc-claude-oauth-url"><?php esc_html_e( 'Copy URL', 'bc-mcp-abilities' ); ?></button>
				</div>
				<ol class="bc-steps">
					<li><?php esc_html_e( 'Ensure your OAuth-compatible MCP provider is installed and enabled.', 'bc-mcp-abilities' ); ?></li>
					<li><?php esc_html_e( 'Add the connection URL in Claude, then sign in to WordPress when prompted.', 'bc-mcp-abilities' ); ?></li>
					<li><?php esc_html_e( 'If Claude is already connected, you can continue using your current connection URL.', 'bc-mcp-abilities' ); ?></li>
				</ol>
				<p class="bc-client-note"><?php esc_html_e( 'This Claude URL is provided by a separate, optional MCP integration. It will work only when that provider is installed and enabled.', 'bc-mcp-abilities' ); ?></p>
			</div>
		</div>
		<p class="bc-muted"><?php esc_html_e( 'The connection indicator reflects your saved settings. It does not confirm that an external AI assistant is connected.', 'bc-mcp-abilities' ); ?></p>
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
			<div><p class="bc-eyebrow"><?php esc_html_e( 'Troubleshooting', 'bc-mcp-abilities' ); ?></p><h2><?php esc_html_e( 'Connection activity', 'bc-mcp-abilities' ); ?></h2><p><?php esc_html_e( 'View recent connection events without exposing passwords, access tokens, or message content.', 'bc-mcp-abilities' ); ?></p></div>
			<?php self::status( $enabled ); ?>
		</div>
		<div class="bc-card">
			<div class="bc-card-title"><h3><?php esc_html_e( 'Recent events', 'bc-mcp-abilities' ); ?></h3><p><?php esc_html_e( 'Up to 50 recent events are kept for 24 hours, with the newest shown first.', 'bc-mcp-abilities' ); ?></p></div>
			<?php if ( ! $enabled ) : ?>
				<div class="bc-empty"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><strong><?php esc_html_e( 'Connection logging is off', 'bc-mcp-abilities' ); ?></strong><p><?php esc_html_e( 'Enable connection troubleshooting under Connections to see recent connection events.', 'bc-mcp-abilities' ); ?></p><button type="button" class="button" data-bc-goto="connections"><?php esc_html_e( 'Open connection settings', 'bc-mcp-abilities' ); ?></button></div>
			<?php elseif ( empty( $events ) ) : ?>
				<div class="bc-empty"><strong><?php esc_html_e( 'No events yet', 'bc-mcp-abilities' ); ?></strong><p><?php esc_html_e( 'Connection events will appear here when an assistant connects to your website.', 'bc-mcp-abilities' ); ?></p></div>
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
			<p class="bc-muted"><?php esc_html_e( 'Turn off connection troubleshooting when it is no longer needed. Saved events will be deleted.', 'bc-mcp-abilities' ); ?></p>
		</div>
		<?php
	}


	/**
	 * Render a transparent inventory of registered read-only operations.
	 *
	 * @return void
	 */
	private static function read_only_catalog() {
		$items = BC_MCP_Abilities::read_catalog();
		?>
		<details class="bc-card bc-readonly-catalog">
			<summary>
				<span><strong><?php esc_html_e( 'Read-only access', 'bc-mcp-abilities' ); ?></strong><small><?php esc_html_e( 'Choose which read-only actions are available to connected AI assistants.', 'bc-mcp-abilities' ); ?></small></span>
				<span class="bc-readonly-number"><?php echo esc_html( (string) count( $items ) ); ?> <?php esc_html_e( 'read-only actions', 'bc-mcp-abilities' ); ?></span>
			</summary>
			<form method="post" action="options.php" class="bc-read-exposure-form">
				<?php settings_fields( 'bc_mcp_read_abilities' ); ?>
				<input type="hidden" name="<?php echo esc_attr( BC_MCP_Abilities_Admin::READ_DISABLED_OPTION ); ?>[]" value="" />
				<p class="bc-muted"><?php esc_html_e( 'Select an action to hide it from connected assistants. Read-only actions are available by default to users with the appropriate WordPress permissions. You can change these choices at any time.', 'bc-mcp-abilities' ); ?></p>
				<div class="bc-readonly-list">
					<?php foreach ( $items as $slug => $item ) : ?>
						<label class="bc-read-toggle">
							<span><strong><?php echo esc_html( $item['label'] ); ?></strong><code><?php echo esc_html( 'bc-mcp-abilities/' . $slug ); ?></code><small><?php echo esc_html( $item['description'] ); ?></small></span>
							<span class="bc-switch">
								<input type="checkbox" name="<?php echo esc_attr( BC_MCP_Abilities_Admin::READ_DISABLED_OPTION ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( BC_MCP_Abilities_Admin::is_read_disabled( $slug ) ); ?> />
								<span class="bc-switch-track" aria-hidden="true"></span>
								<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: ability label. */ __( 'Hide %s from AI clients', 'bc-mcp-abilities' ), $item['label'] ) ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
				<div class="bc-form-actions"><?php submit_button( __( 'Save read-only settings', 'bc-mcp-abilities' ), 'primary', 'submit', false ); ?></div>
			</form>
		</details>
		<?php
	}

	/**
	 * Show limited, privacy-preserving execution history.
	 *
	 * @return void
	 */
	private static function activity() {
		$events = array_reverse( BC_MCP_Abilities_Audit::recent() );
		?>
		<div class="bc-section-header">
			<div><p class="bc-eyebrow"><?php esc_html_e( 'Activity records', 'bc-mcp-abilities' ); ?></p><h2><?php esc_html_e( 'Activity history', 'bc-mcp-abilities' ); ?></h2><p><?php esc_html_e( 'Review up to 100 recent actions from the past seven days, including who performed them and whether they succeeded.', 'bc-mcp-abilities' ); ?></p></div>
		</div>
		<div class="bc-card">
			<div class="bc-card-title"><h3><?php esc_html_e( 'Recent actions', 'bc-mcp-abilities' ); ?></h3><p><?php esc_html_e( 'Activity history records action details only. Prompts, passwords, access tokens, and response content are not saved.', 'bc-mcp-abilities' ); ?></p></div>
			<?php if ( ! $events ) : ?>
				<div class="bc-empty"><strong><?php esc_html_e( 'No activity yet', 'bc-mcp-abilities' ); ?></strong><p><?php esc_html_e( 'Actions performed through connected assistants will appear here when they occur.', 'bc-mcp-abilities' ); ?></p></div>
			<?php else : ?>
				<div class="bc-table-scroll">
					<table class="widefat bc-events"><thead><tr><th><?php esc_html_e( 'Time (UTC)', 'bc-mcp-abilities' ); ?></th><th><?php esc_html_e( 'WordPress user', 'bc-mcp-abilities' ); ?></th><th><?php esc_html_e( 'Ability', 'bc-mcp-abilities' ); ?></th><th><?php esc_html_e( 'Type', 'bc-mcp-abilities' ); ?></th><th><?php esc_html_e( 'Outcome', 'bc-mcp-abilities' ); ?></th></tr></thead><tbody>
						<?php foreach ( $events as $event ) : ?>
							<tr>
								<td><?php echo esc_html( gmdate( 'Y-m-d H:i:s', $event['time'] ) ); ?></td>
								<td><?php echo esc_html( '#' . $event['user_id'] ); ?></td>
								<td><code><?php echo esc_html( $event['ability'] ); ?></code></td>
								<td><?php echo esc_html( 'write' === $event['kind'] ? __( 'Edit', 'bc-mcp-abilities' ) : __( 'Read', 'bc-mcp-abilities' ) ); ?></td>
								<td><?php echo esc_html( 'error' === $event['outcome'] ? __( 'Failed', 'bc-mcp-abilities' ) : __( 'Completed', 'bc-mcp-abilities' ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody></table>
				</div>
			<?php endif; ?>
			<div class="bc-activity-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="bc_mcp_export_activity" />
					<?php wp_nonce_field( 'bc_mcp_export_activity' ); ?>
					<?php submit_button( __( 'Export CSV', 'bc-mcp-abilities' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-bc-clear-activity>
					<input type="hidden" name="action" value="bc_mcp_clear_activity" />
					<?php wp_nonce_field( 'bc_mcp_clear_activity' ); ?>
					<?php submit_button( __( 'Clear history', 'bc-mcp-abilities' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
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
			__( 'Content & posts', 'bc-mcp-abilities' )    => array( 'create-draft', 'update-content', 'restore-revision', 'create-custom-draft', 'update-custom-content' ),
			__( 'Elementor', 'bc-mcp-abilities' )          => array( 'elementor-update-text', 'elementor-undo', 'elementor-create-document', 'elementor-update-settings', 'elementor-add-element', 'elementor-delete-element', 'elementor-move-element', 'elementor-regenerate-css' ),
			__( 'WooCommerce', 'bc-mcp-abilities' )        => array( 'wc-update-stock', 'wc-update-prices' ),
			__( 'Taxonomies & menus', 'bc-mcp-abilities' ) => array( 'create-taxonomy-term', 'assign-post-terms', 'create-menu', 'add-menu-item' ),
			__( 'SEO & media', 'bc-mcp-abilities' )        => array( 'rank-math-update', 'yoast-seo-update', 'upload-image', 'update-media-alt' ),
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
					<div><h1><?php esc_html_e( 'MCP Abilities', 'bc-mcp-abilities' ); ?></h1><p><?php esc_html_e( 'Manage AI connections, permissions, and activity for your WordPress website.', 'bc-mcp-abilities' ); ?></p></div>
					<div class="bc-hero-version"><span><?php esc_html_e( 'WordPress integration', 'bc-mcp-abilities' ); ?></span></div>
				</div>
			</div>
			<div class="bc-layout">
				<nav class="bc-sidebar" role="tablist" aria-label="<?php esc_attr_e( 'MCP settings sections', 'bc-mcp-abilities' ); ?>" aria-orientation="vertical">
					<p class="bc-sidebar-label"><?php esc_html_e( 'WORKSPACE', 'bc-mcp-abilities' ); ?></p>
					<button type="button" role="tab" class="bc-tab is-active" id="bc-tab-overview" data-bc-tab="overview" aria-controls="bc-panel-overview" aria-selected="true"><span class="dashicons dashicons-dashboard" aria-hidden="true"></span><?php esc_html_e( 'Overview', 'bc-mcp-abilities' ); ?></button>
					<button type="button" role="tab" class="bc-tab" id="bc-tab-connections" data-bc-tab="connections" aria-controls="bc-panel-connections" aria-selected="false"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><?php esc_html_e( 'Connections', 'bc-mcp-abilities' ); ?></button>
					<button type="button" role="tab" class="bc-tab" id="bc-tab-abilities" data-bc-tab="abilities" aria-controls="bc-panel-abilities" aria-selected="false"><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span><?php esc_html_e( 'Abilities & permissions', 'bc-mcp-abilities' ); ?></button>
					<button type="button" role="tab" class="bc-tab" id="bc-tab-activity" data-bc-tab="activity" aria-controls="bc-panel-activity" aria-selected="false"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><?php esc_html_e( 'Activity history', 'bc-mcp-abilities' ); ?></button>
					<button type="button" role="tab" class="bc-tab" id="bc-tab-diagnostics" data-bc-tab="diagnostics" aria-controls="bc-panel-diagnostics" aria-selected="false"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><?php esc_html_e( 'Diagnostics', 'bc-mcp-abilities' ); ?></button>
					<div class="bc-sidebar-footer"><span class="dashicons dashicons-lock" aria-hidden="true"></span><span><?php esc_html_e( 'Website changes are disabled by default. WordPress user permissions always apply.', 'bc-mcp-abilities' ); ?></span></div>
				</nav>
				<main class="bc-workspace">
					<section class="bc-tab-panel" id="bc-panel-overview" role="tabpanel" aria-labelledby="bc-tab-overview">
						<div class="bc-section-header"><div><p class="bc-eyebrow"><?php esc_html_e( 'Account overview', 'bc-mcp-abilities' ); ?></p><h2><?php esc_html_e( 'Your website at a glance', 'bc-mcp-abilities' ); ?></h2><p><?php esc_html_e( 'See your available actions, manage access, and review connection activity in one place.', 'bc-mcp-abilities' ); ?></p></div></div>
						<div class="bc-stats">
							<div class="bc-stat"><span class="bc-stat-icon dashicons dashicons-admin-network" aria-hidden="true"></span><strong><?php echo esc_html( (string) $total ); ?></strong><span><?php esc_html_e( 'Available actions', 'bc-mcp-abilities' ); ?></span></div>
							<div class="bc-stat"><span class="bc-stat-icon dashicons dashicons-lock" aria-hidden="true"></span><strong><?php echo esc_html( (string) count( $enabled ) ); ?> <small>/ <?php echo esc_html( (string) count( $writes ) ); ?></small></strong><span><?php esc_html_e( 'Enabled editing actions', 'bc-mcp-abilities' ); ?></span></div>
							<div class="bc-stat"><span class="bc-stat-icon dashicons dashicons-admin-links" aria-hidden="true"></span><strong><?php echo esc_html( $server_on && $oauth_on ? __( 'Configured', 'bc-mcp-abilities' ) : __( 'Not configured', 'bc-mcp-abilities' ) ); ?></strong><span><?php esc_html_e( 'Connection settings', 'bc-mcp-abilities' ); ?></span></div>
						</div>
						<div class="bc-overview-grid">
							<div class="bc-card">
								<div class="bc-card-title"><h3><?php esc_html_e( 'Connection status', 'bc-mcp-abilities' ); ?></h3><p><?php esc_html_e( 'This section shows which connection settings are enabled on your website.', 'bc-mcp-abilities' ); ?></p></div>
								<div class="bc-overview-row"><span><?php esc_html_e( 'MCP endpoint', 'bc-mcp-abilities' ); ?></span><?php self::status( $server_on ); ?></div>
								<div class="bc-overview-row"><span><?php esc_html_e( 'OAuth authorization', 'bc-mcp-abilities' ); ?></span><?php self::status( $oauth_on ); ?></div>
								<div class="bc-overview-row"><span><?php esc_html_e( 'HTTPS website', 'bc-mcp-abilities' ); ?></span><?php self::status( is_ssl() ); ?></div>
								<button type="button" class="button bc-outline-button" data-bc-goto="connections"><?php esc_html_e( 'Manage connections', 'bc-mcp-abilities' ); ?></button>
							</div>
							<div class="bc-card bc-card-accent">
								<div class="bc-card-title"><h3><?php esc_html_e( 'Manage access', 'bc-mcp-abilities' ); ?></h3><p><?php esc_html_e( 'Read-only actions follow WordPress user permissions. Website changes must be enabled separately by an administrator.', 'bc-mcp-abilities' ); ?></p></div>
								<p class="bc-spotlight-number"><?php echo esc_html( (string) count( $writes ) ); ?></p>
								<p class="bc-muted"><?php esc_html_e( 'Available editing permissions', 'bc-mcp-abilities' ); ?></p>
								<button type="button" class="button button-primary" data-bc-goto="abilities"><?php esc_html_e( 'Manage abilities', 'bc-mcp-abilities' ); ?></button>
							</div>
						</div>
						<div class="bc-alert"><span class="dashicons dashicons-info" aria-hidden="true"></span><p><?php esc_html_e( 'For security, enable only the actions you need. Connected assistants can make changes to your website when you grant editing access.', 'bc-mcp-abilities' ); ?></p></div>
					</section>
					<section class="bc-tab-panel" id="bc-panel-connections" role="tabpanel" aria-labelledby="bc-tab-connections"><?php self::connections(); ?></section>
					<section class="bc-tab-panel" id="bc-panel-abilities" role="tabpanel" aria-labelledby="bc-tab-abilities">
						<div class="bc-section-header"><div><p class="bc-eyebrow"><?php esc_html_e( 'Access controls', 'bc-mcp-abilities' ); ?></p><h2><?php esc_html_e( 'Abilities & permissions', 'bc-mcp-abilities' ); ?></h2><p><?php esc_html_e( 'Choose which website changes connected AI assistants are allowed to make.', 'bc-mcp-abilities' ); ?></p></div></div>
						<div class="bc-alert"><span class="dashicons dashicons-shield" aria-hidden="true"></span><p><?php esc_html_e( 'Enabling an action allows authorized WordPress users to perform it through connected assistants. No changes are made simply by enabling an action.', 'bc-mcp-abilities' ); ?></p></div>
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
								<div class="bc-sticky-actions"><p><?php esc_html_e( 'Changes take effect after you save these permissions.', 'bc-mcp-abilities' ); ?></p><?php submit_button( __( 'Save permissions', 'bc-mcp-abilities' ), 'primary', 'submit', false ); ?></div>
							</form>
						</div>
					</section>
					<section class="bc-tab-panel" id="bc-panel-activity" role="tabpanel" aria-labelledby="bc-tab-activity"><?php self::activity(); ?></section>
					<section class="bc-tab-panel" id="bc-panel-diagnostics" role="tabpanel" aria-labelledby="bc-tab-diagnostics"><?php self::diagnostics(); ?></section>
				</main>
			</div>
		</div>
		<?php
	}
}
