# MyOTP.App phone provider for Auth0

Deliver Auth0 passwordless SMS codes and SMS MFA codes through MyOTP.App instead of Twilio. Auth0 keeps generating and verifying the code. MyOTP only carries it, over SMS, WhatsApp or Telegram.

Per-country pricing is at https://myotp.app/pricing/.

## What is in this folder

| File | Purpose |
|---|---|
| `action/send-phone-message.js` | The Action. Paste it into the Auth0 editor. No npm dependencies. |
| `action/send-phone-message.test.js` | Tests with a mocked `fetch`, run with Node's built-in test runner. |
| `marketplace/listing.md` | Draft Auth0 Marketplace listing. |

## How it works

Auth0's Custom Phone Provider fires the `custom-phone-provider` Action trigger whenever it needs to send a phone message. For `otp_verify` and `otp_enroll` notifications the event carries the code Auth0 drew, in `event.notification.code`. The Action calls MyOTP `POST /generate_otp` with that value in `otp_code`, so the SMS the user receives contains the code Auth0 will check.

The Action also exports `onExecuteSendPhoneMessage` for the older `send-phone-message` trigger (MFA only). Auth0 has deprecated that trigger in favour of the Unified Phone Experience; use it only if your tenant cannot migrate yet.

What the Action does not do:

- Voice. MyOTP has no voice channel. Voice notifications are dropped with a logged reason.
- Non-OTP notifications (`blocked_account`, `change_password`, `password_breach`). These carry no code and are dropped with a logged reason. Keep Twilio if you need them.
- Verification. MyOTP's `/verify_otp` is never called. Auth0 verifies.

## Prerequisites

- A MyOTP.App account and API key: https://myotp.app/dashboard/user-api-keys/
- Credits on the account. Each send is billed at the destination country's rate.
- An Auth0 tenant with either Passwordless SMS or SMS MFA already enabled (Auth0 requires one of them before the Phone Provider page appears).

## Setup

### 1. Create the Action

1. In the Auth0 Dashboard go to **Branding > Phone Provider**.
2. Select **Custom**.
3. Under delivery method select **Text**. Leave Voice unselected.
4. Under **Provider Configuration**, replace the sample code with the contents of `action/send-phone-message.js`.
5. Click **Save**. Auth0 saves and deploys the Action in one step. It will not show up in the Actions library; that is expected.

If you manage the tenant with the Management API or Terraform instead: create an Action whose `supported_triggers` id is `custom-phone-provider`, deploy it, then set the phone provider to `custom` with `delivery_methods = ["text"]`.

### 2. Add secrets

Open the expanded Actions editor from the same page and add these under **Secrets**:

| Secret | Required | Value |
|---|---|---|
| `MYOTP_API_KEY` | yes | Your MyOTP API key. |
| `MYOTP_CHANNEL` | no | `sms` (default), `whatsapp` or `telegram`. |
| `MYOTP_BRAND` | no | 3 to 16 letters, digits or dots. Shown in the message. Screened against MyOTP's impersonation denylist. |
| `MYOTP_BASE_URL` | no | Leave unset. Defaults to `https://api.myotp.app`. |

Save again after adding secrets.

### 3. Send a test message

On **Branding > Phone Provider**, click **Send Test Message** and enter your own number. Auth0 renders a test notification and runs the Action. Check the MyOTP dashboard for the transaction and your phone for the code.

### 4. Enable the tenant-level provider

- Passwordless: **Authentication > Passwordless > SMS**, enable **Use Tenant-level Messaging Provider**, save.
- MFA: **Security > Multi-factor Auth > Phone Message**, enable **Use Tenant-level Messaging Provider**, save. Make sure the Phone factor itself is enabled.

Auth0 warns that switching the Unified Phone Experience off again after you have configured it can break MFA. Keep it on.

## Troubleshooting

Auth0 does not tell the end user whether the message was sent. Failures show up in **Monitoring > Logs** as failed notification events with the reason string this Action produced. Every reason starts with `MyOTP`.

| Log reason | Cause | Fix |
|---|---|---|
| `MyOTP responded 403: Access from this IP not allowed` | The MyOTP account has an IP allowlist and the Auth0 Actions egress IP is not on it. | In the MyOTP dashboard, either add Auth0's outbound IP addresses for your tenant region to the key's allowlist, or set the allowlist open. Auth0 publishes its outbound IPs per region in the Dashboard under **Settings > Advanced > Outbound IP addresses**. |
| `MyOTP responded 401: ...` | Wrong or missing API key. | Check the `MYOTP_API_KEY` secret. Keys are 32 characters. |
| `MyOTP responded 400: Service not available ...` or any 400 naming the country | The destination country is not priced on your account. | Email sales@myotp.app with the country and channel. |
| `MyOTP responded 402: ...` | No credits. | Top up at https://myotp.app/dashboard/. |
| `MyOTP responded 5xx` or `MyOTP request failed: ...` | Transient. The Action asks Auth0 to retry, up to 5 times. | If it persists, check https://myotp.app/ status and contact support@myotp.app. |
| `MyOTP configuration error: MYOTP_CHANNEL must be one of ...` | Typo in the channel secret. | Use `sms`, `whatsapp` or `telegram`. |
| `MyOTP has no voice channel` | Voice was selected as a delivery method. | Select Text only. |
| `MyOTP delivers OTP codes only; notification type "..."` | Auth0 sent a non-OTP notification. | Expected. Those types are not supported. |

WhatsApp and Telegram deliveries depend on the destination having that app. If the user does not, the send fails at the vendor. SMS is the safe default.

## Running the tests

From the repository root, in Docker:

```sh
docker run --rm -v "$PWD:/w" -w /w/auth0 node:22-alpine sh -c "npm test"
```

## Example

This is the whole Action body for the common case: SMS, brand shown in the message. Add `MYOTP_API_KEY` and `MYOTP_BRAND` as secrets, paste the file, save.

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
      otp_length: n.code.length,
      channel: event.secrets.MYOTP_CHANNEL || "sms",
      brand: event.secrets.MYOTP_BRAND || undefined,
      force_send: true,
    }),
  });
  if (!res.ok) {
    const body = await res.text();
    return (res.status >= 500 ? api.notification.retry : api.notification.drop)(`MyOTP responded ${res.status}: ${body}`);
  }
};
```

The full file in `action/` adds input validation, a 15 second timeout, the legacy trigger and clearer error messages. Prefer it.
