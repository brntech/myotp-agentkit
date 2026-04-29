# MyOTP framework examples

Six self-contained "phone form → send OTP → verify code → success" demos in the most common stacks. Each one is MIT-licensed and meant to be forked or copy-pasted into your own project.

| Stack | Folder | Server pattern |
|-------|--------|----------------|
| Next.js 14 (App Router + Server Actions) | [`nextjs/`](nextjs/) | Server Actions, `lib/myotp.ts` server-only client |
| Express 4 + plain HTML | [`express/`](express/) | Express routes, in-memory `phone → message_id` map |
| Flask 3 + Jinja2 | [`flask/`](flask/) | `app.py` + signed-cookie sessions for `message_id` |
| Django 5 | [`django/`](django/) | `verification` app + Django sessions |
| Rails 7 | [`rails/`](rails/) | Skinny `VerificationController`, encrypted-cookie sessions |
| Laravel 11 | [`laravel/`](laravel/) | Form Requests + Blade templates + encrypted-cookie sessions |

## Which one to start from

- **Building a new app**: pick whichever framework you're already in. They're all the same shape — the differences are idiomatic to the framework, not to MyOTP.
- **Just trying MyOTP out**: `nextjs/` or `express/` boot fastest (`npm install && npm run dev`).
- **Vibe-coding with an agent**: any of these will do. The agent reads the framework's `README.md` for the integration shape; the actual MyOTP code is ~50 lines per stack.

## Common shape

All six examples follow the same structure:

1. **`MYOTP_API_KEY` is server-only.** Read from env at request time. Never sent to the browser.
2. **Two endpoints**: `POST /generate_otp` to send the code, `POST /verify_otp` to check it. No version prefix.
3. **`X-API-Key: <key>` header.** Not `Authorization: Bearer`.
4. **Phone numbers are digits-only**, no `+` or leading 0. Each example includes a small sanitizer that strips characters you'd typically paste in.
5. **`message_id`** comes back from `/generate_otp`; pass it to `/verify_otp` (or pass `phone_number` instead). Each example stores it in whatever the framework's natural session shape is — signed cookie, in-memory map, encrypted-cookie session.
6. **Channel selection**: `sms` (default), `whatsapp`, or `telegram`. The browser picks; the server forwards the choice.

## Setup checklist (every example)

1. Sign up at [myotp.app/sign-up/](https://myotp.app/sign-up/) — ~60 seconds, 15 free trial credits, no card.
2. Generate an API key from the dashboard.
3. Whitelist your dev IP (`*` is fine for local development).
4. Drop the key into `.env` (or your framework's secret store) as `MYOTP_API_KEY`.
5. `npm install` / `pip install -r requirements.txt` / `bundle install` / `composer install` per framework.
6. Run the dev server. Open the app. Enter your phone, get the code, verify.

## Going to production

The examples are intentionally minimal — sessions in memory, no rate limiting, no retry policy. Before deploying:

- Add real session storage (Redis, the framework's session store, your own DB)
- Rate-limit the `send` endpoint (the public form is the obvious abuse vector)
- Pin your outbound IP and whitelist it in MyOTP (or stay on `*` and rotate the API key periodically)
- Decide your re-send policy — MyOTP returns 409 if an OTP is already active for that phone; either wait for it to expire (default 5 min) or pass `force_send: "true"` to issue a new one

## Where to ask for help

- Issues / questions: open an issue at [github.com/brntech/myotp-agentkit](https://github.com/brntech/myotp-agentkit/issues)
- API docs: [myotp.app/api-reference/](https://myotp.app/api-reference/)
- LLM-friendly: [myotp.app/llms-full.txt](https://myotp.app/llms-full.txt) — paste this into your AI agent's context so it can wire MyOTP into whatever you're vibe-coding next

## License

MIT. Fork freely, no attribution required.
