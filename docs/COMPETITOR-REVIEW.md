# Competitor review: Enable Abilities for MCP 2.17.1

Reviewed user-provided WordPress.org ZIP (not imported or copied).

## Verified competitor features
- 114 abilities in 21 categories according to bundled readme.
- An ability enable/disable interface.
- OAuth 2.1 custom connector flow for Claude.ai and opt-in ChatGPT dynamic registration (beta).
- Application Password / bearer-token client configuration.
- WooCommerce products, orders, customers; Elementor; Rank Math, Yoast, SEOPress; CPTs; menus; multilingual; FSE; LMS; events.
- Activity logs and integration options.

## Product differentiation goals
- **First**: port capabilities from the existing Jobayer AI Agent into native WordPress abilities: full Elementor document/widget operations, revision conflict checks, rollback, content workflow, media and runtime diagnostics.
- Keep native WordPress Abilities API as the sole core dependency; MCP Adapter optional.
- Stronger safeguards: per-ability explicit opt-in for writes, revisions, immutable audit records, destructive-operation confirmation, safe targeted Elementor edit and rollback.
- Smaller, modular PHP classes and test suite instead of a monolithic file.
- Admin onboarding with clear differentiation between WordPress Abilities and MCP connectivity.
- Do not ship direct source-code edit or arbitrary PHP execution abilities by default.
- Compatibility with WordPress.org security and plugin review.

## Limitations
These are design targets, not claims of current implemented functionality. Competitor's claims should be tested in staging; code review alone does not prove runtime behavior.

## Refresh: 21 November 2026 (WordPress.org listing 2.17.1)

Public reference: https://wordpress.org/plugins/enable-abilities-for-mcp/

The competitor currently advertises 114 abilities in 21 categories. That is the competitor's published count, not a runtime verification of every operation. Its recent changelog also highlights post-object security fixes, draft handling, and temporary private media upload links.

### Launch gap priorities (BC vs competitor)

| Priority | Gap | Release approach |
| --- | --- | --- |
| P0 | No convenient category/tag/custom taxonomy assignment | Five scoped taxonomy abilities in `feat/v1-taxonomy-menu-abilities`; write opt-in, exact-post checks, term revision |
| P0 | No navigation menu operations | Four classic-menu abilities in this branch: list/get, empty menu create, safe existing-content menu item add |
| P0 | No administrator-accessible per-execution audit history | Implement a bounded privacy-safe log and export/clear controls before first public stable tag |
| P0 | Read-only ability visibility cannot be disabled per ability | Implement first-party read exposure allowlist/denylist with a default-safe migration and integration test |
| P0 | Public-release security regression coverage incomplete | Add role, private/draft, media, OAuth, multisite, cache, write confirmation and permission tests |
| P1 | Only Rank Math SEO fields are supported | Add separate, optional Yoast and SEOPress modules; keep all writes behind admin opt-in |
| P1 | WooCommerce lacks guarded price, sale-price, gallery and product status writes | Add revision-guarded per-product edits; never bulk export private customer/order PII |
| P1 | Post status/publish flow, featured image and safe post duplication | Preserve draft-safe defaults, explicit confirmation, protected meta restrictions |
| P1 | WPML/Polylang translation linking and language metadata | Implement as optional modules with direct plugin APIs, not raw meta replacement |
| P2 | Events, LMS, JetEngine, FSE, llms.txt, accessibility scanner | Implement according to demand as isolated plugins/modules, not launch blockers |

Product differentiation: BC ships only a small set of `tools/list` MCP tools but exposes its own many WordPress abilities through discovery; counts are **not directly equivalent** to the competitor's individual abilities. No Facebook, LinkedIn or YouTube features are in scope for the public BC plugin.

### Before publication

- Make sure all PHP/WPCS, WordPress Plugin Check and functional tests pass on the release tag, rather than relying on previous PR runs.
- Review data minimization, OAuth callback allowlisting and the separate Claude connection; the existing direct OAuth issuer currently restricts registration to ChatGPT callbacks.
- Verify the external live OAuth metadata redirect observed on WPToolix is intentional and compatible before publishing.
- Update stable tag/header, clean ZIP and WordPress.org readme/screenshots; obtain release security review.
