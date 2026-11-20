# BC MCP Abilities

Native, independent WordPress Abilities API plugin developed by **BitwiseCode**. WordPress 6.9+ and PHP 7.4+ required. The [official MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) is optional: it provides the MCP transport, while this plugin registers and executes abilities.

> **Developer preview — do not deploy to production or submit to WordPress.org until quality checks and staging acceptance tests pass.** The Agent-parity expansion is under review in draft PR #7; production release still requires integration testing.

## Registered abilities (35 on the Agent-parity development branch)

| Module | Abilities |
| --- | --- |
| WordPress | `site-info`, `list-content`, `get-content`, `create-draft`, `update-content`, `list-revisions`, `restore-revision`, `check-url`, `content-types`, `search-custom-content`, `get-custom-content`, `create-custom-draft`, `update-custom-content` |
| Media | `list-media`, `get-media`, `update-media-alt`, `upload-image` |
| Elementor | `elementor-document`, `elementor-get-element`, `elementor-update-text`, `elementor-undo`, `elementor-validate-json`, `elementor-export-json`, `elementor-create-document`, `elementor-update-settings`, `elementor-add-element`, `elementor-delete-element`, `elementor-move-element`, `elementor-regenerate-css` |
| Rank Math | `rank-math-read`, `rank-math-update` |
| WooCommerce | `wc-products`, `wc-product`, `wc-update-stock`, `wc-order-report` |

Full names are prefixed by `bc-mcp-abilities/`.

## Standalone WordPress MCP and ChatGPT OAuth — developer preview

The plugin now contains an **independent WordPress MCP endpoint** at `/wp-json/bc-mcp/v1/server` and a namespaced OAuth authorization service at `/bc-mcp`. Neither depends on the official MCP Adapter. Both are disabled by default.

1. On **staging**, open **Settings → BC MCP Abilities** and enable the built-in MCP endpoint and WordPress OAuth.
2. In ChatGPT Plugins on web, use **Add custom MCP server**, paste the URL, select automatic OAuth client registration (DCR), and approve access through WordPress.
3. First test discovery and a read-only ability.
4. Keep all BC write abilities disabled until separate testing.

The existing Claude/MCP Adapter connection remains unchanged and can coexist. This is experimental code until OAuth end-to-end acceptance testing and security review finish.

## Admin dashboard

The WordPress admin screen under **Settings → BC MCP Abilities** includes a responsive dashboard: setup overview, ChatGPT/OAuth connection controls, searchable write-ability permissions, bounded sanitized diagnostics, and Claude Desktop instructions. Existing option names and security checks are preserved. The public plugin deliberately excludes Facebook, LinkedIn, and YouTube API integrations and social publishing.

## Setup

1. Install on an isolated WordPress 6.9+ staging website.
2. Activate the plugin; **the MCP Adapter is not needed** for local WordPress Abilities API calls.
3. Go to **Settings → BC MCP Abilities** to selectively enable write abilities. All writes are disabled by default.
4. Optionally install and configure the official MCP Adapter, then connect an authenticated MCP client.
5. Never give untrusted AI clients an administrator account or broad write access.

Permissions are enforced by WordPress in addition to the per-ability write allowlist. The optional plugin integrations return a clear error when Elementor, WooCommerce, or Rank Math is absent.

## Important safeguards

- Scoped posts/pages and eligible public custom post types, with WordPress capability checks and revision-based conflict protection.
- ACF arbitrary-field edits, theme/plugin source edits, arbitrary PHP/JavaScript execution, and shell commands are intentionally not provided.
- Elementor offers guarded text edits, allowlisted widget settings, safe structure changes, JSON validation/export and CSS regeneration. Structural writes use one-step undo; arbitrary code widgets and unvalidated JSON import remain excluded.
- WooCommerce inventory writes require enabled stock management and a matching inventory revision token; orders expose no customer PII.
- Image uploads require administrator opt-in, public HTTPS sources, MIME validation and an 8 MB limit.
- Rank Math updates check a metadata revision token before changing allowlisted fields.
- Uninstall removes only BitwiseCode settings/recovery snapshots, not WordPress content.

## Development and testing

```bash
composer install
composer lint
# On a disposable WordPress staging instance with WP-CLI:
wp eval-file tests/smoke.php
```

GitHub Actions provides PHP lint, WordPressCS, WordPress Plugin Check, and a disposable WordPress Abilities smoke test. A passing smoke test is not a substitute for a full editor/Elementor/WooCommerce integration test.

See [Agent parity inventory](docs/AGENT-PARITY.md), [security notes](docs/SECURITY.md), [quality checklist](docs/QA-CHECKLIST.md), [roadmap](docs/ROADMAP.md), and [competitor analysis](docs/COMPETITOR-REVIEW.md).

License: GPL-2.0-or-later. Copyright 2026 BitwiseCode.
