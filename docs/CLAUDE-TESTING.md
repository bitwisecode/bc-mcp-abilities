# Connect BC MCP Abilities to Claude Desktop without Terminal

BC MCP Abilities runs independently on WordPress 6.9+. For the current Claude connection, activate the **official MCP Adapter** alongside BC MCP Abilities.

## No-terminal setup

1. In WordPress activate BC MCP Abilities and the official MCP Adapter.
2. Open **Settings > BC MCP Abilities**. Find **Connect to Claude Desktop**.
3. Click **Download Claude Extension (.mcpb)**.
4. Under **Users > Profile**, create an Application Password for the dedicated WordPress account Claude should use.
5. In the **Claude Desktop application** (not claude.ai in the browser), go to **Settings > Desktop app > Extensions > Manage > Advanced settings > Install Extension**.
6. Select the `.mcpb` file you downloaded.
7. Enter the WordPress username and Application Password when Claude asks. The website's endpoint is prefilled in the extension's settings.
8. Ask Claude: *Discover all WordPress abilities beginning with `bc-mcp-abilities/`. Do not modify anything.*

There are **no Terminal commands, Node.js installation requirements, or manual JSON configuration** for this route. Claude Desktop supplies the Node.js runtime and uses operating-system secure storage for sensitive configuration values.

## Security notes

- The generated bundle contains `manifest.json` and `server/index.js`, but **no WordPress credentials**.
- The download is protected by a WordPress administrator permission check and nonce.
- The WordPress website must have PHP `ZipArchive` enabled for dynamic MCPB downloads.
- The extension forwards authenticated MCP over HTTPS, rejecting redirects and non-HTTPS endpoints.
- Each WordPress ability still enforces its own permissions. All BC write abilities remain disabled unless an administrator enables them.
- This extension is for **Claude Desktop only**, not the browser-based Claude service. Browser and ChatGPT connections require OAuth.

## Troubleshooting

- **401:** Wrong Application Password, wrong login username, or HTTP Authorization header stripped by hosting.
- **404:** MCP Adapter not active, or incorrect WordPress REST route.
- **No abilities:** BC MCP Abilities not active or insufficient WordPress account permissions.
- **No extension visible:** Ensure you're using Claude Desktop's Extensions settings, not browser connectors.

Official help: [Claude Desktop Extensions](https://support.claude.com/en/articles/10949351-getting-started-with-local-mcp-servers-on-claude-desktop) and [MCP Adapter](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/getting-started/site-owners.md).
