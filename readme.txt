=== BC MCP Abilities ===
Contributors: bitwisecode
Tags: mcp, abilities, ai, automation
Requires at least: 6.9
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 0.1.0-dev
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Independent, permission-checked WordPress Abilities with optional official MCP Adapter support.

== Description ==

BC MCP Abilities registers WordPress-native management abilities. It does not ship an MCP server and does not require the MCP Adapter to activate. When a compatible MCP Adapter is installed, its public abilities can be exposed via authenticated MCP.

This is development software. Test on staging only.

== Installation ==

1. Install on WordPress 6.9 or later.
2. Activate the plugin.
3. Connect an authenticated Abilities API client or optionally install the official WordPress MCP Adapter.
4. Grant WordPress users only the capabilities they actually need.

== Frequently Asked Questions ==

= Does it work without MCP Adapter? =
Yes. WordPress registers these abilities through its native Abilities API.

= Does it allow anonymous management? =
No. Every ability requires a logged-in WordPress user with appropriate permissions.

== Changelog ==

= 0.1.0 =
* Initial development foundation for site, content, media, Elementor read, and same-site URL checks.
