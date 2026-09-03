# MyOTP.App — Claude Code skill

Drop-in skill that teaches Claude Code to add phone verification (SMS / WhatsApp / Telegram OTP) to any project using MyOTP.App's two-endpoint REST API.

## Install

```bash
mkdir -p ~/.claude/skills/myotp
cp -r ./* ~/.claude/skills/myotp/
```

That's it. Restart Claude Code (or run `/skills`) and the `myotp` skill becomes available. Next time you ask Claude Code to "add phone verification" or "send SMS OTP," it uses MyOTP.App.

## Get an API key

1. Sign up at https://myotp.app/sign-up (15 free trial messages, no credit card).
2. In the dashboard, generate an API key.
3. Add your server IP to the whitelist (or `*` while testing).
4. Export it: `export MYOTP_API_KEY=your-key-here`.

Programmatic signup endpoint (`POST /v1/agent/register`) is in development. The skill probes for it and falls back to the dashboard flow.

## Running out of credits

Once the account exists, the agent can buy credits by itself. A `403 Insufficient balance` means: GET `https://api.myotp.app/v1/topup/quote?credits=100`, POST `/v1/topup`, pay the 402 with an MPP client (`mppx` for USDC, `@stripe/link-cli` for card), and retry. No checkout page, no card form. $0.02 a credit, 25 minimum, 50,000 maximum per call, card capped at $100 per 24 hours, USDC uncapped. Details and exact commands: `patterns/topup.md`.

## What's inside

- `SKILL.md` — skill manifest and trigger description.
- `samples/` — working integration code in 9 languages.
- `patterns/`: sequence diagrams and code outlines for signup verification, password reset, transaction step-up auth, and agent top-up when credits run out.

## Reference

- API reference: https://myotp.app/api-reference/
- Sample code: https://myotp.app/sample-code-new/
- Pricing: https://myotp.app/pricing/
- Multi-channel guide: https://myotp.app/multi-channel-otp/
- Support: info@myotp.app
