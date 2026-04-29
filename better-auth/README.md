# @myotp/better-auth

[![npm version](https://img.shields.io/npm/v/@myotp/better-auth.svg)](https://www.npmjs.com/package/@myotp/better-auth)
[![npm downloads](https://img.shields.io/npm/dm/@myotp/better-auth.svg)](https://www.npmjs.com/package/@myotp/better-auth)
[![License: MIT](https://img.shields.io/npm/l/@myotp/better-auth.svg)](LICENSE)

> MyOTP.App phone-number adapter for [Better Auth](https://www.better-auth.com/). Drop-in SMS, WhatsApp, and Telegram OTP delivery — a cleaner alternative to Twilio Verify in custom-code mode.

## Why

Better Auth's [`phoneNumber()`](https://www.better-auth.com/docs/plugins/phone-number) plugin generates the OTP and stores it in your database, then asks you to deliver it. Most projects end up wiring Twilio (4 credentials, opaque pricing) or rolling their own SMTP-via-Twilio mess. This package gives you a 5-line setup against MyOTP.App: one API key, transparent per-message pricing, three channels (SMS / WhatsApp / Telegram).

This addresses the request in [Better Auth issue #4702](https://github.com/better-auth/better-auth/issues/4702) for a simpler OTP delivery option in custom mode.

## Install

```bash
npm install @myotp/better-auth better-auth
```

Get an API key at [myotp.app/sign-up](https://myotp.app/sign-up) (15 free trial credits, no card).

## Use

```typescript
import { betterAuth } from "better-auth";
import { phoneNumber } from "better-auth/plugins";
import { myotpSendOtp } from "@myotp/better-auth";

export const auth = betterAuth({
  database: yourDatabase(),
  plugins: [
    phoneNumber({
      sendOTP: myotpSendOtp({
        apiKey: process.env.MYOTP_API_KEY!,
      }),
      otpLength: 6,
      expiresIn: 300, // 5 minutes
    }),
  ],
});
```

That's it. Better Auth handles generation + verification. MyOTP delivers.

## Channel selection

```typescript
phoneNumber({
  sendOTP: myotpSendOtp({
    apiKey: process.env.MYOTP_API_KEY!,
    channel: "whatsapp", // or "telegram", default "sms"
    brand: "Acme",       // optional sender brand
  }),
})
```

To make this user-selectable at sign-up time, build a small wrapper:

```typescript
const sendByChannel = (apiKey: string) => async ({ phoneNumber, code }, request) => {
  const channel = request?.headers?.get("x-otp-channel") ?? "sms";
  const fn = myotpSendOtp({ apiKey, channel });
  return fn({ phoneNumber, code }, request);
};
```

## All options

| Option | Type | Default | Notes |
|--------|------|---------|-------|
| `apiKey` | `string` | (required) | From the MyOTP dashboard or `/v1/agent/register` |
| `baseUrl` | `string` | `https://api.myotp.app` | Override for staging/test |
| `channel` | `"sms" \| "whatsapp" \| "telegram"` | `"sms"` | |
| `brand` | `string` | API key's default | 3-16 alphanumeric, dots allowed |
| `validitySeconds` | `number` | `300` | 30-14400 (30-3600 for Telegram) |
| `timeoutMs` | `number` | `15000` | Per-request timeout |
| `userAgent` | `string` | `myotp-better-auth/0.1.0` | |
| `fetch` | `typeof fetch` | `globalThis.fetch` | Inject for tests / edge runtimes |

## Error handling

The adapter throws `MyotpDeliveryError` (with `.status` and `.body`) on any non-2xx from MyOTP. Better Auth surfaces this back to the caller of `signIn.phoneNumber()`. Common cases:

- `403 Insufficient balance` — top up at [myotp.app](https://myotp.app)
- `403 IP not whitelisted` — add server IP (or `*` for testing) in dashboard
- `400 Destination could not be determined` — phone format issue (use digits only, no `+`, no leading 0; library does this for you but exotic numbers may still fail)

```typescript
import { MyotpDeliveryError } from "@myotp/better-auth";

try {
  await auth.api.sendVerificationCode({ phoneNumber: "..." });
} catch (err) {
  if (err instanceof MyotpDeliveryError && err.status === 403) {
    // ask user to retry / contact support
  }
}
```

## What this does (and doesn't)

**Does:** delivers an OTP code that Better Auth generated, by calling MyOTP's `/generate_otp` with the `otp_code` parameter set to Better Auth's value. MyOTP just acts as the delivery channel.

**Does NOT:** verify the code against MyOTP. Better Auth verifies against its own database. MyOTP's `/verify_otp` endpoint is not invoked.

This split matches the design pattern Supabase uses for its phone-auth hooks. It's the right boundary — keep state in your auth library, treat the SMS provider as a delivery service.

## Edge runtimes

Works on Vercel Edge, Cloudflare Workers, Bun, Deno — anywhere `globalThis.fetch` exists. If your runtime needs a custom fetch (Node < 18, jsdom-based tests), pass it explicitly:

```typescript
import { fetch as undiciFetch } from "undici";
myotpSendOtp({ apiKey: "...", fetch: undiciFetch });
```

## Testing your wiring

```bash
# 1. Get a key
npx myotp init             # interactive (after @myotp/cli ships)
# or sign up manually at https://myotp.app/sign-up

# 2. Verify the adapter directly
node --input-type=module -e '
  import("@myotp/better-auth").then(({ myotpSendOtp }) =>
    myotpSendOtp({ apiKey: process.env.MYOTP_API_KEY })({
      phoneNumber: "14155551234",
      code: "123456",
    }).then(() => console.log("delivered"))
  );
'
```

## Submitting upstream

We're working with the Better Auth team to list this in their official docs. Track [issue #4702](https://github.com/better-auth/better-auth/issues/4702).

## License

MIT — BroadNet Technologies. See [LICENSE](LICENSE).
