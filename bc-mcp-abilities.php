<?php
/**
 * Plugin Name: BC MCP Abilities
 * Plugin URI: https://github.com/bitwisecode/bc-mcp-abilities
 * Description: Independent, permission-checked WordPress Abilities for AI agents. Optional MCP Adapter integration.
 * Version: 1.0.0
 * Author: BitwiseCode
 * Author URI: https://bitwisecode.com
 * License: GPLv2 or later
 * Text Domain: bc-mcp-abilities
 * Requires at least: 6.9
 * Requires PHP: 7.4
 *
 * @package BCMCPAbilities
 */

defined( 'ABSPATH' ) || exit;
define( 'BC_MCP_ABILITIES_VERSION', '1.0.0' );
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-admin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-audit.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-integrations.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-elementor.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-woocommerce.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-content.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-elementor-agent.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-server.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-oauth.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-connections.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-ui.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-performance.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-taxonomies.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-menus.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-release-integrations.php';
BC_MCP_Abilities_Admin::init();
BC_MCP_Abilities_Audit::init();
BC_MCP_Abilities::init();
BC_MCP_Abilities_Server::init();
BC_MCP_Abilities_OAuth::init();
register_activation_hook( __FILE__, array( 'BC_MCP_Abilities_OAuth', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BC_MCP_Abilities_OAuth', 'deactivate' ) );
add_action(
	'before_woocommerce_init',
	static function () {
		// Orders are read only through wc_get_orders(), which supports HPOS.
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);
BC_MCP_Abilities_Connections::init();
add_action( 'wp_abilities_api_init', array( 'BC_MCP_Abilities_Integrations', 'register' ), 20 );
add_action( 'wp_abilities_api_init', array( 'BC_MCP_Abilities_Elementor', 'register' ), 20 );
add_action( 'wp_abilities_api_init', array( 'BC_MCP_Abilities_WooCommerce', 'register' ), 20 );
add_action( 'wp_abilities_api_init', array( 'BC_MCP_Abilities_Content', 'register' ), 20 );
add_action( 'wp_abilities_api_init', array( 'BC_MCP_Abilities_Elementor_Agent', 'register' ), 20 );
add_action( 'wp_abilities_api_init', array( 'BC_MCP_Abilities_Performance', 'register' ), 20 );
add_action( 'wp_abilities_api_init', array( 'BC_MCP_Abilities_Taxonomies', 'register' ), 20 );
add_action( 'wp_abilities_api_init', array( 'BC_MCP_Abilities_Menus', 'register' ), 20 );
add_action( 'wp_abilities_api_init', array( 'BC_MCP_Abilities_Release_Integrations', 'register' ), 20 );
