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
