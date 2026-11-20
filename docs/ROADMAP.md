# Existing Agent port and development roadmap

Reference: [Jobayer AI Agent](https://github.com/jobayerhossan/jobayer-wordpress-ai), v0.7.0. The competitor ZIP, Enable Abilities for MCP 2.17.1, was analyzed separately; its source has not been copied.

## Implemented on the WordPress Abilities API (48 in version 1.0.0)

- **Core:** site information, post/page search/read, create drafts, revision-guarded updates, list/restore revisions, bounded same-site HEAD checks, plus allowed public custom post types, draft creation and revision-guarded custom edits.
- **Media:** list/read attachments, update image alternative text, download public HTTPS images into Media Library with MIME and 8 MB size limits.
- **Elementor:** read documents and elements; safe widget text/settings edits; guarded node add/delete/move; export/validate JSON; draft document creation and CSS regeneration. Writes use an expected revision, a pre-save snapshot and one-level undo.
- **Rank Math and Yoast SEO:** read and edit strictly allowlisted title, description and focus keyword metadata with concurrency tokens.
- **WooCommerce:** search/read product inventory and variations, HPOS-compatible PII-free order reports, stock updates and **guarded regular/sale price editing for individual simple products and variations** with distinct pricing revision tokens.
- **Taxonomies (first v1 batch):** discover public eligible taxonomies and terms, read post term assignments, create allowed terms, and revision-guarded assignment edits with WordPress taxonomy capabilities.
- **Navigation (first v1 batch):** read classic navigation menus/items; create an empty menu; add a permitted post or term without allowing arbitrary external URLs.
- **Site diagnostics:** PageSpeed Insights scores, lab metrics and bounded findings for this site's public HTTPS paths.

All write abilities require a WordPress administrator and explicit per-ability opt-in. Third-party module availability and user capabilities are checked inside every operation.

## Existing-agent features intentionally NOT ported

- Arbitrary PHP/JS source-file patches, filesystem edits, shell commands and remote code execution. These are not appropriate for the public WordPress.org edition.
- HMAC site-agent credential exchange and the separate custom MCP server. The WordPress Abilities API plus optional official MCP Adapter replaces those connection mechanics.
- Arbitrary Elementor document overwrite/JSON import with user-controlled widgets, because executable HTML, custom code and layout integrity require stricter safety design; a read-only JSON validator is now included.
- Bulk role changes, plugin/theme installation, direct SQL and customer-data exports.

## Standalone MCP and OAuth

- Native WordPress REST MCP endpoint, independent of the Adapter
- OAuth 2.1-style authorization code with PKCE S256, client registration restricted to ChatGPT callbacks, refresh/revoke, OAuth discovery metadata and WordPress consent screen
- Disabled by default until enabled by an administrator; permissions remain scoped to the WordPress user.
- Verify the full authorized ChatGPT and Claude connection flows on a separate, clean test site before submission.

## Still to develop / harden before first public release

1. Finish WordPress Coding Standards and Plugin Check on latest commit and test on WordPress 6.9 and 7.1.
2. Add deep integration tests for Elementor save and undo, WooCommerce inventory and order read, Rank Math metadata and safe image upload (including SSRF and size tests).
3. **Implemented in security PR**: bounded first-party execution audit (100 entries / 7-day retention, admin-only CSV export and clear) logging only ability, user ID, type and outcome. Complete independent security verification before public release.
4. **Implemented in security PR**: administrator-configurable read-only ability hiding. Hidden BC read operations are not registered, so they cannot appear in MCP/REST discovery or be executed. Test both WordPress and official MCP Adapter on staging.
5. Expand role, content revision, inventory race, OAuth security, logged activity purge and third-party integration testing.
6. Finish independent security review, v1.0.0 packaging and clean-site acceptance. WordPress.org listing artwork may be added through SVN after approval.

## Public plugin scope

Facebook, LinkedIn and YouTube integrations, scheduling, profile/page posting, media publishing, and associated tokens are deliberately **out of scope** for the public BC MCP Abilities plugin. They must remain in separately deployed private tooling, not this repository's public plugin distribution.

## Admin experience

The settings interface is a standalone, responsive WordPress dashboard with connection controls, searchable write permission groups, diagnostics, and combined ChatGPT/Claude OAuth URL instructions on the Connections screen, plus clear overview/status indicators. The legacy Claude extension download is no longer shown in the public admin dashboard. Existing option keys, WordPress capability checks, and nonce-based save handling remain unchanged.

## Long-term integrations

ACF, SEOPress, custom post types, taxonomy management and advanced WooCommerce workflows can be layered in independent modules after the initial release.

See [QA checklist](QA-CHECKLIST.md), [security notes](SECURITY.md), and [competitor analysis](COMPETITOR-REVIEW.md).
