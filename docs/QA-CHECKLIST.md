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
- [ ] No PHP warnings with WP_DEBUG on
- [ ] Proper uninstall retains content and removes only plugin-owned configuration with user consent

## WordPress.org release
- [ ] Update stable tag to release version, matching plugin header
- [ ] Complete readme.txt and changelog
- [ ] Copyright and license review
- [ ] Plugin slug verified available
- [ ] Screenshots and plugin icons added
- [ ] Build a clean installable ZIP and test on fresh staging
- [ ] No bundled developer dependencies in release ZIP
