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

## What's inside

- `SKILL.md` — skill manifest and trigger description.
- `samples/` — working integration code in 9 languages.
- `patterns/` — sequence diagrams and code outlines for signup verification, password reset, and transaction step-up auth.

## Reference

- API reference: https://myotp.app/api-reference/
- Sample code: https://myotp.app/sample-code-new/
- Pricing: https://myotp.app/pricing/
- Multi-channel guide: https://myotp.app/multi-channel-otp/
- Support: info@myotp.app
