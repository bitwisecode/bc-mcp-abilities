# BC MCP Abilities

Permission-checked WordPress Abilities for AI assistants, by **BitwiseCode**. Requires WordPress 6.9+ and PHP 7.4+.

The plugin registers 54 scoped abilities through the native WordPress Abilities API. Connect ChatGPT or Claude through the plugin's built-in OAuth-protected MCP endpoint, or use any compatible MCP provider such as the optional official [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/).

## Abilities

| Module | Abilities |
| --- | --- |
| WordPress | `site-info`, `list-content`, `get-content`, `create-draft`, `update-content`, `list-revisions`, `restore-revision`, `check-url`, `content-types`, `search-custom-content`, `get-custom-content`, `create-custom-draft`, `update-custom-content` |
| Media | `list-media`, `get-media`, `update-media-alt`, `upload-image` |
| Elementor | `elementor-document`, `elementor-get-element`, `elementor-update-text`, `elementor-undo`, `elementor-validate-json`, `elementor-export-json`, `elementor-create-document`, `elementor-update-settings`, `elementor-add-element`, `elementor-delete-element`, `elementor-move-element`, `elementor-regenerate-css` |
| SEO | `rank-math-read`, `rank-math-update`, `rankmath-list-redirects`, `rankmath-check-redirect`, `rankmath-create-redirect`, `rankmath-update-redirect`, `rankmath-delete-redirect`, `rankmath-bulk-import-redirects`, `yoast-seo-read`, `yoast-seo-update` |
| WooCommerce | `wc-products`, `wc-product`, `wc-update-stock`, `wc-update-prices`, `wc-order-report` |
| Taxonomies | `list-taxonomies`, `list-taxonomy-terms`, `get-post-terms`, `create-taxonomy-term`, `assign-post-terms` |
| Menus | `list-menus`, `get-menu`, `create-menu`, `add-menu-item` |
| Diagnostics | `pagespeed-audit` |

Full names are prefixed with `bc-mcp-abilities/`. Elementor, WooCommerce, Rank Math and Yoast SEO abilities need the corresponding plugin.

## Rank Math 301 redirects

When Rank Math's Redirections module is active, six abilities can inspect and manage its existing redirect records:

- `rankmath-list-redirects`: paginated inventory including IDs and revision tokens.
- `rankmath-check-redirect`: find exact-source duplicates before adding anything.
- `rankmath-create-redirect`: add a single guarded, exact-match 301.
- `rankmath-update-redirect`: change the destination only with its current revision token.
- `rankmath-delete-redirect`: permanently remove an ID only with its current revision token.
- `rankmath-bulk-import-redirects`: preview up to 50 mappings, detect duplicates/conflicts, then import only after supplying the current inventory revision and `apply: true`.

Write abilities are administrator-only and disabled by default under **Settings → BC MCP Abilities**. Redirect targets must be local to the WordPress site, either a relative path or a full same-site URL; homepage targets are allowed. These abilities use Rank Math's own redirection model, rather than writing directly to its database. Importing redirect rules does not remove existing theme-level PHP redirects, which must be audited separately.

## Permissions

- Every ability requires a signed-in WordPress user and checks that user's capabilities for each item.
- All editing abilities are off by default. An administrator enables each one individually, and only administrators can run them.
- Read-only abilities can be hidden individually, which removes them from every connection method.
- Edits use revision tokens, so changes made in the meantime are never silently overwritten.

## Connecting ChatGPT

1. Open **Settings → BC MCP Abilities → Connections**.
2. Turn on **Enable MCP connection** and **Secure WordPress sign-in**. HTTPS and pretty permalinks are required.
3. In ChatGPT, add a custom MCP connection with the MCP server URL shown, choose automatic OAuth registration, then sign in to WordPress and approve access.

For Claude, add a custom remote connector in Claude and enter the **same built-in URL** shown for ChatGPT. The plugin supports Claude's exact published OAuth callback, without requiring MCP Adapter.

Connected assistants are listed on the same screen, where they can be revoked individually or all at once. Changing a user's password or role disconnects that user.

## Development

```bash
composer install
composer lint
wp eval-file tests/smoke.php   # on a disposable WordPress site
```

GitHub Actions runs PHP lint (7.4–8.4), WordPress Coding Standards, Plugin Check, and integration tests with Elementor, WooCommerce and Yoast SEO.

See [SECURITY.md](SECURITY.md) for security details and how to report a vulnerability.

License: GPL-2.0-or-later. Copyright 2026 BitwiseCode.
