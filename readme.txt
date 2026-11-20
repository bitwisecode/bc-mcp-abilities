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

BC MCP Abilities does not send website data to a central BitwiseCode service or use analytics trackers. Its MCP endpoint runs on the installed WordPress site. External requests occur only when an administrator chooses the corresponding feature or connects an external AI assistant. The services below are optional.

= Google PageSpeed Insights (Google) =

Purpose: Run an on-demand performance and SEO audit of a public page on this website. When an authorized user invokes the PageSpeed ability, the WordPress server sends the publicly accessible page URL to Google's PageSpeed Insights API (https://www.googleapis.com/pagespeedonline/v5/runPagespeed). Google can fetch and analyze that URL and return Lighthouse and field performance data. No WordPress account passwords or private post content are sent by this ability. Requests do not happen automatically; results are cached locally for one hour.

Provider: Google. API terms: https://developers.google.com/terms . Privacy policy: https://policies.google.com/privacy . API information: https://developers.google.com/speed/docs/insights/v5/get-started .

= Optional AI assistants: ChatGPT and Claude =

BC MCP Abilities does not require ChatGPT or Claude to be installed or connected. If a site administrator chooses to connect a supported third-party AI assistant, the assistant accesses this WordPress site's MCP endpoint using an authorized user account. The site sends responses to requested tools, which may include WordPress posts, pages, SEO information, product information or other information that the user is authorized to access. If editing permissions are explicitly enabled, the assistant may also send requested changes to WordPress. The external client may process the submitted prompts, tool inputs and returned information under that provider's policies. Connection requests and data transfers occur only when the external client is used; simply installing BC MCP Abilities does not send data to those providers.

ChatGPT is provided by OpenAI. Terms: https://openai.com/policies/terms-of-use/ . Privacy: https://openai.com/policies/privacy-policy/ .

Claude is provided by Anthropic. Terms and privacy documents: https://www.anthropic.com/legal . The optional Claude OAuth URL requires a compatible, separately installed MCP provider.

= Optional image import from user-selected websites =

When an authorized administrator explicitly enables and invokes the image-import ability, WordPress fetches a chosen publicly accessible HTTPS image URL from the external image host. This can reveal the requested URL and this server's public IP address to the remote host. The remote site's terms and privacy policy depend on the image source chosen by the administrator. No other images are imported automatically.

= Local data and security history =

The plugin stores connection configuration, registered OAuth clients, short-lived OAuth authorization/access data, administrator-selected ability permissions and at most 100 execution audit records. Audit entries include the timestamp, numeric WordPress user ID, action name, read/edit type and outcome, and expire after up to seven days. They do not contain prompts, request bodies, tokens, passwords or response data. Administrators can export or clear local audit history. Additional connection diagnostics are off unless enabled by an administrator, and retain sanitized events for no more than 24 hours.

Uninstall removes plugin-owned settings, registered OAuth clients, audit history and Elementor recovery data. Temporary OAuth credentials expire automatically; existing access credentials become invalid after plugin removal. Uninstall does not delete ordinary WordPress posts, pages, products or media.

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
