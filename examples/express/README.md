# MyOTP.App + Express.js

A minimal phone-verification demo using [MyOTP.App](https://myotp.app) and Express 4.x. The browser posts a phone number to a server endpoint, which calls MyOTP and stores the resulting `message_id` server-side; the browser then submits the code, which the server verifies.

## Security boundary

The MyOTP API key is a server-only credential. In this example:

- The browser only sees the user's own phone number and OTP code.
- The server holds the API key (`process.env.MYOTP_API_KEY`).
- The `message_id` returned by `generate_otp` is kept in an in-memory map keyed by phone number, so the browser doesn't need to (and can't) round-trip it.

If the browser had to send the API key, anyone could view-source it and burn your credits. Keep the key on the server.

## Prerequisites

- Node.js 18+
- A MyOTP API key — sign up at https://myotp.app/sign-up (15 free trial credits)
- Your server's IP whitelisted in the MyOTP dashboard (use `*` for local development)

## Setup

```bash
npm install
cp .env.example .env
# edit .env and paste your API key
npm start
```

Open http://localhost:3000.

## Code walkthrough

```
src/
  server.js            -- Express app, two endpoints + static hosting
  myotp.js             -- API client (generateOtp, verifyOtp)
  phone.js             -- digits-only sanitiser
public/
  index.html           -- phone form
  verify.html          -- code form
  success.html         -- verified state
  app.js               -- tiny fetch glue for both forms
  styles.css
```

### Endpoints

- `POST /api/send-otp` — body `{ phone, channel }`. Sanitises the phone, calls `/generate_otp`, stores `phone -> message_id` in memory, returns `{ phone, channel }`.
- `POST /api/verify-otp` — body `{ phone, otp }`. Looks up `message_id` for the phone, calls `/verify_otp`, returns the verification result.

### In-memory store

`server.js` keeps a `Map<phone, { messageId, createdAt }>`. Entries auto-expire after 10 minutes (longer than the default 5-minute OTP validity). For production replace this with Redis or your session store.

## Production hardening

This example intentionally skips:

- Rate limiting — add `express-rate-limit` on `/api/send-otp` to stop abuse.
- HTTPS — terminate TLS at your reverse proxy (Nginx, Caddy, Cloudflare).
- CSRF — add `csurf` or move to same-site cookies if your form moves cross-origin.
- Persistent storage — swap the in-memory `Map` for Redis or a DB.

## License

MIT — BroadNet Technologies.
