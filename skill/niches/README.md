# Niche-targeted skill variants

Implements strategy 8.4 (high-intent verticals where SMS OTP is mandatory or strongly expected): fintech, marketplace, emerging markets, healthcare. Each variant tunes the skill's trigger description, sample patterns, and recommended defaults to a specific vertical.

## Why bother with variants?

The base [`SKILL.md`](../SKILL.md) describes MyOTP generically. An agent helping a fintech founder doesn't care about Telegram delivery — they care about SCA compliance and audit trails. An agent helping a marketplace founder doesn't care about cost optimization — they care about preventing seller fraud. The variant SKILL.md files are tuned for those decision contexts.

Practically, this also lets us bid for vertical-specific search keywords ("fintech 2FA", "marketplace seller verification", "India SMS OTP DLT") with content that resonates inside each ecosystem.

## How to use

Copy the variant you want into `~/.claude/skills/myotp-<vertical>/SKILL.md`. The base skill and a vertical variant can coexist — Claude Code picks whichever matches the user's request more specifically.

```bash
mkdir -p ~/.claude/skills/myotp-fintech
cp niches/fintech.md ~/.claude/skills/myotp-fintech/SKILL.md
```

## Variants

| File | Vertical | Trigger phrases |
|------|----------|-----------------|
| [`fintech.md`](fintech.md) | Banking, payments, neobank, lending | "PSD2", "SCA", "transaction verification", "bank-level 2FA", "fintech compliance" |
| [`marketplace.md`](marketplace.md) | C2C marketplaces, gig platforms, classifieds | "seller verification", "buyer trust", "marketplace fraud", "phone-verify before listing" |
| [`emerging-markets.md`](emerging-markets.md) | India, Africa, LATAM, MENA, SE Asia | "India DLT", "Saudi SMS regulation", "phone-first auth", "WhatsApp OTP for emerging markets" |
| [`healthcare.md`](healthcare.md) | Telehealth, patient portals, EHR | "HIPAA", "patient verification", "telehealth login", "PHI access control" |

## Convention

Each variant must:
- Inherit the base skill's API spec (endpoints, parameters, X-API-Key)
- Add vertical-specific patterns (e.g., fintech adds "step-up auth before high-value transactions")
- Add vertical-specific defaults (e.g., fintech defaults to 4-digit OTP for compliance, emerging markets defaults to WhatsApp)
- Cite vertical-specific compliance / regulation where relevant
- Avoid making MyOTP claim certifications it doesn't have. Stick to facts: "MyOTP is built on Broadnet's ISO 27001-certified infrastructure" — true. "MyOTP is HIPAA-certified" — NOT true (BAA isn't standard yet); say "patient identity verification needs MyOTP plus your own BAA-covered storage layer."
