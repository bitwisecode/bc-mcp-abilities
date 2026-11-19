# BC MCP Abilities Security Policy

The native WordPress Abilities API is the only core dependency. The MCP Adapter is optional.

## Current authorization
- Authenticated access is required for all abilities.
- Reads require `edit_posts` and per-post `edit_post` checks.
- Writes require `manage_options`, explicit administrator opt-in for the individual ability, and resource-level authorization.
- Creating posts/pages always creates a draft; updating content never accepts a status change.
- Existing post edits use an expected revision hash; stale revisions return a conflict.
- Site URL checks restrict inputs to root-relative same-site paths, disable redirects, and use `wp_safe_remote_head`.

## Known gaps before release
- Formal WordPress integration and security tests have not been run.
- The current read-only site metadata ability exposes plugin and PHP versions to editors; review a stronger capability requirement before release.
- Audit logging, update rollback, Elementor writes, and scoped media upload remain to be implemented.
- Read-only access is enabled for authorized editors by default. A future release should allow administrators to narrow read permissions.
- No arbitrary PHP execution, file-edit, shell, database, credential read, or remote plugin installation abilities will be exposed in the public version.

Do not install the development branch on production sites. Report security defects privately to the repository maintainer, not as public GitHub issues.
