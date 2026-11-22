=== BC MCP Abilities ===
Contributors: bitwisecode
Tags: mcp, abilities, ai, automation
Requires at least: 6.9
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Permission-checked WordPress Abilities for AI agents, with an optional built-in MCP endpoint and OAuth. Works with the official MCP Adapter.

== Description ==

BC MCP Abilities lets AI assistants work with your WordPress site through the native WordPress Abilities API. It registers 48 scoped actions for reading and managing posts, pages, custom content, media, categories and tags, navigation menus, Elementor pages, WooCommerce products and SEO fields.

AI assistants reach these actions through MCP (Model Context Protocol). Connect ChatGPT or Claude through the plugin's built-in MCP endpoint and WordPress sign-in, or use a compatible MCP provider such as the official MCP Adapter. The abilities also work through the WordPress Abilities API without any AI assistant connected.

= Permissions you control =

* All editing actions are off by default. An administrator enables each one individually.
* Every action follows the connected WordPress user's own permissions, item by item.
* Read-only actions can be hidden individually.
* Edits check a revision token, so changes made by someone else in the meantime are never silently overwritten.
* Elementor edits keep a one-step undo.

= Connection management =

* Users approve each AI assistant on a WordPress sign-in screen.
* Administrators can see every connected assistant and revoke one or all of them.
* Changing a user's password or role disconnects that user's assistants.
* An activity history shows which actions ran, by which user and with what result, for up to seven days.

== Privacy & External Services ==

BC MCP Abilities does not send website data to a central BitwiseCode service or use analytics trackers. Its MCP endpoint runs on the installed WordPress site. External requests occur only when an administrator chooses the corresponding feature or connects an external AI assistant. The services below are optional.

= Google PageSpeed Insights (Google) =

Purpose: Run an on-demand performance and SEO audit of a public page on this website. When an authorized user invokes the PageSpeed ability, the WordPress server sends the publicly accessible page URL to Google's PageSpeed Insights API (https://www.googleapis.com/pagespeedonline/v5/runPagespeed). Google can fetch and analyze that URL and return Lighthouse and field performance data. No WordPress account passwords or private post content are sent by this ability. Requests do not happen automatically; results are cached locally for one hour.

Provider: Google. API terms: https://developers.google.com/terms . Privacy policy: https://policies.google.com/privacy . API information: https://developers.google.com/speed/docs/insights/v5/get-started .

= Optional AI assistants: ChatGPT and Claude =

BC MCP Abilities does not require ChatGPT or Claude to be installed or connected. If a site administrator chooses to connect a supported third-party AI assistant, the assistant accesses this WordPress site's MCP endpoint using an authorized user account. The site sends responses to requested tools, which may include WordPress posts, pages, SEO information, product information or other information that the user is authorized to access. If editing permissions are explicitly enabled, the assistant may also send requested changes to WordPress. The external client may process the submitted prompts, tool inputs and returned information under that provider's policies. Connection requests and data transfers occur only when the external client is used; simply installing BC MCP Abilities does not send data to those providers.

ChatGPT is provided by OpenAI. Terms: https://openai.com/policies/terms-of-use/ . Privacy: https://openai.com/policies/privacy-policy/ .

Claude is provided by Anthropic. Consumer terms: https://www.anthropic.com/legal/consumer-terms . Privacy policy: https://www.anthropic.com/legal/privacy . Claude custom remote connectors and ChatGPT can use the same BC MCP Abilities OAuth endpoint when enabled, without installing MCP Adapter.

= Optional image import from user-selected websites =

When an authorized administrator explicitly enables and invokes the image-import ability, WordPress fetches a chosen publicly accessible HTTPS image URL from the external image host. This can reveal the requested URL and this server's public IP address to the remote host. The remote site's terms and privacy policy depend on the image source chosen by the administrator. No other images are imported automatically.

= Local data and security history =

The plugin stores connection configuration, registered OAuth clients, short-lived OAuth authorization/access data, a list of each user's approved assistant connections, administrator-selected ability permissions and at most 100 execution audit records. Audit entries include the timestamp, numeric WordPress user ID, action name, read/edit type and outcome, and expire after up to seven days. They do not contain prompts, request bodies, tokens, passwords or response data. Administrators can export or clear local audit history. Additional connection diagnostics are off unless enabled by an administrator, and retain sanitized events for no more than 24 hours.

Administrators can see and revoke connected assistants under Settings > BC MCP Abilities > Connections. Changing a user's password or role, or turning the MCP connection or WordPress sign-in off, disconnects the affected assistants. Unused client registrations are removed automatically.

Uninstall removes plugin-owned settings, registered OAuth clients, connection approvals, stored OAuth codes and tokens, audit history and Elementor recovery data, on every site of a multisite network. On sites with a persistent object cache, cached temporary credentials expire on their own; all existing credentials become invalid after plugin removal. Uninstall does not delete ordinary WordPress posts, pages, products or media.

== Supported Integrations ==

- WordPress native Abilities API (6.9 or newer) and optional built-in OAuth MCP transport.
- Optional official MCP Adapter for compatible clients.
- Optional Elementor, WooCommerce, Rank Math and Yoast SEO functionality, requiring the corresponding plugin.
- For modern Yoast SEO versions, SEO titles and descriptions are updated using Yoast's own Abilities API so its indexable data can be refreshed consistently.

== Installation ==

1. Install on WordPress 6.9 or later.
2. Activate the plugin.
3. Use the native WordPress Abilities API, optionally enable the built-in MCP/OAuth connection in Settings, or install the official MCP Adapter. The built-in connection requires HTTPS and pretty permalinks.
4. Grant WordPress users only the capabilities they actually need.

== Frequently Asked Questions ==

= Does it work without the MCP Adapter? =
Yes. WordPress registers these abilities through its native Abilities API. The plugin can also host its own OAuth-protected MCP endpoint when a site administrator enables it.

= Does it allow anonymous management? =
No. Every ability requires a signed-in WordPress user with the appropriate permissions.

= Can an AI assistant change my website? =
Only if an administrator enables the specific editing action, and only for content the connected user is already allowed to edit. Editing actions can only be run by administrators.

= What does the built-in connection require? =
HTTPS and pretty permalinks (any setting other than Plain under Settings > Permalinks). If your site is behind a proxy or CDN, WordPress must detect HTTPS correctly.

= How do I disconnect an AI assistant? =
Go to Settings > BC MCP Abilities > Connections and revoke the connection, or choose Disconnect all assistants. Turning off the MCP connection or WordPress sign-in also disconnects everyone.

= Does turning off the connection block every route? =
It turns off the built-in ChatGPT and Claude connections. Actions remain available through the WordPress Abilities REST API and the official MCP Adapter to users who authenticate another way, such as with an Application Password. To restrict every route, hide read-only actions or leave editing actions disabled.

== Changelog ==

= 1.0.0 =
* Initial release.
* ChatGPT and Claude can use the same built-in OAuth-protected MCP endpoint without MCP Adapter.
