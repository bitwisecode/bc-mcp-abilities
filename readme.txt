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

BC MCP Abilities registers 48 WordPress-native abilities in the pre-release development branch, covering content, media, WooCommerce, Rank Math and guarded Elementor operations. It includes a built-in MCP and OAuth connection that is disabled by default, and works without an external server. The official MCP Adapter remains optional for compatibility. All write abilities require an administrator opt-in and WordPress permission checks.

This is development software. Test on staging only. Administrators can hide individual read-only abilities from AI discovery and execution, and view a bounded seven-day activity log. The audit excludes prompts, tool inputs, response payloads, tokens and passwords.

== Privacy & External Services ==

This plugin stores optional OAuth client registration and token data, administrator-selected permissions, and at most 100 execution audit entries. Audit entries contain only the timestamp, numeric WordPress user ID, ability name, read/write type and outcome; they expire after up to seven days. Administrators can export or delete their local activity history in Settings.

The built-in MCP endpoint runs on your own WordPress website. If you choose to connect an external AI client, its handling of prompts and returned website data is governed by that client's own terms and privacy policy. No external AI account or API key is needed merely to install this plugin.

Google PageSpeed Insights is an optional read-only feature. When an authorized administrator runs this ability, the tested public page URL is sent from the WordPress server to Google's PageSpeed Insights API at https://www.googleapis.com/pagespeedonline/v5/runPagespeed . Google may retrieve that public page and return performance metrics. Responses are cached locally for one hour; cached requests do not contact Google. Google Privacy Policy: https://policies.google.com/privacy . PageSpeed API documentation: https://developers.google.com/speed/docs/insights/v5/get-started .

The optional image-import write ability fetches an explicitly provided public HTTPS image URL from your WordPress server; it is disabled by default. All write abilities require administrator opt-in and the acting user's WordPress permissions.

Uninstall removes plugin-owned OAuth state, permission configuration, activity logs and recovery metadata. It does not delete your WordPress content, products or media.

== Supported Integrations ==

- WordPress native Abilities API (6.9 or newer) and optional built-in OAuth MCP transport.
- Optional official MCP Adapter for compatible clients.
- Optional Elementor, WooCommerce, Rank Math and Yoast SEO functionality, requiring the corresponding plugin.
- For modern Yoast SEO versions, SEO titles and descriptions are updated using Yoast's own Abilities API so its indexable data can be refreshed consistently.

No Facebook, LinkedIn or YouTube social posting, scheduling or account API integration is included.

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
* Fix ChatGPT tool import: `bc-list-abilities` now publishes a valid JSON Schema (`properties: {}`); MCP protocol version negotiation; Authorization header fallback for Apache/LiteSpeed hosts; RFC 6750-compliant challenges; richer, privacy-safe connection diagnostics.
* Developer preview: 48 abilities including guarded Yoast SEO metadata editing and WooCommerce individual product/variation pricing, plus scoped taxonomy, menu, diagnostic and Elementor operations. No unrestricted source editing.
