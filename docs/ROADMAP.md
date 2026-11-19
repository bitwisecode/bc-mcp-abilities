# Porting roadmap

Source-of-truth: https://github.com/jobayerhossan/jobayer-wordpress-ai (Agent v0.7.0).

## Agent functionality inventoried
- Site information and runtime same-site URL checks
- Search/read/create/update/rollback content (including Rank Math)
- Media Library HTTPS upload with image MIME validation and size restrictions
- Elementor document read/create/update, element read/update/add/delete/move, JSON import/export/validation, CSS regeneration, and rollback
- Theme/plugin code read/search/patch with encrypted backup/rollback
- HMAC authentication, registry, and GitHub release update functions

## Initial implemented abilities
- site-info
- list-content
- get-content
- create-draft
- update-content (revision guard)
- list-media
- get-media
- elementor-document (read only)
- check-url
- update-media-alt (administrator opt-in)
- elementor-get-element (read-only)

## Governance implemented
- WordPress Settings API admin interface with per-write opt-in (default all writes off)
- Restrict diagnostic metadata to administrators
- WordPressCS/PHPCS (patched WPCS >=3.4.1) and official Plugin Check CI workflows

## Next priorities
1. Add Elementor element inspection, safe element updates through Elementor APIs, validation, revision check, backups, and rollback.
2. Add safe content rollback and Rank Math metadata operations.
3. Add media upload (HTTPS and SSRF validation) and alt text update.
4. Add diagnostics and file read/search; keep arbitrary PHP editing **out** of the public MCP ability set until an approval and rollback security design is reviewed.
5. Competitor comparison: Enable Abilities for MCP 2.17.1 — compare UX, coverage, permission exposure, OAuth approach, and extensibility. Do not copy its bundled source.

## Security and release gates
- WordPress 6.9+; native Abilities API (MCP Adapter optional)
- Ability-level permissions and per-resource capability checks
- No anonymous calls
- Never expose arbitrary filesystem/PHP execution via AI by default
- Write tools must reject stale revisions
- Staging integration tests and WordPress Plugin Check required before wp.org submission
