# @myotp/cli

[![npm version](https://img.shields.io/npm/v/@myotp/cli.svg)](https://www.npmjs.com/package/@myotp/cli)
[![npm downloads](https://img.shields.io/npm/dm/@myotp/cli.svg)](https://www.npmjs.com/package/@myotp/cli)
[![License: MIT](https://img.shields.io/npm/l/@myotp/cli.svg)](LICENSE)

The official command line for [MyOTP.App](https://myotp.app). Send and verify OTPs over SMS, WhatsApp, and Telegram from your terminal, CI pipeline, or AI agent.

```bash
npx myotp init
npx myotp test +14155551234
npx myotp verify +14155551234 123456
```

## Why this exists

MyOTP is a multi-channel OTP API. Two API calls (`/generate_otp`, `/verify_otp`) and you have phone verification working in any language. The CLI is the fastest way to:

- Provision a new MyOTP account from a script or AI agent.
- Smoke-test an integration during development.
- Run a quick OTP from a tutorial, demo, or blog post.
- Drop into agent toolchains via the `--json` output mode.

## Install

The CLI is published to npm as `@myotp/cli` with the bin name `myotp`.

```bash
# One-off use, no install required
npx myotp <command>

# Or install globally
npm install -g @myotp/cli
myotp <command>
```

Requires Node 20 or newer.

## Quick start

```bash
# 1. Create an account (or import an existing API key)
npx myotp init

# 2. Send a test OTP
npx myotp test +14155551234

# 3. Verify the code that arrived
npx myotp verify +14155551234 482917

# 4. Inspect your account
npx myotp status
```

## Commands

### `myotp init`

Interactive account setup. Calls the MyOTP onboarding API, walks you through email and phone verification, and saves your API key to `~/.myotp/config.json`.

If you already have an API key from the dashboard, skip the registration flow:

```bash
npx myotp config --set-key sk_live_xxxxxxxxxxxxxxxx
```

### `myotp test <phone> [--channel <sms|whatsapp|telegram>]`

Sends an OTP to the given number using your configured API key. Phone numbers can be entered in any of these formats and the CLI will normalize them:

```bash
npx myotp test +14155551234
npx myotp test 14155551234
npx myotp test "+1 (415) 555-1234"
```

Channel options:

```bash
npx myotp test +14155551234                       # SMS (default)
npx myotp test +14155551234 --channel whatsapp    # WhatsApp
npx myotp test +14155551234 --channel telegram    # Telegram
```

Useful flags:

| Flag | Description |
|------|-------------|
| `--channel <name>` | sms, whatsapp, or telegram |
| `--brand <name>` | Override the sender brand for this message |
| `--otp-length <n>` | 3-8 digits (requires the CUSTOM_OTP_LENGTH entitlement) |
| `--return-otp` | Include the OTP code in the response. Testing only. |

### `myotp verify <phone> <code>`

Verifies the OTP that was sent. Exit codes:

- `0` if the code is correct.
- `2` if the code is wrong, expired, or not found (the API call succeeded, the code did not match).
- `1` for any other error (network, auth, etc.).

```bash
npx myotp verify +14155551234 482917
```

### `myotp status`

Shows the account associated with your API key. Detailed metrics like balance, plan, and trial credits are not yet exposed by the public API; this command surfaces what is available today (email, key source, base URL) and notes the rest as unknown.

### `myotp config`

Shows or modifies the saved config.

```bash
npx myotp config                              # show current config
npx myotp config --set-key <KEY>              # save an API key
npx myotp config --set-base-url <URL>         # override API base URL
npx myotp config --reset                      # delete the config file
```

The config file lives at `~/.myotp/config.json` with mode `0600` on POSIX systems.

### `myotp help`

Prints the usage information.

## API key precedence

The CLI looks for an API key in this order:

1. `--api-key <key>` flag passed on the command line.
2. `MYOTP_API_KEY` environment variable.
3. `~/.myotp/config.json`.

The first one that is set wins. This makes it easy to override the saved key for a single call:

```bash
MYOTP_API_KEY=sk_test_xxx npx myotp test +14155551234
```

## JSON mode for agents

Every command supports a `--json` flag for machine-readable output. Combined with the documented exit codes, this makes the CLI safe to call from AI agents, scripts, and CI jobs.

Successful response:

```json
{
  "ok": true,
  "command": "test",
  "data": {
    "phone": "14155551234",
    "channel": "sms",
    "message_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "status": "accepted",
    "date_sent": "2026-04-28T10:30:00.000000",
    "expires_at": "2026-04-28T10:35:00.000000",
    "cost": 0.035
  }
}
```

Failure response:

```json
{
  "ok": false,
  "command": "test",
  "error": {
    "code": "http_403",
    "message": "Insufficient balance",
    "details": { "...": "..." }
  }
}
```

Agent example:

```bash
RESULT=$(npx myotp test +14155551234 --json)
MESSAGE_ID=$(echo "$RESULT" | jq -r '.data.message_id')
# ...prompt user for the code, then:
npx myotp verify +14155551234 "$CODE" --json --message-id "$MESSAGE_ID"
```

In `--json` mode, `init` requires `--email`, `--phone`, and `--company` as flags so it can run non-interactively.

## Environment variables

| Variable | Purpose |
|----------|---------|
| `MYOTP_API_KEY` | API key (overrides config file) |
| `MYOTP_BASE_URL` | API base URL (defaults to `https://api.myotp.app`) |
| `MYOTP_DEBUG` | When set, prints stack traces for unexpected errors |

## Exit codes

| Code | Meaning |
|------|---------|
| 0 | Success |
| 1 | Error (invalid input, network failure, API error, etc.) |
| 2 | `verify` ran successfully but the OTP did not match |
| 130 | User aborted an interactive prompt |

## Security notes

- The config file is created with mode `0600` on POSIX systems so other users on the same machine cannot read your API key.
- The CLI never logs the full API key. The `config` and `status` commands show a masked version (first 4 and last 4 characters).
- The CLI only talks to `https://api.myotp.app` by default. Override with `--base-url` or `MYOTP_BASE_URL` for self-hosted or staging environments.

## Source and license

MIT licensed. Source: [`brntech/myotp-agentkit/cli`](https://github.com/brntech/myotp-agentkit/tree/main/cli).

Built and maintained by [BroadNet Technologies](https://broadnet.com), the team behind MyOTP.App.
