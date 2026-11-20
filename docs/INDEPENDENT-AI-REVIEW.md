# Independent AI security and WordPress.org submission audit

You are an independent senior WordPress plugin security reviewer, PHP developer, OAuth/MCP protocol engineer, and WordPress.org plugin review specialist.

**Target source:** https://github.com/bitwisecode/bc-mcp-abilities (main branch, version 1.0.0 submission candidate).

## Audit instructions

1. Read the **actual current source** from GitHub before reaching conclusions. Do not rely on README claims, previous AI reports, or passing CI checks as proof of correctness.
2. **Read-only audit initially.** Do not change website settings, call production write tools, publish a release, create tags, submit to WordPress.org, expose credentials, or run destructive tests. Suggest minimal code patches or a review PR only after reporting findings.
3. Focus on vulnerabilities reachable by anonymous visitors, subscriber/editor users, OAuth clients, or a malicious/compromised AI agent. Consider WordPress multisite and shared hosting.
4. Review the entire installable release payload, not just tracked PHP files.

## Areas to review

- OAuth 2.1 style DCR and PKCE flow, consent, redirect URI handling, authorization-code replay resistance, token rotation/revocation, client binding, expiration, scope/resource checks, rate limits, CSRF, authentication bypass and refresh-token reuse.
- Streamable HTTP MCP JSON-RPC initialization, tool discovery, tool input/output schemas, unauthenticated endpoints, bearer-token errors, protocol versions, hidden read-only abilities and command dispatch.
- WordPress Abilities API registration, capability/role/object access checks, per-action write opt-in, nonce protection, options sanitization and privilege escalation.
- IDOR, SSRF, file uploads, content/media editing, SQL injection, escaping, stored/reflected XSS, arbitrary code execution and unsafe Elementor JSON/widget configurations.
- WooCommerce simple product and variation inventory/pricing (stale revisions, multiwriter race, effective prices, PII leaks), order reporting, Rank Math/Yoast field updates and taxonomies/menus.
- Activity history data minimization, OAuth diagnostics, temporary secrets and transients, privacy disclosures, external API calls, proper activation/deactivation and uninstall cleanup.
- WordPress.org plugin directory rules: prefix uniqueness across PHP and JavaScript, `register_setting` validation, WordPress coding standards, licensing, GPL dependencies, translation domain, readme metadata, accurate `Tested up to`, no unnecessary bundled development files.
- PHP 7.4–8.4 compatibility, WordPress 6.9+, missing optional plugins, multisite, clean activation with `WP_DEBUG`, and package size/structure.

## Release requirements

- Version in plugin header, PHP constant, readme Stable tag and changelog must be **1.0.0**.
- The plugin must contain no integrations for Facebook, LinkedIn or YouTube.
- WordPress.org screenshots, icons, and banners can be prepared **after approval**; do not block the initial submission because of missing directory artwork.
- Do not replace working OAuth functionality with speculative redesigns; preserve ChatGPT, Claude and WordPress capabilities.
- The owner will separately perform manual installation, connection and abilities tests on a fresh WordPress site. Do not pretend those tests have occurred.

## Deliverables

1. A prioritized **P0 / P1 / P2 / informational** findings table, with exact file paths and line numbers, affected trust boundary, and a reproducible non-destructive verification method.
2. Distinguish **confirmed vulnerabilities**, potential risks, and false positives. Do not report solely stylistic preferences as security bugs.
3. Suggested minimal fixes and targeted automated tests for each P0/P1; identify changes that risk breaking existing clients.
4. WordPress.org submission readiness verdict with evidence for each requirement, explicitly listing anything not verified.
5. A final concise **go / no-go / go-after-fixes** recommendation.

No automatic merge or publication. Report your findings for review first.
