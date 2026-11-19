# Security policy and technical limitations

## Authentication and authorization

The native WordPress Abilities API is required; the official MCP Adapter is optional and is **not** an authentication provider. Use the MCP Adapter's supported authenticated transport or another approved WordPress integration.

All abilities require a logged-in user. Site diagnostics require `manage_options`. All write operations require `manage_options` and a separate, administrator-controlled per-ability toggle under **Settings → BC MCP Abilities**. Resource operations additionally verify `edit_post`, `upload_files`, WooCommerce-specific capabilities, etc.

Write permissions are **off by default**. Tools may remain discoverable to authorized users, but cannot execute a write without explicit approval in WordPress.

## Safeguards

- **Content:** drafts only for creation; no status changes via update; content revisions are checked before editing or restoring.
- **Media:** public HTTPS sources, WordPress-safe HTTP fetch, private host rejection, no redirects, response-size limit, MIME verification and temporary-file cleanup.
- **Elementor:** only Heading/Text Editor/Button text fields on an existing document; update requires revision token and creates a one-step recovery snapshot before saving via Elementor's document API. Undo requires the original token and an unchanged post-edit revision. The snapshot resides in protected WordPress post metadata and is deleted on successful undo or plugin uninstall.
- **WooCommerce:** operations use CRUD APIs; inventory updates require `manage_woocommerce`, stock management already enabled, object-specific permissions and a matching stock token. Order reports omit identifiable customer data. Stock consistency still depends on WooCommerce and concurrent stock changes; test before production.
- **Rank Math:** only title, description and focus keyword values are writable, and only against an unchanged metadata token.
- **Networking:** no external HTTP requests except explicitly requested image import or a bounded same-site HEAD check.
- **Uninstall:** removes only the ability opt-in option and the plugin's Elementor recovery snapshot metadata.

## Important limitations

- This is a **developer preview**: automated smoke tests do not establish production safety.
- PHP_CodeSniffer, Plugin Check and WordPress 6.9 compatibility must all pass on the release commit.
- The first implementation stores only one Elementor undo snapshot per post; editing again replaces the earlier snapshot.
- The Elementor tree validator is bounded, but integration tests with real sites and Elementor versions are still required.
- Rank Math updates are not transactional across concurrent editors; the pre-write revision token reduces stale updates.
- The product inventory revision token prevents obvious stale writes but cannot guarantee atomicity across multiple concurrent systems.
- Read-only content/media abilities require `edit_posts` and per-resource checks. Read policies will be configurable in a later version.
- No arbitrary PHP/JavaScript code editing, plugin/theme source writing, shell execution, direct SQL, or plugin installation abilities are included.

Please report vulnerabilities privately to the BitwiseCode maintainer.

## Optional ChatGPT OAuth CIMD integration

The ChatGPT client option is disabled by default. When explicitly enabled by a site administrator, it adds exactly one trusted `chatgpt.com` CIMD client ID to the supported `wp-media/mcp-oauth` publisher filter. It does not accept arbitrary client ID hosts, implement token issuance, create an OAuth endpoint, or bypass the WordPress ability-level permissions. The OAuth provider must verify the client metadata and redirect URI, enforce PKCE, validate tokens, and link authorization to the correct WordPress user. Do not enable on an untested production site.
