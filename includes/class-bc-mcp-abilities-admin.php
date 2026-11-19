<?php
/**
 * Administrator-controlled MCP write permissions.
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * WordPress settings page and per-ability write allowlist.
 */
final class BC_MCP_Abilities_Admin {
	/**
	 * Option storing the allowed write ability names.
	 */
	const OPTION = 'bc_mcp_abilities_enabled_writes';

	/**
	 * Human-readable descriptions for opt-in write abilities.
	 *
	 * @return array<string,string>
	 */
	public static function writable_abilities() {
		return array(
			'create-draft'       => __( 'Create WordPress post and page drafts', 'bc-mcp-abilities' ),
			'update-content'     => __( 'Edit existing posts and pages with revision checking', 'bc-mcp-abilities' ),
			'restore-revision'   => __( 'Restore historical post revisions with conflict protection', 'bc-mcp-abilities' ),
			'update-media-alt'   => __( 'Update image alternative text', 'bc-mcp-abilities' ),
		);
	}

	/**
	 * Initialize the admin interface.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
	}

	/**
	 * Add the settings menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_options_page(
			__( 'BC MCP Abilities', 'bc-mcp-abilities' ),
			__( 'BC MCP Abilities', 'bc-mcp-abilities' ),
			'manage_options',
			'bc-mcp-abilities',
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Register an allowlisted settings option.
	 *
	 * @return void
	 */
	public static function register_setting() {
		register_setting(
			'bc_mcp_abilities',
			self::OPTION,
			array(
				'type'              => 'array',
				'description'       => __( 'Allowed AI-facing write abilities.', 'bc-mcp-abilities' ),
				'default'           => array(),
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Allow only known values regardless of submitted form data.
	 *
	 * @param mixed $value Saved settings data.
	 * @return string[]
	 */
	public static function sanitize( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$values  = array_map( 'sanitize_key', $value );
		$allowed = array_keys( self::writable_abilities() );

		return array_values( array_intersect( $allowed, $values ) );
	}

	/**
	 * Check explicit site-admin consent for a write ability.
	 *
	 * @param string $slug Ability slug.
	 * @return bool
	 */
	public static function is_enabled( $slug ) {
		$settings = get_option( self::OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();

		return in_array( $slug, $settings, true );
	}

	/**
	 * Render settings with the WordPress Settings API.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$endpoint = rest_url( 'mcp/mcp-adapter-default-server' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'BC MCP Abilities', 'bc-mcp-abilities' ); ?></h1>
			<p><?php esc_html_e( 'Abilities use WordPress core and do not require the MCP Adapter. For MCP client connections, install and configure the official WordPress MCP Adapter separately.', 'bc-mcp-abilities' ); ?></p>
			<p>
				<strong><?php esc_html_e( 'Default MCP endpoint (only when MCP Adapter is active):', 'bc-mcp-abilities' ); ?></strong>
				<code><?php echo esc_html( $endpoint ); ?></code>
			</p>
			<p><?php esc_html_e( 'Read-only abilities are available to authorized WordPress users. All write abilities are disabled for MCP/REST clients until you enable them here. Enabling a tool never bypasses WordPress capability checks.', 'bc-mcp-abilities' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'bc_mcp_abilities' ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::OPTION ); ?>" value="" />
				<table class="form-table" role="presentation">
					<tbody>
						<?php foreach ( self::writable_abilities() as $slug => $description ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $slug ); ?></th>
								<td>
									<label>
										<input
											type="checkbox"
											name="<?php echo esc_attr( self::OPTION ); ?>[]"
											value="<?php echo esc_attr( $slug ); ?>"
											<?php checked( self::is_enabled( $slug ) ); ?>
										/>
										<?php echo esc_html( $description ); ?>
									</label>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save Ability Permissions', 'bc-mcp-abilities' ) ); ?>
			</form>
		</div>
		<?php
	}
}
