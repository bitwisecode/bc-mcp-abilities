# Existing Agent port and development roadmap

Reference: [Jobayer AI Agent](https://github.com/jobayerhossan/jobayer-wordpress-ai), v0.7.0. The competitor ZIP, Enable Abilities for MCP 2.17.1, was analyzed separately; its source has not been copied.

## Implemented on the WordPress Abilities API (22 abilities)

- **Core:** site information, post/page search/read, create drafts, revision-guarded updates, list and restore existing WordPress revisions, bounded same-site HEAD checks.
- **Media:** list/read attachments, update image alternative text, download public HTTPS images into Media Library with MIME and 8 MB size limits.
- **Elementor:** read documents and elements; edit Heading, Text Editor and Button text using an expected document revision, a stored pre-save snapshot, and one-level undo.
- **Rank Math:** read and edit allowlisted SEO meta values with metadata concurrency tokens.
- **WooCommerce:** search/read product inventory and variations, HPOS-compatible PII-free order reports, and stock updates for individually managed products and variations with concurrency tokens.

All write abilities require a WordPress administrator and explicit per-ability opt-in. Third-party module availability and user capabilities are checked inside every operation.

## Existing-agent features intentionally NOT ported

- Arbitrary PHP/JS source-file patches, filesystem edits, shell commands and remote code execution. These are not appropriate for the public WordPress.org edition.
- HMAC site-agent credential exchange and the separate custom MCP server. The WordPress Abilities API plus optional official MCP Adapter replaces those connection mechanics.
- Raw Elementor document overwrite/JSON import with user-controlled widgets, because executable HTML, custom code and layout integrity require stricter safety design.
- Bulk role changes, plugin/theme installation, direct SQL and customer-data exports.

## Still to develop / harden before first public release

1. Finish WordPress Coding Standards and Plugin Check on latest commit and test on WordPress 6.9 and 7.1.
2. Add deep integration tests for Elementor save and undo, WooCommerce inventory and order read, Rank Math metadata and safe image upload (including SSRF and size tests).
3. Add bounded admin-only activity audit logs that avoid storing credentials, customer PII, or large payloads.
4. Add admin UI for read-only access policies and optional third-party module toggles.
5. Add content revision and stock-update failure-case tests, race/concurrency handling and user documentation.
6. Add final WP.org screenshots/icons, changelog and stable release package once all gates pass.

## Long-term integrations

ACF, Yoast/SEOPress, custom post types, taxonomy management and advanced WooCommerce workflows can be layered in independent modules after the initial release.

See [QA checklist](QA-CHECKLIST.md), [security notes](SECURITY.md), and [competitor analysis](COMPETITOR-REVIEW.md).
