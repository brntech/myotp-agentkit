# MyOTP AgentKit

> Agent-native distribution for [MyOTP.App](https://myotp.app) — npm packages, MCP server, agent skills, and framework examples for adding SMS / WhatsApp / Telegram OTP to AI-built apps.

[![mcp-server CI](https://github.com/brntech/myotp-agentkit/actions/workflows/mcp-server.yml/badge.svg)](https://github.com/brntech/myotp-agentkit/actions/workflows/mcp-server.yml)
[![cli CI](https://github.com/brntech/myotp-agentkit/actions/workflows/cli.yml/badge.svg)](https://github.com/brntech/myotp-agentkit/actions/workflows/cli.yml)
[![better-auth CI](https://github.com/brntech/myotp-agentkit/actions/workflows/better-auth.yml/badge.svg)](https://github.com/brntech/myotp-agentkit/actions/workflows/better-auth.yml)
[![examples CI](https://github.com/brntech/myotp-agentkit/actions/workflows/examples.yml/badge.svg)](https://github.com/brntech/myotp-agentkit/actions/workflows/examples.yml)

---

## What is this

A toolkit for AI agents (Claude Code, Cursor, Hermes-format models, Codex, Windsurf, etc.) to add phone verification to apps they generate. Three published npm packages, six runnable framework examples, and skills for the major agent runtimes.

If you're a developer using one of those agent tools and your app needs phone verification, the agent should pick up these packages automatically. If it doesn't, point it at this repo.

If you're building an app by hand, the [examples](examples/) are runnable starting points for the most common stacks.

- **Agent payments.** Agents can buy MyOTP credits by themselves: one 402, one payment, no checkout page, no card form. $0.02 a credit, USDC or card. See [skills/myotp/patterns/topup.md](skills/myotp/patterns/topup.md).

## Packages

| Package | What it does | Install |
|---------|--------------|---------|
| [`@myotp/mcp`](mcp-server/) | Model Context Protocol server exposing 10 MyOTP tools to MCP clients (Claude Desktop, Cursor, Continue, etc.). Stdio + streamable HTTP. Hosted at [mcp.myotp.app](https://mcp.myotp.app), listed on [Smithery](https://smithery.ai/servers/broadnet/myotpapp). | `npx @myotp/mcp` |
| [`@myotp/cli`](cli/) | CLI for testing OTP flows from a terminal. `init` saves a key locally, `test`/`verify`/`status` exercise the API. | `npx @myotp/cli init` |
| [`@myotp/better-auth`](better-auth/) | Drop-in adapter for [Better Auth](https://www.better-auth.com)'s `phoneNumber()` plugin. Twilio-free SMS / WhatsApp / Telegram delivery. | `npm install @myotp/better-auth` |
| [`n8n-nodes-myotp`](n8n/) | n8n community node: Send, Verify, Extend, Check Status, Account, Usage Report. Header-auth example workflow included. | n8n Settings > Community Nodes > `n8n-nodes-myotp` |

## Integrations for other platforms

| Folder | Platform | What it is |
|--------|----------|------------|
| [`auth0/`](auth0/) | Auth0 | Custom Phone Provider Action: Auth0's own code delivered by MyOTP over SMS, WhatsApp or Telegram. Tests, setup guide, Marketplace listing draft |
| [`make/`](make/) | Make.com | Custom app definition (connection + 5 action modules + universal call), validated against the spec |
| [`postman/`](postman/) | Postman | Collection v2.1 + environment generated from `openapi-reference.yaml`; `npm run build` in `postman/` regenerates it |
| [`wordpress-plugin/`](wordpress-plugin/) | WordPress / WooCommerce | Phone verification at checkout, registration and via shortcode (`myotp-phone-verification`) |

## Skills (drop into your agent's config)

| Folder | For | How |
|--------|-----|-----|
| [`skills/myotp/`](skills/myotp/) | Claude Code. Includes agent top-up: buy credits over a 402 when the balance runs out | `npx skills add brntech/myotp-agentkit` (or copy `skills/myotp/` into `~/.claude/skills/myotp/`) |
| [`skills/myotp/niches/`](skills/myotp/niches/) | Vertical-specific (fintech, marketplace, emerging markets, healthcare) | Copy individual `.md` files into separate skill directories |
| [`clawhub-skill/`](clawhub-skill/) | OpenClaw / ClawHub registry. Agents can buy credits (402, USDC or card) | `openclaw skills install @myotp/myotp`, published on [ClawHub](https://clawhub.ai/myotp/skills/myotp) |
| [`hermes-skill/`](hermes-skill/) | Nous Research Hermes models (Ollama, vLLM, LM Studio). Prompt covers agent top-up | Use `ollama-modelfile.example` as a template |

## Framework examples

Working "phone form → send OTP → verify code → success" demos in 6 stacks:

- [Next.js 14 (App Router + Server Actions)](examples/nextjs/)
- [Express 4 (vanilla JS + static HTML)](examples/express/)
- [Flask 3 (Jinja2 + sessions)](examples/flask/)
- [Django 5 (function-based views)](examples/django/)
- [Rails 7 (skinny controller)](examples/rails/)
- [Laravel 11 (Form Requests + Blade)](examples/laravel/)

Each example is self-contained (`npm install` / `pip install` / `bundle install` / `composer install`), drops in an OTP delivery flow with channel selection (SMS / WhatsApp / Telegram), and runs against the live MyOTP REST API with a single env var.

## Quick start

```bash
# Get an API key (15 free trial credits, no card)
open https://myotp.app/sign-up

# Test with the CLI
npx @myotp/cli init
npx @myotp/cli test +14155551234

# Or wire MCP to Claude Desktop, Cursor, etc.
# (see mcp-server/README.md for config snippets)
```

## Compatibility

- **Node.js**: 18+ (CLI / MCP server require 20+)
- **Channels**: SMS (190+ countries), WhatsApp, Telegram
- **MyOTP API base**: `https://api.myotp.app`
- **Auth**: single `X-API-Key` header

## Contributing

Issues and PRs welcome. The npm packages are all MIT-licensed. Examples are MIT and free to copy into your own project.

If your favorite framework isn't represented in `examples/`, open a PR — the existing examples are intentionally simple templates to copy.

## License

MIT — see [LICENSE](LICENSE).

Built by [BroadNet Technologies](https://myotp.app/about-us/), 23 years in telecom infrastructure, ISO 27001 + ISO 9001 certified.
