# env-mcp

Lets each WordPress user connect Claude (or ChatGPT, Gemini) to the site over MCP, with their own scoped access.

- **Requires:** WordPress 6.9+ (Abilities API), PHP 8.1+, and the [MCP Adapter](https://github.com/WordPress/mcp-adapter) plugin.
- **Endpoint:** `https://<site>/wp-json/wp-mcp/v1/`
- **claude.ai / Claude Desktop connector:** Settings > Connectors > Add custom connector, paste the endpoint, then sign in with your WordPress account (OAuth with dynamic client registration; discovery at `/.well-known/oauth-protected-resource`).
- **Token connections (Claude Code, CLI tools):** Tools > MCP Connect. Create a connection, choose what it may do, copy the setup. Tokens are stored hashed; "Show setup" issues a new token for the same connection.
- Every tool call is checked against the user's own capabilities and the connection's scope, and logged under their name.
- **Gravity Forms (3.2+):** its tools come through this endpoint, so no second connection is needed. The plugin sets Gravity Forms' own MCP settings (on, "Site MCP" mode, no dedicated endpoint) and allows forms, feeds, notifications, confirmations and system info, but no entry data: entries can only be counted (`includes/class-amc-gravityforms.php`).
