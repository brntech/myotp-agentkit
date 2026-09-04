# MyOTP.App phone provider for Auth0

Deliver Auth0 passwordless SMS codes and SMS MFA codes through MyOTP.App instead of Twilio. Auth0 keeps generating and verifying the code. MyOTP only carries it, over SMS, WhatsApp or Telegram.

Per-country pricing is at https://myotp.app/pricing/.

## What is in this folder

| File | Purpose |
|---|---|
| `action/send-phone-message.js` | The Action. Paste it into the Auth0 editor. No npm dependencies. |
| `action/send-phone-message.test.js` | Tests with a mocked `fetch`, run with Node's built-in test runner. |

## How it works

Auth0's Custom Phone Provider fires the `custom-phone-provider` Action trigger whenever it needs to send a phone message. For `otp_verify` and `otp_enroll` notifications the event carries the code Auth0 drew, in `event.notification.code`. The Action calls MyOTP `POST /generate_otp` with that value in `otp_code`, so the SMS the user receives contains the code Auth0 will check.

The request always goes to `https://api.myotp.app`. There is no host override, on purpose: a setting that could redirect the request would also send the API key, the phone number and the code to that host.

What the Action does not do:

- Voice. MyOTP has no voice channel. Voice notifications are dropped with a logged reason.
- Non-OTP notifications (`blocked_account`, `change_password`, `password_breach`). These carry no code and are dropped with a logged reason. Keep Twilio if you need them.
- Verification. MyOTP's `/verify_otp` is never called. Auth0 verifies.
- Delivery confirmation. A 2xx from MyOTP means the message was accepted for sending. If the carrier or app rejects it later, Auth0 is not told. Check the transaction in the MyOTP dashboard for the final status.

### Retries and duplicate messages

Every send uses `force_send`, so a "resend code" in Auth0 always produces a new message. To keep that from turning into duplicates, the Action asks Auth0 to retry only when the request provably never reached MyOTP. That is two cases: a DNS or connection failure raised before any bytes were sent, and a 429. MyOTP's edge rate limiter allows 100 requests per minute per client IP and answers 429 before the request reaches the application, so a 429 was neither sent nor charged. Timeouts, connection resets and 5xx responses are ambiguous, since MyOTP may already have sent the message. Those are dropped with a logged reason and the user can press resend.

Duplicates are possible only if MyOTP accepted the request and the response was lost. In that case the Action drops rather than retries, so one lost reply costs one resend tap.

The whole exchange, response body included, is capped at 8 seconds to stay inside Auth0's Action budget.

## Prerequisites

- A MyOTP.App account and API key: https://myotp.app/dashboard/user-api-keys/
- Credits on the account. Each send is billed at the destination country's rate.
- An Auth0 tenant. If it does not yet have Passwordless SMS or SMS MFA enabled, Auth0's Unified Phone Experience setup guide walks through turning them on.

## Setup

### 1. Create the Action

1. In the Auth0 Dashboard go to **Branding > Phone Provider**.
2. Select **Custom**.
3. Under delivery method select **Text**. Leave Voice unselected.
4. Under **Provider Configuration**, replace the sample code with the contents of `action/send-phone-message.js`.
5. Click **Save**. Auth0 saves and deploys the Action in one step. It will not show up in the Actions library; that is expected.

### 2. Add secrets

1. On the same page, open the expanded Actions editor.
2. Under **Secrets**, add:

| Secret | Required | Value |
|---|---|---|
| `MYOTP_API_KEY` | yes | Your MyOTP API key. |
| `MYOTP_CHANNEL` | no | `sms` (default), `whatsapp` or `telegram`. |
| `MYOTP_BRAND` | no | 3 to 16 letters, digits or dots. Shown in the message. Screened against MyOTP's impersonation denylist. |

3. In the expanded editor click **Save** and then **Deploy**. A saved draft is not the running version until it is deployed.
4. Go back to **Branding > Phone Provider**, make sure **Custom** is still selected, and click **Save** on the provider page.

### 3. Send a test message

On **Branding > Phone Provider**, click **Send Test Message** and enter your own number. Auth0 renders a test notification and runs the Action. Check the MyOTP dashboard for the transaction and your phone for the code.

### 4. Enable the tenant-level provider

- Passwordless: **Authentication > Passwordless > SMS**. If a **Use Tenant-level Messaging Provider** toggle is shown, enable it and save.
- MFA: **Security > Multi-factor Auth > Phone Message**. If the same toggle is shown, enable it and save. Make sure the Phone factor itself is enabled.

If the toggle is absent, the tenant is already on the Unified Phone Experience and the Custom provider is in use.

Auth0 warns that switching the Unified Phone Experience off again after you have configured it can break MFA. Keep it on.

### Terraform or Management API instead of the dashboard

Three resources are needed, in this order:

1. An Action whose `supported_triggers` id is `custom-phone-provider`, containing this file, deployed.
2. A trigger binding (`auth0_trigger_action` in Terraform) that attaches the deployed Action to the `custom-phone-provider` trigger. Without the binding the Action never runs.
3. The phone provider set to `custom` with `delivery_methods = ["text"]` (`auth0_phone_provider`), depending on the binding.

Secrets go on the Action resource. Auth0's page "Configure a custom phone provider with Terraform" has the full resource definitions.

### Legacy MFA Notifications flow

Tenants that cannot use the Unified Phone Experience still run the older `send-phone-message` trigger for MFA only. The file also exports `onExecuteSendPhoneMessage` for that trigger, but a `custom-phone-provider` Action does not serve it. Create a separate Action in **Actions > Library** with the trigger set to Send Phone Message, paste the same file, add the same secrets, deploy, and attach it in **Actions > Flows > Send Phone Message**. That flow has no retry or drop api, so failures are thrown and land in the tenant log as failed Action executions.

## Troubleshooting

Auth0 does not tell the end user whether the message was sent. Failures show up in **Monitoring > Logs** as failed notification events with the reason string this Action produced. Every reason starts with `MyOTP`. Phone numbers the Action reports itself are masked to their last two digits. Text echoed back by the API is redacted before it is logged: the recipient digits, the code, the API key and any 32-character hex token become `[redacted]`.

| Log reason | Cause | Fix |
|---|---|---|
| `MyOTP responded 403: Access from this IP not allowed` | The MyOTP account has an IP allowlist and the Auth0 Actions egress IP is not on it. | In the MyOTP dashboard, either add Auth0's outbound IP addresses for your tenant region to the key's allowlist, or set the allowlist open. Auth0 publishes its outbound IPs per region in the Dashboard under **Settings > Advanced > Outbound IP addresses**. |
| `MyOTP responded 401: ...` | Wrong or missing API key. | Check the `MYOTP_API_KEY` secret. Keys are 32 characters. |
| `MyOTP responded 400: Service not available ...` or any 400 naming the country | The destination country is not priced on your account. | Email sales@myotp.app with the country and channel. |
| `MyOTP responded 402: ...` | No credits. | Top up at https://myotp.app/dashboard/. |
| `MyOTP responded 429: ...` | Rate limited by MyOTP's edge rate limiter before the request reached the application. Nothing was sent or charged, so Auth0 retries automatically. | Nothing, unless it repeats. |
| `MyOTP responded 5xx: ...`, `MyOTP request failed: timed out ...`, `MyOTP response body read failed: ...` | MyOTP or the network did not answer cleanly. The message may or may not have gone out, so it is not retried. | The user presses resend. If it persists, contact support@myotp.app. |
| `MyOTP request failed: ...` with retry | DNS or connection failure before anything was sent. Auth0 retries up to 5 times. | Nothing, unless it persists. |
| `MyOTP configuration error: MYOTP_CHANNEL must be one of ...` | Typo in the channel secret. | Use `sms`, `whatsapp` or `telegram`. |
| `MyOTP configuration error: Auth0 did not supply a numeric code of 4 to 8 digits` | Telegram needs at least 4 digits and Auth0 sent fewer. | Use a longer code length in Auth0, or SMS. |
| `MyOTP has no voice channel` | Voice was selected as a delivery method. | Select Text only. |
| `MyOTP delivers OTP codes only; notification type "..."` | Auth0 sent a non-OTP notification. | Expected. Those types are not supported. |

WhatsApp and Telegram deliveries depend on the destination having that app. If the user does not, the send fails at the vendor after MyOTP has accepted it, and Auth0 sees a success. SMS is the safe default.

## Running the tests

From the repository root, in Docker. Bash:

```sh
docker run --rm -v "$PWD:/w" -w /w/auth0 node:22-alpine sh -c "npm test"
```

PowerShell:

```powershell
docker run --rm -v "${PWD}:/w" -w /w/auth0 node:22-alpine sh -c "npm test"
```

## Example

This is the whole Action body for the common case: SMS, brand shown in the message. Add `MYOTP_API_KEY` and `MYOTP_BRAND` as secrets, paste the file, save and deploy.

```js
exports.onExecuteCustomPhoneProvider = async (event, api) => {
  const n = event.notification;
  if (n.message_type !== "otp_verify" && n.message_type !== "otp_enroll") {
    return api.notification.drop(`MyOTP delivers OTP codes only; got ${n.message_type}`);
  }
  const res = await fetch("https://api.myotp.app/generate_otp", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-API-Key": event.secrets.MYOTP_API_KEY },
    body: JSON.stringify({
      phone_number: n.recipient.replace(/[^0-9]/g, ""),
      otp_code: n.code,
      channel: event.secrets.MYOTP_CHANNEL || "sms",
      brand: event.secrets.MYOTP_BRAND || undefined,
      force_send: true,
    }),
    signal: AbortSignal.timeout(8000),
  });
  if (!res.ok) {
    const body = (await res.text()).slice(0, 300);
    return (res.status === 429 ? api.notification.retry : api.notification.drop)(`MyOTP responded ${res.status}: ${body}`);
  }
};
```

The full file in `action/` adds input validation, phone masking in logs, a bounded body read, the legacy trigger and clearer error messages. Prefer it.

## Marketplace listing copy

Integration name: MyOTP.App

Integration type: Phone messaging provider (Custom Phone Provider Action, trigger `custom-phone-provider`).

Short description: Send Auth0 passwordless and MFA codes over SMS, WhatsApp or Telegram with MyOTP.App.

Long description:

MyOTP.App delivers one-time passwords over SMS, WhatsApp and Telegram with per-country pricing and no monthly fee.

This integration is an Auth0 Custom Phone Provider Action. Auth0 keeps generating and verifying the code. The Action passes that code to MyOTP, which delivers it on the channel you choose. Users receive the exact code Auth0 expects, so nothing changes in your login flow.

What you get:

- Passwordless SMS login and SMS MFA (enrollment and challenge) delivered by MyOTP.
- A channel switch: SMS by default, or WhatsApp or Telegram through a single secret.
- Optional brand name in the message, screened against an impersonation denylist.
- Failure reasons from the MyOTP API in Auth0 tenant logs, with phone numbers masked and any echoed recipient, code or key redacted. Retries only when a request provably never reached MyOTP; a lost reply after acceptance is dropped, not retried, so it cannot multiply into duplicate messages or charges.
- No npm dependencies. The Action uses the runtime's built-in fetch.

Setup takes a few minutes: select Custom on Branding > Phone Provider, paste the Action, add your MyOTP API key as a secret, save and deploy, and enable the tenant-level provider for Passwordless or MFA.

Not supported: voice calls, and Auth0's non-OTP phone notifications (blocked account, password change, password breach).

Pricing: per-country pricing at https://myotp.app/pricing/.

Categories: Multi-factor Authentication, Passwordless, Messaging / SMS.

Support: support@myotp.app. Privacy policy: https://myotp.app/privacy-policy/. Terms: https://myotp.app/term-condition/.
