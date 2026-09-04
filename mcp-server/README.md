# @myotp/mcp — MyOTP.App MCP Server

A [Model Context Protocol](https://modelcontextprotocol.io/) server that exposes [MyOTP.App](https://myotp.app)'s OTP API to any MCP-compatible AI agent: Claude Desktop, Claude Code, Cursor, Windsurf, Codex, and anything else that speaks MCP.

Send and verify one-time passwords (SMS, WhatsApp, Telegram) directly from a chat with your agent, or from any app it builds.

## What it does

Exposes 10 tools:

| Tool | Purpose |
|---|---|
| `generate_otp` | Send an OTP via SMS, WhatsApp, or Telegram. Returns a `message_id`. |
| `verify_otp` | Verify a code submitted by an end user. |
| `check_otp_status` | Check delivery status / whether an OTP is still active. |
| `extend_otp` | Add more time to an active OTP without resending. |
| `get_account_info` | Sanity-check the API key and IP whitelist (calls `GET /me`). |
| `get_usage_report` | Paginated transaction history for a date range. |
| `create_account` | Create an agent account without an API key and return its one-time key. |
| `get_account_status` | Check verification, balance, plan, and status; optionally resend verification. |
| `get_topup_quote` | Quote a credit purchase and return USDC and card payment commands. |
| `top_up_credits` | Return an MPP payment challenge and retry details, or the credited result. |

All tools call the public MyOTP REST API at `https://api.myotp.app`. Override with the `MYOTP_BASE_URL` env var for a mock server.

## Install

You don't need to install anything globally — `npx` will fetch and run the latest version on demand:

```bash
npx @myotp/mcp
```

> Use the scoped name. `myotp-mcp` is the bin name inside the package, not a
> package on npm, so `npx myotp-mcp` does not resolve.

If you want to pin a version or install it locally:

```bash
npm install --save-dev @myotp/mcp
```

## Get an API key

Call `create_account` with an email address and optional name. It is the one
account tool that needs no configured key. Save the returned API key immediately:
it is shown once, and the new account starts with a zero balance. Configure that
key as `MYOTP_API_KEY` for stdio or send it with hosted requests, then call
`get_topup_quote` or `top_up_credits` to add credits.

The confirmation email requires a human click. Confirmation unlocks card
top-ups; USDC top-ups work before confirmation. Human signup at
[myotp.app/sign-up/](https://myotp.app/sign-up/) remains available and follows
the dashboard's email, phone, API-key, and IP-allowlist flow.

## Use it with Claude Desktop

Edit your Claude Desktop config (`~/Library/Application Support/Claude/claude_desktop_config.json` on macOS, `%APPDATA%\Claude\claude_desktop_config.json` on Windows):

```json
{
  "mcpServers": {
    "myotp": {
      "command": "npx",
      "args": ["-y", "@myotp/mcp"],
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
      "args": ["-y", "@myotp/mcp"],
      "env": {
        "MYOTP_API_KEY": "your-32-character-api-key"
      }
    }
  }
}
```

Or register it globally with the Claude Code CLI:

```bash
claude mcp add myotp -- npx -y @myotp/mcp
# Set the env var separately, then restart claude.
```

## Use it with Cursor

Cursor reads the same `.mcp.json` format. Add to your workspace settings or `.cursor/mcp.json`:

```json
{
  "mcpServers": {
    "myotp": {
      "command": "npx",
      "args": ["-y", "@myotp/mcp"],
      "env": {
        "MYOTP_API_KEY": "your-32-character-api-key"
      }
    }
  }
}
```

## Gateways that pass config in the URL

Some MCP gateways deliver per-user settings as query parameters rather than headers. The hosted server accepts `?apiKey=<key>` (also `?api_key=`, or a base64url `?config=` JSON blob with an `apiKey` field) as a fallback. A key in the `X-API-Key` or `Authorization: Bearer` header always wins over one in the URL. On Smithery, the config schema maps the key to the `x-api-key` header, so no URL parameter is needed there.

## Use it with Codex CLI

Codex reads TOML, not JSON. Add to `~/.codex/config.toml`:

```toml
[mcp_servers.myotp]
command = "npx"
args = ["-y", "@myotp/mcp"]

[mcp_servers.myotp.env]
MYOTP_API_KEY = "your-32-character-api-key"
```

Codex can also talk to the hosted server over HTTP instead of launching anything
locally. It attaches the token as `Authorization: Bearer`, which the hosted
endpoint accepts as an alias for `X-API-Key`:

```toml
[mcp_servers.myotp]
url = "https://mcp.myotp.app/mcp"
bearer_token_env_var = "MYOTP_API_KEY"
```

On older Codex builds that only pick up stdio servers, add
`experimental_use_rmcp_client = true` above the entry.

**If you use the hosted URL, add `108.61.176.199` to your API key's IP allowlist.**
Hosted calls reach the MyOTP API from that address rather than from your machine,
and every call returns 403 until it is allowed. The stdio option above has no such
requirement because the request leaves your own machine.

## Use it with anything else

Any client that speaks MCP works. The stdio block is the same shape everywhere
(`command: npx`, `args: ["-y", "@myotp/mcp"]`, `MYOTP_API_KEY` in the
environment), so Windsurf, Zed, Continue, OpenClaw, Hermes and Grokbot all take
the JSON or TOML equivalent of the blocks above.

For a hosted client, point it at `https://mcp.myotp.app/mcp` and send your key as
either `X-API-Key` or `Authorization: Bearer`. Use whichever one your client can
actually set.

## Transport modes

### stdio (default — for local agent installs)

The server reads JSON-RPC messages from stdin and writes them to stdout. The API key comes from the `MYOTP_API_KEY` env var, set when the agent launches the server. This is the right mode for desktop apps like Claude Desktop, Claude Code, and Cursor.

```bash
MYOTP_API_KEY=sk_... npx @myotp/mcp
# or explicitly
MYOTP_API_KEY=sk_... npx @myotp/mcp --stdio
```

### Streamable HTTP (for hosted servers)

Run an HTTP server that any MCP-compatible agent can point at. Authenticated
tools receive the API key per request via `X-API-Key`, so one hosted instance
can serve many tenants; anonymous tools do not require the header.

```bash
npx @myotp/mcp --http --port 3000
# or with the env switch
MYOTP_MCP_TRANSPORT=http PORT=3000 npx @myotp/mcp
```

The MCP endpoint is `POST /mcp` (also accepts `GET` and `DELETE` per the spec). Health check at `GET /healthz`.

This is what we host at `https://mcp.myotp.app/mcp`. Point your client at that
URL and send `X-API-Key: <your-key>` on authenticated tool requests.

## Configuration

| Env var | Default | Description |
|---|---|---|
| `MYOTP_API_KEY` | — | Your MyOTP.App API key. Required for authenticated tools; `create_account` and `get_topup_quote` work without it. |
| `MYOTP_BASE_URL` | `https://api.myotp.app` | API base URL. Override for a mock server. |
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
- *"Create a MyOTP agent account for dev@example.com."*
- *"Check whether my agent account email is verified, and resend the email if needed."*
- *"Quote 500 more MyOTP credits."*
- *"That send failed with NoBalance. Top up 100 credits."*

Under the hood the agent will pick the right tool, validate inputs against the JSON Schema we publish, and call the MyOTP API.

## Buying credits as an agent

Agents can buy MyOTP credits by themselves: one 402, one payment, no checkout
page, no card form. Credits cost $0.02 each, with a 25-credit ($0.50) minimum
and a 50,000-credit maximum per call. Card top-ups are capped at $100 per
account per rolling 24 hours; USDC is uncapped. A trial account moves to the
Starter pay-as-you-go pricing table on its first top-up, without creating a
subscription.

When an OTP send returns HTTP 403 `insufficient balance` or `NoBalance`, call
`get_topup_quote` to inspect the price or call `top_up_credits` to start the
purchase. `top_up_credits` fetches the quote first, then posts to `/v1/topup`
with the configured API key and no payment credential. The expected 402
response contains MPP offers for USDC on Tempo and, while the card cap permits,
card or Link through Stripe. The tool returns the decoded offers, challenge ID,
exact retry URL and body, and headers with credential placeholders.

The MCP server cannot hold the agent's wallet. Run one of the returned commands
with `your MyOTP API key` replaced locally; the MPP client pays and retries the
same request with its payment credential. That retry credits the account. If
the runtime already wraps `fetch` with an MPP credential provider, the tool can
receive and return the successful credited response directly.

USDC wallet (creating an mppx account makes a wallet, and testnet auto-funds):

```bash
npx -y mppx@0.9.2 https://api.myotp.app/v1/topup -X POST -H "x-api-key: your MyOTP API key" -H "content-type: application/json" -d "{\"credits\":100}"
```

Card or Link wallet (run `npx @stripe/link-cli auth login` once first):

```bash
npx -y @stripe/link-cli mpp pay https://api.myotp.app/v1/topup -X POST -d "{\"credits\":100}" -H "x-api-key: your MyOTP API key" --context "MyOTP credits"
```

## Account creation

The `create_account` tool calls the live unauthenticated
`POST /v1/agent/register` endpoint with an email and optional name. The response
contains the full account record and a 32-character API key that is shown once.
Save it immediately and set `MYOTP_API_KEY` (or configure the hosted client to
send it). Agent accounts have an open IP allowlist, need no phone verification,
and start with balance 0 and the Starter pay-as-you-go plan.

A confirmation email is sent to the supplied address. A human must click the
link within 24 hours to unlock card top-ups; USDC top-ups work immediately.
Use `get_account_status` to check `email_verified`, balance, plan, and account
status, or call it with `resend_verification: true` to send another email. Then
use `get_topup_quote` or `top_up_credits` to fund the account before sending an
OTP.

## Develop

```bash
git clone https://github.com/brntech/myotp-agentkit
cd myotp-agentkit/mcp-server
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
