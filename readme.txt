=== BC MCP Abilities ===
Contributors: bitwisecode
Tags: mcp, abilities, ai, automation
Requires at least: 6.9
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 0.1.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent, permission-checked WordPress Abilities plus an optional built-in MCP endpoint and OAuth; works with the official MCP Adapter when available.

== Description ==

BC MCP Abilities registers 35 WordPress-native abilities in the Agent-parity development branch, covering content, media, WooCommerce, Rank Math and guarded Elementor operations. It includes a built-in MCP and OAuth connection that is disabled by default, and works without an external server. The official MCP Adapter remains optional for compatibility. All write abilities require an administrator opt-in and WordPress permission checks.

This is development software. Test on staging only.

== Installation ==

1. Install on WordPress 6.9 or later.
2. Activate the plugin.
3. Use the native WordPress Abilities API, optionally enable the built-in MCP/OAuth connection in Settings, or install the official MCP Adapter.
4. Grant WordPress users only the capabilities they actually need.

== Frequently Asked Questions ==

= Does it work without MCP Adapter? =
Yes. WordPress registers these abilities through its native Abilities API. The plugin can also host an independent OAuth-protected MCP endpoint when enabled by a site administrator.

= Does it allow anonymous management? =
No. Every ability requires a logged-in WordPress user with appropriate permissions.

== Changelog ==

= 0.1.0 =
* Developer preview: 35 abilities, including new eligible custom post type operations and safe Elementor structure/JSON/CSS tools. No unrestricted source editing.
