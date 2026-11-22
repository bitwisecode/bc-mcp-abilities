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
	 * Denied read-only first-party ability slugs.
	 */
	const READ_DISABLED_OPTION = 'bc_mcp_abilities_disabled_reads';

	/**
	 * Human-readable descriptions for opt-in write abilities.
	 *
	 * @return array<string,string>
	 */
	public static function writable_abilities() {
		return array(
			'create-draft'              => __( 'Create WordPress post and page drafts', 'bc-mcp-abilities' ),
			'update-content'            => __( 'Update posts and pages without overwriting newer changes', 'bc-mcp-abilities' ),
			'restore-revision'          => __( 'Restore an earlier version of a post or page', 'bc-mcp-abilities' ),
			'elementor-update-text'     => __( 'Edit text in supported Elementor widgets with an undo option', 'bc-mcp-abilities' ),
			'elementor-undo'            => __( 'Undo the last recorded Elementor text update', 'bc-mcp-abilities' ),
			'wc-update-stock'           => __( 'Adjust stock for eligible WooCommerce products with change protection', 'bc-mcp-abilities' ),
			'create-taxonomy-term'      => __( 'Create categories, tags, and supported custom terms', 'bc-mcp-abilities' ),
			'assign-post-terms'         => __( 'Update categories and tags on a post without overwriting newer changes', 'bc-mcp-abilities' ),
			'create-menu'               => __( 'Create a new WordPress navigation menu', 'bc-mcp-abilities' ),
			'add-menu-item'             => __( 'Add pages, posts, or supported terms to an existing navigation menu', 'bc-mcp-abilities' ),
			'rank-math-update'          => __( 'Update SEO titles, descriptions, and focus keywords in Rank Math', 'bc-mcp-abilities' ),
			'rankmath-create-redirect'   => __( 'Create a new exact-match Rank Math 301 redirection', 'bc-mcp-abilities' ),
			'rankmath-update-redirect'   => __( 'Update an existing Rank Math redirect with revision protection', 'bc-mcp-abilities' ),
			'rankmath-delete-redirect'   => __( 'Permanently remove an existing Rank Math redirect with revision protection', 'bc-mcp-abilities' ),
			'rankmath-bulk-import-redirects' => __( 'Preview or import up to 50 Rank Math redirects with duplicate checks', 'bc-mcp-abilities' ),
			'yoast-seo-update'          => __( 'Update SEO titles, descriptions, and focus keywords in Yoast SEO', 'bc-mcp-abilities' ),
			'wc-update-prices'          => __( 'Update regular and sale prices for an individual WooCommerce product or variation', 'bc-mcp-abilities' ),
			'upload-image'              => __( 'Import images from secure public web links into the Media Library', 'bc-mcp-abilities' ),
			'update-media-alt'          => __( 'Update image alternative text', 'bc-mcp-abilities' ),
			'create-custom-draft'       => __( 'Create drafts in supported custom content types', 'bc-mcp-abilities' ),
			'update-custom-content'     => __( 'Update custom content without overwriting newer changes', 'bc-mcp-abilities' ),
			'elementor-create-document' => __( 'Create a draft post or page for editing with Elementor', 'bc-mcp-abilities' ),
			'elementor-update-settings' => __( 'Change supported Elementor widget settings with an undo option', 'bc-mcp-abilities' ),
			'elementor-add-element'     => __( 'Add supported Elementor widgets and containers', 'bc-mcp-abilities' ),
			'elementor-delete-element'  => __( 'Remove Elementor elements with a recovery option', 'bc-mcp-abilities' ),
			'elementor-move-element'    => __( 'Reorder or move Elementor elements', 'bc-mcp-abilities' ),
			'elementor-regenerate-css'  => __( 'Refresh styles for an Elementor page or post', 'bc-mcp-abilities' ),
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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
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
	 * Enqueue isolated admin assets only on this plugin's settings screen.
	 *
	 * @param string $hook_suffix WordPress admin page identifier.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( 'settings_page_bc-mcp-abilities' !== $hook_suffix || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_style(
			'bc-mcp-settings',
			plugins_url( 'assets/admin.css', dirname( __DIR__ ) . '/bc-mcp-abilities.php' ),
			array(),
			BC_MCP_ABILITIES_VERSION
		);
		wp_enqueue_script(
			'bc-mcp-settings',
			plugins_url( 'assets/admin.js', dirname( __DIR__ ) . '/bc-mcp-abilities.php' ),
			array(),
			BC_MCP_ABILITIES_VERSION,
			true
		);
		wp_localize_script(
			'bc-mcp-settings',
			'bcMcpSettings',
			array(
				'copied'        => __( 'Copied!', 'bc-mcp-abilities' ),
				'confirmClear'  => __( 'Permanently clear the stored BC MCP activity history?', 'bc-mcp-abilities' ),
				'confirmRevoke' => __( 'Disconnect every connected AI assistant? Users will need to connect again.', 'bc-mcp-abilities' ),
			)
		);
	}

	/**
	 * Register an allowlisted settings option.
	 *
	 * @return void
	 */
	public static function register_setting() {
		register_setting(
			'bc_mcp_read_abilities',
			self::READ_DISABLED_OPTION,
			array(
				'type'              => 'array',
				'description'       => __( 'First-party read abilities hidden from all AI clients.', 'bc-mcp-abilities' ),
				'default'           => array(),
				'sanitize_callback' => array( __CLASS__, 'sanitize_disabled_reads' ),
				'show_in_rest'      => false,
			)
		);
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

		$values  = array_map( 'sanitize_key', array_filter( $value, 'is_string' ) );
		$allowed = array_keys( self::writable_abilities() );

		return array_values( array_intersect( $allowed, $values ) );
	}

	/**
	 * Filter denied read-only slugs to actual first-party read abilities.
	 *
	 * @param mixed $value Submitted settings.
	 * @return array
	 */
	public static function sanitize_disabled_reads( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$catalog = BC_MCP_Abilities::read_catalog();
		$values  = array_map( 'sanitize_key', array_filter( $value, 'is_string' ) );
		return array_values( array_intersect( array_keys( $catalog ), $values ) );
	}

	/**
	 * Whether this read-only ability must remain unavailable to AI clients.
	 *
	 * @param string $slug Read ability slug.
	 * @return bool
	 */
	public static function is_read_disabled( $slug ) {
		$settings = get_option( self::READ_DISABLED_OPTION, array() );
		$settings = is_array( $settings ) ? $settings : array();
		return in_array( $slug, $settings, true );
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
		BC_MCP_Abilities_UI::render();
	}
}
