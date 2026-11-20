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

## Automated HTTP end-to-end check

`tests/chatgpt-oauth-http-e2e.py` replays the ChatGPT connection sequence over real HTTP — protected-resource and authorization-server discovery, DCR, WordPress sign-in, consent, PKCE token exchange, `initialize`, `notifications/initialized`, `tools/list`, and a read-only `tools/call` of `bc-list-abilities` — plus negative cases (missing, malformed, unknown, revoked tokens; replayed refresh tokens; users without `edit_posts`; JSON-RPC errors). It never calls a write ability.

```bash
python3 tests/chatgpt-oauth-http-e2e.py https://staging.example.com EDITOR_LOGIN EDITOR_PASSWORD [SUBSCRIBER_LOGIN SUBSCRIBER_PASSWORD]
```

Use dedicated staging test accounts only. Each run registers one DCR client.

## Troubleshooting with diagnostics

Enable **temporary OAuth connection diagnostics** under **Connect ChatGPT directly**, reconnect ChatGPT, and refresh the settings page. Each event shows the stage, result, MCP method, whether an `Authorization` header reached PHP and the token check outcome. Only fixed labels are stored (never tokens, codes, client IDs, cookies, IPs or bodies); at most 50 events are kept for 24 hours and they are deleted when diagnostics are turned off.

A healthy connection, oldest first:

| Stage | Result | MCP method | Authorization | Detail |
| --- | --- | --- | --- | --- |
| mcp | initialized | initialize | anonymous | protocol_2025-… (ChatGPT's tool scan) |
| mcp | listed | tools/list | anonymous | |
| registration | registered | | | |
| authorization | approved | | | |
| token | received → issued | | | |
| mcp | initialized | initialize | token_valid | protocol_… |
| mcp | listed | tools/list | token_valid | |
| mcp | call_succeeded | tools/call | token_valid | bc-list-abilities |

How to read failures:

- `unauthorized` · `missing` · `token_missing` on `tools/call` — no `Authorization` header reached PHP. The plugin already reads `HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION` and `getallheaders()`. If it persists, the host or a security/cache plugin is removing the header before PHP; WordPress's default `.htaccess` line `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` restores it on Apache/LiteSpeed.
- `token_unknown_or_expired` — the token is not in WordPress (expired after one hour, revoked, or the transient/object cache was flushed). ChatGPT should refresh; if it cannot, reconnect.
- `token_resource_mismatch` — the token was issued for a different URL (for example after changing the site URL or permalinks). Reconnect.
- `forbidden` / `user_not_permitted` — the authorizing WordPress user lacks `edit_posts`.
- `initialized` with `protocol_mismatch_offered_…` — the client asked for a protocol revision this server does not implement.
- `unknown_method` — the client called an MCP method this server does not offer (e.g. `resources/list`); it receives JSON-RPC error `-32601`, which is expected and harmless.

WordPress evaluates REST permission callbacks a second time while building the `Allow` header; this plugin records each request once.

### Fixed: tools could not be imported (2026-11)

`bc-list-abilities` advertised `"inputSchema": {"type": "object", "properties": []}`. PHP encodes an empty array as a JSON list, which is not a valid JSON Schema `properties` value; OpenAI rejects such tool schemas (`[] is not of type 'object'`). Discovery and OAuth succeeded, but ChatGPT never called a tool. The schema now encodes `"properties": {}`, and `tests/chatgpt-connection-smoke.php` guards against regressions.

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
