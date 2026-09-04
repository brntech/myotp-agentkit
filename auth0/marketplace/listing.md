# Auth0 Marketplace listing draft: MyOTP.App

Submitted by a human through the Auth0 Marketplace partner portal (https://marketplace.auth0.com/ > Become a partner). Auth0 reviews listings by hand. Everything below is copy to paste into the submission form. Fields Auth0 asks for that are not known yet are marked TODO.

## Integration name

MyOTP.App

## Integration type

Phone messaging provider (Custom Phone Provider Action, trigger `custom-phone-provider`).

## Short description (under 100 characters)

Send Auth0 passwordless and MFA codes over SMS, WhatsApp or Telegram with MyOTP.App.

## Long description

MyOTP.App delivers one-time passwords over SMS, WhatsApp and Telegram with per-country pricing and no monthly fee.

This integration is an Auth0 Custom Phone Provider Action. Auth0 keeps generating and verifying the code. The Action passes that code to MyOTP, which delivers it on the channel you choose. Users receive the exact code Auth0 expects, so nothing changes in your login flow.

What you get:

- Passwordless SMS login and SMS MFA (enrollment and challenge) delivered by MyOTP.
- A channel switch: SMS by default, or WhatsApp or Telegram through a single secret.
- Optional brand name in the message, screened against an impersonation denylist.
- Clear failure reasons in Auth0 tenant logs, with automatic retries on transient errors.
- No npm dependencies. The Action uses the runtime's built-in fetch.

Setup takes a few minutes: select Custom on Branding > Phone Provider, paste the Action, add your MyOTP API key as a secret, save, and enable the tenant-level provider for Passwordless or MFA.

Not supported: voice calls, and Auth0's non-OTP phone notifications (blocked account, password change, password breach).

Pricing: per-country pricing at https://myotp.app/pricing/.

## Categories

- Multi-factor Authentication
- Passwordless
- Messaging / SMS

## Links

| Field | Value |
|---|---|
| Documentation URL | https://github.com/brntech/myotp-agentkit/tree/main/auth0 (TODO: swap for https://myotp.app/integrations/auth0/ once that page is live) |
| Support URL | https://myotp.app/contact/ |
| Support email | support@myotp.app |
| Privacy policy | https://myotp.app/privacy-policy/ (TODO: confirm slug) |
| Terms of service | https://myotp.app/terms/ (TODO: confirm slug) |
| Source code | https://github.com/brntech/myotp-agentkit/tree/main/auth0 |
| Company website | https://myotp.app |
| Company name | BroadNet Technologies |

## Logo requirements

Auth0 asks for a square logo, PNG or SVG, on a transparent background, minimum 512 x 512 px, no text baked into the mark if avoidable. Provide a light-background and a dark-background variant. Source files are in the internal `MyOTP.Website/assets/social/` folder; export a 512 x 512 square from the icon mark, not the wordmark.

## Screenshots (optional but helps review)

1. Branding > Phone Provider with Custom selected and the Action pasted.
2. The Secrets tab showing `MYOTP_API_KEY` set (value hidden).
3. A tenant log entry for a successful test message.
4. The MyOTP dashboard transaction for that message.

## Reviewer notes

- The Action needs the tenant's Auth0 outbound IP addresses allowlisted on the MyOTP API key, or the key's allowlist set open. The README's troubleshooting table covers the 403 this causes.
- Tests: `node --test` under Node 22, 12 tests, mocked fetch, no network.
