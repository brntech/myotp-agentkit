# @myotp/mcp — MyOTP.App MCP Server

A [Model Context Protocol](https://modelcontextprotocol.io/) server that exposes [MyOTP.App](https://myotp.app)'s OTP API to any MCP-compatible AI agent: Claude Desktop, Claude Code, Cursor, Windsurf, Codex, and anything else that speaks MCP.

Send and verify one-time passwords (SMS, WhatsApp, Telegram) directly from a chat with your agent, or from any app it builds.

## What it does

Exposes 7 tools:

| Tool | Purpose |
|---|---|
| `generate_otp` | Send an OTP via SMS, WhatsApp, or Telegram. Returns a `message_id`. |
| `verify_otp` | Verify a code submitted by an end user. |
| `check_otp_status` | Check delivery status / whether an OTP is still active. |
| `extend_otp` | Add more time to an active OTP without resending. |
| `get_account_info` | Sanity-check the API key and IP whitelist (calls `GET /me`). |
| `get_usage_report` | Paginated transaction history for a date range. |
| `create_account` | Programmatic onboarding (placeholder — see "Account creation" below). |

All tools call the public MyOTP REST API at `https://api.myotp.app`. Override with the `MYOTP_BASE_URL` env var for staging.

## Install

You don't need to install anything globally — `npx` will fetch and run the latest version on demand:

```bash
npx myotp-mcp
```

If you want to pin a version or install it locally:

```bash
npm install --save-dev @myotp/mcp
```

## Get an API key

1. Sign up at [myotp.app/sign-up/](https://myotp.app/sign-up/) — 15 free trial credits, no card required.
2. In the dashboard, generate an API key.
3. Add your machine's public IP (or `*` for testing) to the IP whitelist for that key.

## Use it with Claude Desktop

Edit your Claude Desktop config (`~/Library/Application Support/Claude/claude_desktop_config.json` on macOS, `%APPDATA%\Claude\claude_desktop_config.json` on Windows):

```json
{
  "mcpServers": {
    "myotp": {
      "command": "npx",
      "args": ["-y", "myotp-mcp"],
      "env": {
        "MYOTP_API_KEY": "your-32-character-api-key"
      }
    }
  }
}
```

Restart Claude Desktop. Ask the agent: *"Send a test OTP to my phone +1 415 555 1234."* It will call `generate_otp` and report the message_id.

## Use it with Claude Code

Add it to your project's `.mcp.json`:

```json
{
  "mcpServers": {
    "myotp": {
      "command": "npx",
      "args": ["-y", "myotp-mcp"],
      "env": {
        "MYOTP_API_KEY": "your-32-character-api-key"
      }
    }
  }
}
```

Or register it globally with the Claude Code CLI:

```bash
claude mcp add myotp -- npx -y myotp-mcp
# Set the env var separately, then restart claude.
```

## Use it with Cursor

Cursor reads the same `.mcp.json` format. Add to your workspace settings or `.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "myotp": {
      "command": "npx",
      "args": ["-y", "myotp-mcp"],
      "env": {
        "MYOTP_API_KEY": "your-32-character-api-key"
      }
    }
  }
}
```

## Transport modes

### stdio (default — for local agent installs)

The server reads JSON-RPC messages from stdin and writes them to stdout. The API key comes from the `MYOTP_API_KEY` env var, set when the agent launches the server. This is the right mode for desktop apps like Claude Desktop, Claude Code, and Cursor.

```bash
MYOTP_API_KEY=sk_... npx myotp-mcp
# or explicitly
MYOTP_API_KEY=sk_... npx myotp-mcp --stdio
```

### Streamable HTTP (for hosted servers)

Run an HTTP server that any MCP-compatible agent can point at. The API key is provided per-request via the `X-API-Key` header — so a single hosted instance can serve many tenants.

```bash
npx myotp-mcp --http --port 3000
# or with the env switch
MYOTP_MCP_TRANSPORT=http PORT=3000 npx myotp-mcp
```

The MCP endpoint is `POST /mcp` (also accepts `GET` and `DELETE` per the spec). Health check at `GET /healthz`.

This is what we host at `https://mcp.myotp.app/mcp`. Point your client at that URL and send `X-API-Key: <your-key>` on every request.

## Configuration

| Env var | Default | Description |
|---|---|---|
| `MYOTP_API_KEY` | — | Your MyOTP.App API key (required in stdio mode). |
| `MYOTP_BASE_URL` | `https://api.myotp.app` | API base URL. Override for staging. |
| `MYOTP_MCP_TRANSPORT` | `stdio` | Set to `http` to start in HTTP mode. |
| `PORT` | `3000` | HTTP listen port. |
| `HOST` | `0.0.0.0` | HTTP bind address. |
| `MCP_PATH` | `/mcp` | HTTP route for MCP traffic. |

## Example tool calls

Once the server is wired up, you can ask the agent things like:

- *"Send an OTP via WhatsApp to 14155551234."*
- *"Use MyOTP to verify code 482913 for that phone number."*
- *"Did the last OTP get delivered? Check status for message_id `a1b2…`."*
- *"Show me my OTP usage for the last 7 days."*
- *"How much credit do I have on this MyOTP account?"*

Under the hood the agent will pick the right tool, validate inputs against the JSON Schema we publish, and call the MyOTP API.

## Account creation

The `create_account` tool calls `POST /v1/agent/register` and currently returns a 404 with a friendly message asking the user to sign up at [myotp.app/sign-up/](https://myotp.app/sign-up/) (~60 seconds, 15 free trial credits). The decision (2026-04-29) is to keep signup human-driven — the actual ongoing-friction unlock is **Stripe x402** for autonomous top-up of paid credits, not skipping the one-time signup.

A `top_up_credits` tool is shipping in a future release: when the agent's account exhausts paid credits mid-flow, `top_up_credits` returns a Stripe x402 challenge with a USDC deposit address; the agent pays from a pre-funded wallet; balance is credited via webhook in ~5-15 seconds. No human-in-the-loop after the initial wallet funding.

## Develop

```bash
git clone https://github.com/broadnet/myotp-app
cd myotp-app/MyOTP.AgentKit/mcp-server
npm install
npm run build
npm run start:stdio   # or start:http
```

## Security notes

- This server never logs your API key.
- In HTTP mode, the API key is only read from `X-API-Key` per request — there is no global key configured at startup.
- The MyOTP API additionally enforces an IP whitelist; make sure the host running this server (or your end users' IPs in HTTP mode) are on the allow-list for the key in use.
- Returning the OTP code in plain text (`return_otp: true`) is intended for testing only — never enable it in production user flows.

## License

MIT — see [LICENSE](./LICENSE).

Built by [BroadNet Technologies](https://broadnet.me). Questions? `info@myotp.app`.
