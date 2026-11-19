# Connecting BC MCP Abilities to ChatGPT

The official WordPress Abilities API registration is independent of any MCP client. ChatGPT connections require an authenticated, OAuth-capable MCP server on the site.

## Fastest route: reuse the working WordPress OAuth MCP endpoint

If Claude already connects using the browser-based OAuth flow, use **the same OAuth-enabled endpoint** in ChatGPT, **not** the Application Password-only MCP Adapter default route:

```
https://wptoolix.com/wp-json/mcp/mcp-oauth-server
```

This URL is a verified example for the WPToolix test website, not a universal URL for every WordPress site. The endpoint is provided by a compatible OAuth MCP server on your WordPress installation, **not** by the BC MCP Abilities plugin alone.

## WordPress settings

Under **Settings > BC MCP Abilities > Connect to ChatGPT**:

1. Confirm an OAuth MCP provider is active. This optional integration expects the `wp-media/mcp-oauth` OAuth provider exposing the `wpmedia_mcp_oauth_trusted_publishers` extension hook; the plugin does not import the competitor's code.
2. Keep all BC ability write permissions disabled during initial connection tests.
3. Check **Enable trusted ChatGPT OAuth client (CIMD)**.
4. Save the official ChatGPT Client Metadata URL shown by your ChatGPT app setup. The default is `https://chatgpt.com/oauth/client.json`.
5. Do not paste arbitrary client URLs. Only exact OpenAI-hosted `chatgpt.com` client metadata URLs are accepted, with callback-specific paths restricted to `/oauth/<callback-id>/client.json`.

The WordPress integration adds a trusted CIMD publisher entry **only when enabled**. The separate OAuth provider must still fetch and validate ChatGPT's metadata, enforce its exact published redirect URIs, verify PKCE S256, issue a short-lived code, verify its access tokens and bind them to the correct WordPress user and MCP resource. BC MCP Abilities does **not** perform these operations itself.

If your site's OAuth server supports only a fixed Claude client, or lacks compatible CIMD support, you will need a compatible OAuth provider or a future BC-built authorization implementation.

## ChatGPT setup (account-dependent)

ChatGPT's current custom MCP connection interface is available only to eligible plans/workspaces. On a supported ChatGPT web account:

1. Open **Plugins**.
2. Select **Add (+)**, then **Add custom MCP server**.
3. Give it a name (for example, `BC MCP Abilities - WPToolix`).
4. Paste the OAuth-enabled MCP server URL above.
5. Under Authentication, choose **OAuth** and **CIMD** / Client ID Metadata Document when offered. Review the client's **exact** metadata URL and redirect callback.
6. Confirm the data-sharing warning, create the private connection, and complete the WordPress login/consent flow.
7. Ask ChatGPT: `Discover all WordPress abilities beginning with bc-mcp-abilities/ and list five pages without making any changes.`

The MCP Adapter's default server exposes three helper tools (discover, get ability info, execute). It doesn't necessarily expose all 22 BC abilities as individual top-level tools.

## Current limitations

- **Not a built-in OAuth server**: This is an optional **integration** for an already-installed, compatible OAuth MCP provider. Abilities work without that provider.
- **Not DCR**: The first implementation supports trusted CIMD client metadata IDs, not arbitrary Dynamic Client Registration.
- The host's OAuth provider is responsible for token issuing/rotation and registered redirect validation.
- If ChatGPT insists on DCR, or the authorization code is rejected, use a provider supporting secured registration and exact callback allowlisting. Do not add anonymous, unrestricted registration endpoints.
- A connection may be unavailable because of ChatGPT account/workspace permissions rather than the website.
- Do not embed WordPress Application Passwords, long-lived tokens or callback secrets in WordPress admin pages or GitHub.
- No live ChatGPT OAuth connection has yet been verified for this code. Test on staging first.

## Competitor comparison

The user-supplied **Enable Abilities for MCP 2.17.1** ZIP implements a separate, opt-in **ChatGPT & Other OAuth Connectors** mode based on RFC 7591 Dynamic Client Registration (`/oauth/register`), with administrator-managed callback allowlists, registration caps and rate limits. Its older bundled `wp-media/mcp-oauth` library independently supports Claude CIMD identities. Its documentation recommends testing the exact ChatGPT callback URL and limiting the allowlist.

Our first BC implementation deliberately uses **CIMD** instead, as supported by the current OpenAI OAuth documentation, to avoid an unauthenticated public registration endpoint. If actual connection testing shows a need for DCR, implement a carefully scoped fallback later.

References:
- [ChatGPT custom MCP connections](https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt)
- [OpenAI Plugin OAuth requirements](https://developers.openai.com/plugins/build/auth)
- [Official WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter)
