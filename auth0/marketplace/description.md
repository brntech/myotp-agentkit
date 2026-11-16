# MyOTP.App: Auth0 Marketplace listing copy

Listing text for the Send Phone Message integration in this folder. It covers SMS multi-factor authentication only
(enrollment and challenge). It does not cover passwordless SMS login.

## shortDescription

Deliver Auth0 MFA codes over SMS, WhatsApp or Telegram.

## Gallery tile 3

Auth0 keeps generating and checking every MFA code.

## description

MyOTP.App delivers one-time passwords over SMS, WhatsApp and Telegram with per-country pricing.

This integration is an Action for Auth0's Send Phone Message flow, so it handles SMS multi-factor authentication:
enrollment and challenge. Auth0 keeps generating and verifying the code. The Action passes that code to MyOTP, which
delivers it on the channel you choose. Users receive the exact code Auth0 expects, so nothing changes in your MFA flow.

What you get:

- SMS MFA codes (enrollment and challenge) delivered by MyOTP.
- A channel setting: SMS by default, or WhatsApp or Telegram.
- Failure reasons from the MyOTP API in Auth0 tenant logs.
- No npm dependencies. The Action uses the runtime's built-in fetch.

Setup:

1. Add the MyOTP.App integration and enter your MyOTP API key and delivery channel.
2. Add the Action to the Send Phone Message flow and apply the changes.
3. Under Security > Multi-factor Auth > Phone Message, select Custom as the delivery provider, save, and enable the
   factor.

The full steps are in the installation guide.

Not supported: voice calls and passwordless SMS login.

Pricing: per-country pricing at https://myotp.app/pricing/.

Categories: Multi-factor Authentication, Messaging / SMS.

Support: support@myotp.app. Privacy policy: https://myotp.app/privacy-policy/. Terms:
https://www.broadnet.me/legal/terms-of-service.
