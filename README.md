# BC MCP Abilities

Independent WordPress Abilities API integration by **BitwiseCode**. WordPress 6.9+ required; the official WordPress MCP Adapter is optional.

## Development status

Current code is a developer preview on the `feat/agent-abilities-foundation` branch. **Do not deploy to production.** GitHub CI, Plugin Check, and WordPress staging integration tests must pass before release.

Implemented abilities: site information, list/get posts and pages, create drafts, update posts with revision safeguards, list/get media, update image alt text, read Elementor documents and individual elements, and check same-site URLs.

Settings: **Settings → BC MCP Abilities**. Write abilities are disabled by default; an administrator must explicitly enable each one. Existing user permissions remain enforced.

This plugin does not create an MCP endpoint. To connect through MCP, install the official WordPress MCP Adapter and authenticate your MCP client.

## Documentation
- [Development roadmap](docs/ROADMAP.md)
- [Security design and open issues](docs/SECURITY.md)
- [Competitor comparison](docs/COMPETITOR-REVIEW.md)

## Development

```bash
composer install
composer lint
```

License: GPL-2.0-or-later.
