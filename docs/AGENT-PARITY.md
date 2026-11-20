# Jobayer MCP Agent → BC MCP Abilities parity

Audited 20 November 2026 directly against the connected `Jobayer_WordPress_AI` tool inventory (67 available tools) and the source in `jobayerhossan/jobayer-wordpress-ai`.

## Initial audit

| Domain | Existing Agent MCP tools | BC MCP Abilities coverage |
| --- | --- | --- |
| WordPress site/content/media/URL | Site lookup, content search/get/create/update/rollback, source code search/read/patch/rollback, media upload, URL check | Native site info, posts/pages + CPT content, media, revisions, URLs; **no arbitrary file edit** |
| Elementor | Read/edit/create documents; add, update, delete, move widgets; validate/import/export JSON; CSS regeneration; rollback | Read/edit text + undo; now guarded structural editing, empty draft creation, validation/export, per-document CSS; unrestricted document replacement/import deferred |
| WooCommerce | Not represented as dedicated tools in this connected Agent inventory | Product search/read, HPOS-compatible order reporting, inventory writes |
| Frontend & web performance | Browser inspector/audit/compare/verify, Chromium self-test, PageSpeed Insights | Separate phase: cannot safely run headless Chromium within an ordinary shared-hosting WordPress plugin |
| GA4/Search Console | Google API reports | Separate integration with site-admin credentials; not part of core WordPress abilities |
| Social media | Facebook Page, YouTube, LinkedIn posting/media/analytics | Optional future integrations; user credentials and platform reviews required |

## Added in first implementation stage (13 abilities)

### Custom WordPress content (5)

- `content-types`: allowed public UI-visible post types (no product/order/ACF/internal records)
- `search-custom-content`: per-resource permission-checked search for eligible custom post types
- `get-custom-content`: read content and revision hash
- `create-custom-draft`: create draft only; no publish or role changes
- `update-custom-content`: allowlisted text fields and optimistic revision checks; no status/type changes

### Safe Elementor extensions (8)

- `elementor-validate-json`: bounded structural validation; does **not** import or execute templates
- `elementor-export-json`: export a permitted Elementor document, max 1 MB
- `elementor-create-document`: initialize a draft WordPress page/post using Elementor's document API
- `elementor-update-settings`: merge only allowlisted text/layout fields on supported native widgets
- `elementor-add-element`: create supported native widgets/containers; no HTML/code widget injection
- `elementor-delete-element`: delete by ID, with explicit nested-child consent and undo
- `elementor-move-element`: safe movement with cycle checks
- `elementor-regenerate-css`: regenerate one Elementor document's styles through the Elementor API

These are **developer preview** functions. Elementor structural edits use the same revision hash and single-level undo as existing text edits. As with all BC write abilities, each is **disabled by default**, needs an administrator opt-in and obeys WordPress resource permissions.

## Not yet ported

- Arbitrary PHP/theme/plugin source edits, shell commands, privileged self-impersonation, direct SQL, role elevation: intentionally excluded from public WordPress.org edition
- Full Elementor JSON import, arbitrary document replacement, custom widget settings: need strict sanitization, versioned fixtures and compatibility tests
- Custom post type revision rollback after independent changes: requires protected snapshots or native revisions with conflict checks
- Frontend DOM audit/replay: separate browser diagnostics. PageSpeed: initial site-scoped read-only module implemented
- Third-party platform integrations (GSC, GA4, Facebook, YouTube, LinkedIn): optional future modules, with their own OAuth/permissions

## Acceptance gates

1. Pass PHP lint, PHPCS, WordPress Plugin Check and disposable WordPress smoke suite (now 35 registered abilities).
2. Manually test Elementor nested layout operations on a staging page and verify undo, settings fidelity, CSS regeneration and Elementor editor reload.
3. Test permission denials, stale revision rejection, duplicate IDs, invalid JSON, cycle prevention, and concurrent edits.
4. Keep this PR separate from ongoing ChatGPT OAuth connector troubleshooting.
5. Do not deploy these structural write abilities on a live production site without explicit administrator opt-in and staging acceptance.

## Site diagnostics, stage 2

- `pagespeed-audit`: administrator-only Google PageSpeed Insights for an HTTPS same-site path; mobile or desktop, allowlisted Lighthouse categories, 1-hour transient caching, response-size limit, and bounded findings. This ability does not run Chromium on Hostinger, accept arbitrary external URLs, or include GA4/Search Console credentials. Google API may rate-limit unauthenticated requests.
