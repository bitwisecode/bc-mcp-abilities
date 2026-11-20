# WordPress.org pre-release gates

Release remains blocked until all gates pass.

## Automated
- [x] PHP syntax checks across supported PHP versions (earlier branch commit; current head must pass again)
- [ ] WordPress Coding Standards (PHPCS) without errors
- [ ] WordPress Plugin Check without blocking findings
- [ ] Tests against WordPress 6.9 and latest stable
- [ ] PHP 7.4, 8.0, 8.2, 8.4 compatibility

## Security and access tests
- [x] Anonymous execution denial covered by disposable WordPress smoke tests (current branch).
- [x] Subscribers denied read and write access in disposable WordPress smoke tests (current branch).
- [ ] Editors cannot enable administrative write permissions
- [ ] Write abilities disabled by default and must fail before activation
- [ ] Admin activation enables only the specifically selected write ability
- [ ] WordPress current_user_can checks each target object
- [x] New release-hardening module introduces no arbitrary code execution or filesystem writes.
- [ ] Independently audit existing source-editing and upload callbacks
- [ ] Taxonomy assignment requires editing the specific post and the taxonomy's assign_terms capability
- [ ] Term creation requires manage_terms and edit_terms on that specific taxonomy
- [ ] Term assignment rejects stale revision tokens, duplicate IDs and terms from another taxonomy
- [ ] Menu item creation rejects inaccessible posts, unpublished content without proper rights, invalid parent and untrusted external URLs
- [x] Read-only ability exposure denylist implemented with admin controls and registry-level hiding; verify through fresh-request CI.
- [ ] Validate on staging with official MCP Adapter discovery after disabling and re-enabling an ability
- [x] Activity logger stores only user ID, first-party ability slug, read/write, result and timestamp; admin CSV/export/clear controls added.
- [ ] Verify seven-day cron pruning and role-restricted export/clear on staging.
- [ ] Revision conflict rejects updates and restores
- [ ] Revision restore accepts revisions belonging only to the requested post
- [ ] Element IDs are validated, and missing Elementor is handled gracefully
- [ ] Media operations respect edit_post capabilities

## Integration and compatibility
- [ ] Abilities work when MCP Adapter is deactivated
- [ ] MCP Adapter discovers only opted-in abilities
- [ ] Application Password authentication honors connected user's role
- [ ] Elementor installed/disabled flows tested
- [ ] No PHP warnings with WP_DEBUG on (CI now enables WP_DEBUG for the complete disposable smoke suite; review actual logs and any notices)
- [x] Run real Yoast SEO + WooCommerce integration workflow with pricing/metadata round-trip and stale revision checks (previous CI; rerun against final commit).
- [ ] Verify Yoast native post SEO rendered/indexable data reflects updated titles and descriptions in CI and on deployed staging site.
- [ ] Proper uninstall retains content and removes only plugin-owned configuration with user consent

## WordPress.org release
- [ ] Update stable tag to release version, matching plugin header
- [ ] Complete readme.txt and changelog
- [ ] Copyright and license review
- [ ] Plugin slug verified available
- [ ] Screenshots and plugin icons added
- [ ] Build a clean installable ZIP and test on fresh staging
- [ ] No bundled developer dependencies in release ZIP

## Current publication blockers (21 November 2026)

- [x] WPToolix WordPress agent confirmed BC MCP Abilities active after the 21 November 2026 upload.
- [x] External GitHub OAuth metadata/MCP initialization/token validation probe passed on 21 November 2026. Re-run against the final release ZIP after deployment.
- [ ] Confirm live ChatGPT and Claude client connections after deployment, including permission changes and read-only discovery.
- [ ] Replace both development version 0.1.0-dev and readme Stable tag with the identical numerical final version on the release candidate. WordPress.org's Stable tag requires numbers and periods.
- [ ] Run WordPress.org readme validator and fresh WordPress staging acceptance, including admin mobile layout and multisite/security review.
- [x] Source includes a GPLv2-or-later license notice.
- [x] Readme documents external Google PageSpeed service, privacy, audit retention and optional client integrations.
- [ ] Add plugin icon, banner and screenshots for a polished WordPress.org listing.

## Specific reviewer lessons from BC CourierHub BD (May 2026)

See `docs/WORDPRESS-ORG-REVIEW-LESSONS.md` for the full historical cross-check.
- [x] `Contributors: bitwisecode` matches the WordPress.org owner account.
- [x] All plugin class names and public option identifiers use a distinctive BC MCP prefix. Dashboard JS runs inside an IIFE rather than declaring an unprefixed global.
- [x] The readme now explains optional Google PageSpeed, ChatGPT, Claude, and user-selected image-host requests: purpose, outbound data, when requests occur and provider policy links.
- [x] All WordPress Settings API options have a dedicated sanitization/allowlist callback; no opaque OAuth secrets are treated as ordinary text-field settings.
- [x] OAuth bearer token validation now checks whether its issuing client still exists; uninstall unschedules plugin-owned OAuth cleanup events.
- [ ] Verify live token reconnection after the next deployment of this security fix, then test fresh installation/deactivation/uninstall.
- [ ] Check that admin notices remain limited to the BC settings screen, and that final reviewer instructions are concise and fully grounded.
- [ ] Do not submit or tag until the numerical stable version matches in plugin header, constant, readme and release ZIP.
