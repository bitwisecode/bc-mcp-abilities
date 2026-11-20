# WordPress.org review lessons — BC MCP Abilities

This checklist was informed by the 12–15 May 2026 WordPress.org review cycle of the separately published BC CourierHub BD plugin. It is for internal release QA and contains no personal email content or review tokens. **Approval of a different plugin does not constitute approval of BC MCP Abilities.**

## Lessons from the earlier plugin review

1. **Unique prefixes everywhere**: WordPress.org required unique identifiers of at least four characters for PHP functions, classes, options and globals, as well as JavaScript localized object names. A second manual review flagged an unprefixed localized JavaScript object after the initial fixes.
2. **Third-party disclosures**: The readme must identify each external service, what data it receives, why and when, plus working terms and privacy references.
3. **Setting sanitization and credentials**: Use an appropriate `sanitize_callback` in every `register_setting()`; preserve exact opaque secrets rather than altering them with generic text sanitization.
4. **Uninstall and data scope**: Remove plugin-owned configuration and scheduled events without deleting ordinary site content.
5. **Directory metadata**: `Contributors` must contain the actual WordPress.org username (`bitwisecode`, not the brand abbreviation `bc`).
6. **Release tests and communication**: Test activation and actions on a clean WordPress installation with `WP_DEBUG` on; fix the full class of issues, not just flagged lines. Reply briefly after submitting a corrected complete ZIP, not a lengthy change list.
7. **Potential issues to review**: User tracking without consent, intrusive admin notices, and external-network requests. These were guideline warnings, not all separately proven defects in BC CourierHub BD.

## Current BC MCP Abilities findings

| Previous review concern | BC MCP Abilities state |
| --- | --- |
| PHP and WP-facing prefix | Uses `BC_MCP_Abilities_*` classes and `bc_mcp_*` options, hooks and route identifiers. Class constants such as `OPTION` are scoped within uniquely named classes and do not create global names. |
| JavaScript globals | Dashboard script is encapsulated in an IIFE and exposes no localized global object; DOM identifiers and session key have plugin-specific prefixes. |
| WordPress.org contributor | Readme uses `Contributors: bitwisecode` (verified owner account name). |
| Settings sanitation | Connection flags use a strict `0`/`1` sanitizer. Write and read permission lists sanitize and allowlist submitted slugs. Public OAuth tokens are generated and validated as opaque values rather than passed through a generic text-field setting sanitizer. |
| Optional external services | Readme identifies PageSpeed, ChatGPT, Claude, and user-chosen image hosts; includes data flow, trigger conditions and provider policies. Confirm every external call remains within the documented scope. |
| Data retention | Plugin audit is limited to 100 events and seven days. Debug events are optional, bounded and stored for 24 hours. OAuth clients are stored locally; issued short-lived tokens use WordPress transients. |
| Token invalidation | Bearer tokens now require their issuing OAuth client to remain registered, preventing old tokens from being accepted after uninstall/reinstall. |
| Uninstall | Deletes plugin-owned settings, OAuth client registry, audit history and Elementor recovery metadata; unschedules audit and OAuth lock-cleanup hooks. Transient data may remain in an external object cache until expiry but cannot authorize after uninstall. |
| WP_DEBUG | Disposable WP smoke workflow explicitly enables `WP_DEBUG` before activation and running tests. |
| Admin notices | Premium dashboard loads only on `Settings → BC MCP Abilities`, uses the native WordPress settings notice and scopes CSS/JS to the plugin screen. |

## Outstanding publication steps

- Complete all CI checks on the exact release commit, including PHP/WPCS, WordPress Plugin Check, `WP_DEBUG` smoke, Elementor, WooCommerce/Yoast and external OAuth metadata.
- Test the installable ZIP on an **independent clean WordPress site**, including plugin activation, a real authenticated MCP session, permission toggles, non-admin users, deactivation and uninstall.
- Keep the plugin header version, PHP version constant, changelog and `Stable tag` **identical** when preparing the first numerical release. Current `0.1.0-dev` is intentionally not a WordPress.org stable release tag.
- Confirm the requested plugin slug is available. Approval for `bc-courierhub-bd` does not reserve `bc-mcp-abilities`.
- Review optional OAuth and third-party integration disclosures for the final release and recheck linked provider policies.
- Prepare WordPress.org icons, banner, screenshots, and proof that the shipped ZIP does not contain dev/test files.
- Submit for WordPress.org review only after the pre-release checklist is signed off; do not automatically publish or mail reviewers.
