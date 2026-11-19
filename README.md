# BC MCP Abilities

Native, independent WordPress Abilities API plugin developed by **BitwiseCode**. WordPress 6.9+ and PHP 7.4+ required. The [official MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) is optional: it provides the MCP transport, while this plugin registers and executes abilities.

> **Developer preview — do not deploy to production or submit to WordPress.org until quality checks and staging acceptance tests pass.** Development takes place on `feat/agent-abilities-foundation` and PR #1.

## Registered abilities (22)

| Module | Abilities |
| --- | --- |
| WordPress | `site-info`, `list-content`, `get-content`, `create-draft`, `update-content`, `list-revisions`, `restore-revision`, `check-url` |
| Media | `list-media`, `get-media`, `update-media-alt`, `upload-image` |
| Elementor | `elementor-document`, `elementor-get-element`, `elementor-update-text`, `elementor-undo` |
| Rank Math | `rank-math-read`, `rank-math-update` |
| WooCommerce | `wc-products`, `wc-product`, `wc-update-stock`, `wc-order-report` |

Full names are prefixed by `bc-mcp-abilities/`.

## Setup

1. Install on an isolated WordPress 6.9+ staging website.
2. Activate the plugin; **the MCP Adapter is not needed** for local WordPress Abilities API calls.
3. Go to **Settings → BC MCP Abilities** to selectively enable write abilities. All writes are disabled by default.
4. Optionally install and configure the official MCP Adapter, then connect an authenticated MCP client.
5. Never give untrusted AI clients an administrator account or broad write access.

Permissions are enforced by WordPress in addition to the per-ability write allowlist. The optional plugin integrations return a clear error when Elementor, WooCommerce, or Rank Math is absent.

## Important safeguards

- Only scoped post/page operations (with WordPress revisions and optimistic concurrency checks).
- ACF arbitrary-field edits, theme/plugin source edits, arbitrary PHP/JavaScript execution, and shell commands are intentionally not provided.
- Elementor editing is limited to text fields on existing Heading, Text Editor and Button widgets; one-step undo stores a recovery snapshot before the change.
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

See [security notes](docs/SECURITY.md), [quality checklist](docs/QA-CHECKLIST.md), [roadmap](docs/ROADMAP.md), and [competitor analysis](docs/COMPETITOR-REVIEW.md).

License: GPL-2.0-or-later. Copyright 2026 BitwiseCode.
