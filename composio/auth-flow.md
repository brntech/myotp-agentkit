# Auth flow for the Composio MyOTP toolkit

We're API-key-based, no OAuth dance. This makes our auth flow the simplest case in Composio's `auth_schemes`.

## User journey

1. **Developer adds toolkit in Composio dashboard**
   - Selects "MyOTP.App" from the registry
   - Composio prompts for the API key

2. **Developer obtains an API key**
   - Composio shows a link: `https://myotp.app/sign-up/` with copy: "Sign up (15 free trial credits, no card required), generate an API key in the dashboard, paste here"
   - Once the planned `/v1/agent/register` endpoint ships, this collapses to a 1-step flow inside Composio (Composio calls our register endpoint, gets the key directly, never asks the user)

3. **Developer adds server IP to MyOTP whitelist**
   - This is the one extra step. The MyOTP dashboard requires IP allow-listing for security. For agents running on Composio's infra, we'd recommend supporting `*` wildcard in the IP whitelist OR working with Composio to publish their fixed IP ranges.
   - **Action item with Composio**: ask if they have a published IP allow-list we can pre-configure for users.

4. **Composio stores the key**
   - Server-side, encrypted at rest, scoped to the user/workspace
   - Never exposed to the LLM or to other workspace members beyond the configured policy

5. **At runtime**
   - When the agent calls `myotp_generate_otp`, Composio injects `X-API-Key: <key>` and forwards to `https://api.myotp.app/generate_otp`
   - Response is returned to the agent

## What we need from Composio

- Published outbound IP range (for IP whitelist setup) — this is a common ask, they likely have a doc
- Confirmation of where API keys are stored and rotation policy
- Webhook URL we can register if they want to send us tool-usage analytics (we'd consume to update `signup_source = "composio"` for attribution)

## Multi-tenant key storage

Each Composio workspace has its own MyOTP API key. There's no shared "Composio key" — each tenant brings their own. This matches our pricing model (per-account billing) and our security posture (no cross-tenant access).

## Failure modes Composio should surface

| MyOTP returns | Composio should show |
|---------------|----------------------|
| 401 Unauthorized Client | "MyOTP API key is invalid. Re-paste from your dashboard." |
| 403 Insufficient balance | "Top up your MyOTP account at myotp.app." |
| 403 IP not whitelisted | "Add Composio's IP range (or `*` for testing) to your MyOTP IP whitelist." |
| 400 Invalid phone | Pass through to the agent — it's a code-level mistake the agent should fix. |
| 5xx | Retry with exponential backoff (Composio's standard policy). |
