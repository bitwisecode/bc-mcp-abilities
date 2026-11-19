<?php
/**
 * Plugin Name: BC MCP Abilities
 * Plugin URI: https://github.com/bitwisecode/bc-mcp-abilities
 * Description: Independent, permission-checked WordPress Abilities for AI agents. Optional MCP Adapter integration.
 * Version: 0.1.0-dev
 * Author: BitwiseCode
 * Author URI: https://bitwisecode.com
 * License: GPLv2 or later
 * Text Domain: bc-mcp-abilities
 * Requires at least: 6.9
 * Requires PHP: 7.4
 */
defined( 'ABSPATH' ) || exit;
define( 'BC_MCP_ABILITIES_VERSION', '0.1.0-dev' );
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities-admin.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-bc-mcp-abilities.php';
BC_MCP_Abilities_Admin::init();
BC_MCP_Abilities::init();
