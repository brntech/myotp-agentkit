# MyOTP.App + Django

A minimal phone-verification demo using [MyOTP.App](https://myotp.app) and Django 5. Server-rendered Django templates, the API key stays on the server, the `message_id` is stored in Django's session.

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
# edit .env and paste your API key + a strong DJANGO_SECRET_KEY
python manage.py migrate             # creates the SQLite session table
python manage.py runserver
```

Open http://localhost:8000.

## Environment variables

`python-dotenv` loads these from `.env` automatically when `settings.py` is imported:

| Variable | Purpose |
|---|---|
| `MYOTP_API_KEY` | Your MyOTP API key — never expose to the browser. |
| `MYOTP_BASE_URL` | API base URL. Defaults to `https://api.myotp.app`. |
| `DJANGO_SECRET_KEY` | Signs sessions and CSRF tokens. Generate with `python -c "import secrets; print(secrets.token_hex(32))"`. |
| `DJANGO_DEBUG` | `true`/`false`. Defaults to `true`. |

## Scaffolding from scratch

This project mirrors what `django-admin startproject myotp_demo .` followed by `python manage.py startapp verification` would give you, with the demo views/templates filled in. The only out-of-the-box additions are `requests`, `python-dotenv`, and registering the `verification` app in `INSTALLED_APPS`.

## Code walkthrough

```
manage.py
myotp_demo/                -- Django project
  settings.py              -- INSTALLED_APPS, sessions, MyOTP env config
  urls.py                  -- includes verification.urls
  wsgi.py / asgi.py
verification/              -- single app for the demo
  views.py                 -- 5 function-based views (phone_form, send_otp,
                              verify_form, verify_otp, success)
  urls.py                  -- named URL routes
  myotp_client.py          -- MyOTP client (generate_otp, verify_otp)
  phone.py                 -- digits-only sanitiser
  templates/verification/
    base.html
    index.html             -- phone form
    verify.html            -- code form
    success.html           -- verified state
  static/verification/
    styles.css
requirements.txt
```

### Routes

- `GET /` — `phone_form` renders `index.html`.
- `POST /send/` — `send_otp` sanitises the phone, calls `generate_otp`, stores `message_id`/`phone`/`channel` in `request.session`, redirects to `/verify/`.
- `GET /verify/` — `verify_form` renders `verify.html` (redirects home if no `message_id` is in session).
- `POST /verify/submit/` — `verify_otp` calls the API with the stored `message_id`, redirects to `/success/` on success.
- `GET /success/` — `success` shows the verified phone.

### Why function-based views?

Class-based views are idiomatic in modern Django, but for a 5-step linear flow (form → API → form → API → confirm) function-based views make the request lifecycle obvious and read 1:1 with the Express/Flask versions in this repo. Either is fine.

### Why `request.session`?

Django ships with a database-backed session backend by default — that's why `migrate` creates a sessions table even though we don't define any models. The `message_id` is opaque to the browser (a UUID), and Django signs the session cookie with `DJANGO_SECRET_KEY` so it can't be tampered with.

If you want to avoid the DB hit, switch to the signed-cookie backend in `settings.py`:

```python
SESSION_ENGINE = "django.contrib.sessions.backends.signed_cookies"
```

For multi-server deployments, use the cache backend with Redis.

### Why is there a CSRF token in the form?

Django's `CsrfViewMiddleware` is enabled by default and rejects unsafe methods (POST, PUT, DELETE) without a valid CSRF token. The `{% csrf_token %}` template tag injects a hidden input that the middleware validates on submit. Removing the tag would 403 every form post — leave it in. This is genuinely free CSRF protection vs. Express/Flask which need separate libraries.

## Production hardening

This example skips:

- Rate limiting — add `django-ratelimit` on the `send_otp` view to stop abuse.
- HTTPS — set `SESSION_COOKIE_SECURE = True`, `CSRF_COOKIE_SECURE = True`, and serve through a TLS-terminating proxy.
- Tighter `ALLOWED_HOSTS` — replace `["*"]` with your real hostnames.
- Persistent storage — sessions are fine; if you'd rather not write to the DB, swap to Redis (`django.contrib.sessions.backends.cache`) or signed cookies.

## License

MIT — BroadNet Technologies.
