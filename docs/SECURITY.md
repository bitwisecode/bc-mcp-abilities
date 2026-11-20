# Security policy and technical limitations

## Authentication and authorization

The native WordPress Abilities API is required; the official MCP Adapter is optional and is **not** an authentication provider. Use the MCP Adapter's supported authenticated transport or another approved WordPress integration.

Executing abilities requires a logged-in user. Public MCP initialization and tool-manifest preflight methods may return only static protocol metadata. Site diagnostics require `manage_options`. Write operations require `manage_options`, administrator-controlled per-ability opt-in and resource-specific WordPress capabilities.

Write permissions are **off by default**. Tools may remain discoverable to authorized users, but cannot execute a write without explicit approval in WordPress.

## Safeguards

- **Content:** drafts only for creation; no status changes via update; content revisions are checked before editing or restoring.
- **Media:** public HTTPS sources, WordPress-safe HTTP fetch, private host rejection, no redirects, response-size limit, MIME verification and temporary-file cleanup.
- **Elementor:** text updates for Heading/Text Editor/Button widgets, allowlisted widget settings, and adding, moving or deleting native containers and widgets (never HTML/code widgets or link settings). Every write requires a revision token, is re-checked immediately before saving, validates the tree (depth 30, 1,500 nodes, unique IDs) and creates a one-step recovery snapshot before saving via Elementor's document API. Undo requires the original token and an unchanged post-edit revision. The snapshot resides in protected WordPress post metadata and is deleted on successful undo or plugin uninstall.
- **WooCommerce:** operations use CRUD APIs; inventory updates require `manage_woocommerce`, stock management already enabled, object-specific permissions and a matching stock token. Order reports omit identifiable customer data. Stock consistency still depends on WooCommerce and concurrent stock changes; test before production.
- **Rank Math:** only title, description and focus keyword values are writable, and only against an unchanged metadata token.
- **Yoast SEO:** title and meta description updates invoke Yoast native Abilities when available, with advanced-metadata permissions and revision checks. Focus keywords are guarded separately.
- **WooCommerce prices:** regular/sale edits are limited to one product or variation with per-object permissions, pricing revision and valid price constraints.
- **Networking:** outgoing requests include only explicitly requested image imports, bounded site URL checks, and the optional Google PageSpeed Insights API when triggered by an administrator. The PageSpeed tested public URL is transmitted to Google and its result cached for one hour; see readme.txt.
- **OAuth:** PKCE S256 only, exact redirect matching against a chatgpt.com allowlist, hashed one-use codes claimed atomically, 1-hour access tokens and rotating 30-day refresh tokens. Every token belongs to a per-user grant; revoking the grant (administrator action, password or role change, turning the connection off, authorization-code reuse, or refresh-token reuse outside a 30-second retry window) invalidates all of its tokens. Registrations that never complete a token exchange expire after a day and unused clients after 30 days; at the 50-client limit the oldest unused registration is evicted, never a working connection. The consent page cannot be framed and shows the client name, return address, registration date and signed-in user.
- **Uninstall:** removes plugin-owned settings, registered clients, grants, stored codes and tokens, single-use locks, execution history, debug events, scheduled events and Elementor recovery metadata, on every site of a multisite network. Transients held only in a persistent object cache expire on their own. It never deletes ordinary posts, media or products.

## Important limitations

- Automated smoke tests do not establish production safety; test on a staging copy first.
- PHP_CodeSniffer, Plugin Check and WordPress 6.9 compatibility must all pass on the release commit.
- The built-in connection requires HTTPS and pretty permalinks. Behind a TLS-terminating proxy, WordPress must be configured to detect HTTPS.
- Disabling the built-in connection does not stop access through the core Abilities REST API or the official MCP Adapter; use the per-ability exposure and write controls to restrict those.
- The first implementation stores only one Elementor undo snapshot per post; editing again replaces the earlier snapshot.
- The Elementor tree validator is bounded, but integration tests with real sites and Elementor versions are still required.
- Rank Math updates are not transactional across concurrent editors; the pre-write revision token reduces stale updates.
- The product inventory revision token prevents obvious stale writes but cannot guarantee atomicity across multiple concurrent systems.
- Read-only content/media abilities require WordPress permissions and per-resource checks. Administrators can hide individual read abilities from registration/discovery using the built-in exposure controls.
- Execution audit logs retain only time, numeric user ID, safe ability identifier, read/write type and outcome, up to 100 entries for at most seven days. No requests, credentials, results or prompts are persisted.
- No arbitrary PHP/JavaScript code editing, plugin/theme source writing, shell execution, direct SQL, or plugin installation abilities are included.

Please report vulnerabilities privately to the BitwiseCode maintainer.
