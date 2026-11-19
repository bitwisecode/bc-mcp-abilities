# Test BC MCP Abilities with Claude Desktop on macOS

This is a temporary testing path. **BC MCP Abilities is independent of the MCP Adapter for WordPress-side ability registration**, but it does **not yet implement an MCP endpoint or OAuth inside the plugin**. For Claude testing install the [official WordPress MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) as a separate bridge. A future release may add a built-in MCP endpoint and OAuth for direct ChatGPT connections without relying on another hosted MCP server.

## Staging WordPress

1. Install BC MCP Abilities staging ZIP on **WordPress 6.9+**.
2. Install and activate official **MCP Adapter** on the same site.
3. Keep all write abilities **off** under Settings → BC MCP Abilities.
4. Under Users → Profile, create a dedicated **WordPress Application Password**. Choose a user with the least privilege needed; for `site-info` the BC plugin requires Administrator. For initial reads use an Editor.
5. Note your website **Site Address URL**; the default MCP endpoint is `https://yourwebsite.example/wp-json/mcp/mcp-adapter-default-server`.

## Claude Desktop (Mac)

Ensure Node.js is installed (`node -v`, `npx -v`). Claude Desktop can use the official local helper `@automattic/mcp-wordpress-remote`.

Open the config file:

```bash
mkdir -p "$HOME/Library/Application Support/Claude"
touch "$HOME/Library/Application Support/Claude/claude_desktop_config.json"
open -a TextEdit "$HOME/Library/Application Support/Claude/claude_desktop_config.json"
```

If the file is empty, paste this JSON. If it already has `mcpServers`, **add the new server under the existing object without replacing anything**.

```json
{
  "mcpServers": {
    "bc-wordpress-staging": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://yourwebsite.example/wp-json/mcp/mcp-adapter-default-server",
        "WP_API_USERNAME": "your-wordpress-username",
        "WP_API_PASSWORD": "your-application-password"
      }
    }
  }
}
```

Save, fully quit Claude Desktop (Cmd+Q), then reopen it. Keep this configuration private because it holds an Application Password. Do not post it in GitHub or chat.

If Claude Desktop's modern settings do not show a Developer tab, the manual config file location above still provides the required configuration.

## Read-only test prompts

1. `Discover all abilities exposed by my WordPress MCP Adapter, and show the ones beginning with bc-mcp-abilities/.`
2. `Get info about bc-mcp-abilities/list-content including its input schema.`
3. `Use bc-mcp-abilities/list-content to list five pages, with no changes.`
4. `Use bc-mcp-abilities/get-content to read a page I choose. Do not modify it.`

Write tests should only take place on staging after the read-only tests succeed. Each write requires the site administrator to enable the corresponding ability in **Settings → BC MCP Abilities** and must pass the authenticated user's WordPress capability checks.

## Troubleshooting

- **404 / no MCP route**: Ensure WordPress MCP Adapter is installed and active, and the endpoint uses the correct Site Address URL.
- **401**: WordPress login username or Application Password is wrong, or the host strips the Authorization header.
- **No BC abilities**: Ensure BC MCP Abilities is active and both WordPress and the official Adapter have completed activation. Run the plugin's `tests/smoke.php` on a disposable staging site if needed.
- **Permission denied**: The WordPress user lacks the required capabilities or the requested write is disabled by the BC plugin.

Source: [Official WordPress MCP Adapter Site Owners Guide](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/getting-started/site-owners.md).
