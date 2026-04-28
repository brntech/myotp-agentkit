# MyOTP.App + Flask

A minimal phone-verification demo using [MyOTP.App](https://myotp.app) and Flask. Server-rendered Jinja2 templates, the API key stays on the server, the `message_id` is stored in Flask's signed session cookie.

## Prerequisites

- Python 3.10+
- A MyOTP API key — sign up at https://myotp.app/sign-up (15 free trial credits)
- Your server's IP whitelisted in the MyOTP dashboard (use `*` for local development)

## Setup

```bash
python -m venv .venv
source .venv/bin/activate            # Windows: .venv\Scripts\activate
pip install -r requirements.txt
cp .env.example .env
# edit .env and paste your API key + a strong FLASK_SECRET_KEY
python app.py
```

Open http://localhost:5000.

## Environment variables

`python-dotenv` loads these from `.env` automatically when `app.py` starts:

| Variable | Purpose |
|---|---|
| `MYOTP_API_KEY` | Your MyOTP API key — never expose to the browser. |
| `MYOTP_BASE_URL` | API base URL. Defaults to `https://api.myotp.app`. |
| `FLASK_SECRET_KEY` | Signs the session cookie. Generate with `python -c "import secrets; print(secrets.token_hex(32))"`. |

## Code walkthrough

```
app.py                  -- Flask app, routes, session-based message_id storage
myotp.py                -- MyOTP client (generate_otp, verify_otp)
phone.py                -- digits-only sanitiser
templates/
  base.html
  index.html            -- phone form
  verify.html           -- code form
  success.html          -- verified state
static/
  styles.css
requirements.txt
```

### Routes

- `GET /` — renders `index.html` with the phone form.
- `POST /send` — sanitises the phone, calls `generate_otp`, stores `message_id` and `phone` in `session`, redirects to `/verify`.
- `GET /verify` — renders `verify.html`.
- `POST /verify` — calls `verify_otp` with the stored `message_id`, redirects to `/success` on success.
- `GET /success` — renders `success.html` with the verified phone.

### Why `flask.session`?

Flask signs the session cookie with `FLASK_SECRET_KEY`. The `message_id` is opaque to the browser (it's just a UUID), but the signed cookie prevents tampering. For multi-server deployments use server-side sessions (`flask-session` or Redis) instead.

## Production hardening

This example skips:

- Rate limiting — add `flask-limiter` on the `/send` route.
- HTTPS — set `SESSION_COOKIE_SECURE=True` and serve through a TLS-terminating proxy.
- CSRF — add `flask-wtf` for form CSRF tokens. The current example relies on the same-origin form post and the signed session cookie.
- Persistent storage — `flask.session` is fine for this demo; production apps typically already have a session backend.

## License

MIT — BroadNet Technologies.
