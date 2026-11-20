# Test independent BC MCP Abilities with ChatGPT

> **Developer preview — not ready for a live production installation.** Security review and end-to-end OAuth acceptance tests remain mandatory before merge/release.

This integration is **independent** of the official MCP Adapter, the Jobayer AI Agent, any hosted Node server, and the competitor's vendor library. BC MCP Abilities itself hosts the MCP HTTP endpoint and OAuth authorization routes within WordPress.

## Setup on disposable HTTPS staging

1. Install the development branch on WordPress 6.9+ with a valid HTTPS URL and pretty permalinks.
2. Go to **Settings → BC MCP Abilities**.
3. Under **Connect ChatGPT directly**, enable **built-in MCP endpoint** and **WordPress OAuth authorization** and save.
4. Leave **all write abilities disabled**. Use a dedicated WordPress Editor for read-only testing.
5. Verify that these URLs respond correctly:
   - MCP endpoint: `https://example.com/wp-json/bc-mcp/v1/server` (unauthorized POST must be **401** with `WWW-Authenticate`)
   - Protected-resource discovery: `https://example.com/.well-known/oauth-protected-resource/wp-json/bc-mcp/v1/server`
   - Authorization-server discovery: `https://example.com/.well-known/oauth-authorization-server/bc-mcp`
   - DCR endpoint: `https://example.com/bc-mcp/oauth/register` (POST only)
   - Token endpoint: `https://example.com/bc-mcp/oauth/token` (POST only)
6. In **ChatGPT on the web**, open **Plugins → + → Add custom MCP server**. Enter your site URL from step 5, then choose **automatic OAuth registration via DCR** (rather than manually entering client secrets).
7. Sign into WordPress when redirected, then approve the permission screen.
8. Discover tools and execute only read operations before testing changes. The three exposed MCP tools are `bc-list-abilities`, `bc-get-ability-info`, and `bc-execute-ability`. The latter invokes only `bc-mcp-abilities/*` and needs an extra `confirmed=true` value for a write.

## ChatGPT account limitations

Custom MCP server creation and full MCP write access currently depend on ChatGPT's plan and workspace policies. Official OpenAI documentation lists full functionality for **Business / Enterprise / Edu**, with Pro supporting limited read/fetch. A Plus account may not show the create-custom-MCP action. This is a ChatGPT product limitation, not a failure of BC MCP Abilities.

Current details: [Connecting custom MCP servers in ChatGPT](https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt).

## Security implementation

- **Disabled by default:** separate toggles for MCP transport and OAuth; HTTPS is mandatory.
- **Dynamic Client Registration:** register only allowed HTTPS ChatGPT callback URLs; registration rate limited and bounded.
- **OAuth authorization:** WordPress user login plus nonce-protected consent form, authorization-code flow with required PKCE S256, exact client redirect URI and MCP `resource` binding.
- **Tokens:** random, opaque tokens; only hashes are stored; access tokens expire in one hour; refresh tokens rotate and expire after 30 days. Refresh-token requests require `offline_access`.
- **Scopes and permissions:** MCP `mcp` scope required. The ability's existing WordPress user capability checks and per-write admin opt-ins remain in force. BC write tools additionally require `confirmed=true`.
- **Isolation:** namespaced OAuth issuer and discovery URLs avoid overwriting the site's existing MCP Adapter OAuth routes.
- **No credentials or code are sent to an external BitwiseCode server.**

## Release gates

- [ ] PHP syntax for supported versions and WordPress Coding Standards
- [ ] WordPress Plugin Check and disposable-site smoke tests
- [ ] HTTP DCR registration, consent, and token exchange on live HTTPS staging
- [ ] Refresh rotation, revocation, repeated use/replay and expiry checks
- [ ] Invalid callback/domain/resource rejection and CSRF tests
- [ ] ChatGPT connection: discovery → WordPress sign-in → consent → MCP tools/list → ability execution
- [ ] Independent security review of OAuth code, token storage, registration rate limits and permissions
- [ ] Confirm compatibility with existing Claude/MCP Adapter endpoint

Do not merge and distribute an OAuth implementation solely because lint/smoke tests pass.
