# MyOTP.App + Next.js (App Router)

A minimal phone-verification demo using [MyOTP.App](https://myotp.app) and Next.js 14+ Server Actions. The user enters their phone number, receives a 6-digit code over SMS / WhatsApp / Telegram, then enters the code to verify.

## Why Server Actions?

The MyOTP API key is a server credential. Server Actions keep it on the server, where it belongs — the browser only ever sees form fields, never the key or the `MYOTP_BASE_URL`. This is the recommended integration pattern for MyOTP in any Next.js app.

## Prerequisites

- Node.js 18.17+ (Next.js 14 requirement)
- A MyOTP API key — sign up at https://myotp.app/sign-up (new accounts get 15 free trial credits)
- Your server's IP whitelisted in the MyOTP dashboard (use `*` for local development)

## Setup

```bash
npm install
cp .env.example .env.local
# edit .env.local and paste your API key
npm run dev
```

Open http://localhost:3000.

## Code walkthrough

```
app/
  page.tsx              -- "/" — phone number form
  verify/page.tsx       -- "/verify" — code entry form
  success/page.tsx      -- "/success" — verified state
  actions.ts            -- server actions: sendOtp, verifyOtp
  layout.tsx
  globals.css
lib/
  myotp.ts              -- server-side API client
  phone.ts              -- digits-only sanitiser
```

### `lib/myotp.ts`

Two functions, `generateOtp` and `verifyOtp`. Both POST JSON to MyOTP with the `X-API-Key` header. They throw on non-2xx so server actions can surface the error message back to the form.

### `app/actions.ts`

`"use server"` module. `sendOtp` reads the form, sanitises the phone number, calls `generateOtp`, then redirects to `/verify?phone=...&channel=...`. `verifyOtp` calls the verify endpoint and redirects to `/success` on a `status: "success"` response.

### Channel selection

The phone form has three radio buttons (SMS / WhatsApp / Telegram). MyOTP routes the same OTP through different vendors based on the `channel` field. WhatsApp delivery requires the recipient to have WhatsApp installed; Telegram requires a Telegram account. SMS is the universal fallback.

## Deploying to Vercel

```bash
vercel
vercel env add MYOTP_API_KEY
vercel env add MYOTP_BASE_URL   # https://api.myotp.app
vercel --prod
```

After deploy, copy the production deployment's outbound IP from the Vercel dashboard and whitelist it in MyOTP. Vercel rotates IPs across regions, so the simplest production setup uses `*` in MyOTP's whitelist combined with API-key rotation if a key is ever leaked.

## Extending

- **Persistent verification state** — replace the redirect with a session cookie or your own auth system. The `message_id` returned by `generateOtp` is the canonical handle for that OTP attempt.
- **Custom OTP length / brand / template** — `generate_otp` accepts `otp_length`, `brand`, `template_order`, and others. See https://api.myotp.app docs.
- **Resend / cooldown** — the MyOTP API returns 409 if an OTP is already active for that phone. Either wait for it to expire or pass `force_send: "true"` (not available in all markets).

## License

MIT — BroadNet Technologies.
