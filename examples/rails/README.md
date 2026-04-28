# MyOTP.App + Rails

A minimal phone-verification demo using [MyOTP.App](https://myotp.app) and Rails 7. Server-rendered ERB templates, the API key stays on the server, the `message_id` is stored in Rails' encrypted session cookie.

## Prerequisites

- Ruby 3.2+
- A MyOTP API key — sign up at https://myotp.app/sign-up (15 free trial credits)
- Your server's IP whitelisted in the MyOTP dashboard (use `*` for local development)

## Setup

```bash
bundle install
cp .env.example .env
# edit .env and paste your API key + a strong SECRET_KEY_BASE
bin/rails server
```

Open http://localhost:3000.

## Environment variables

`dotenv-rails` loads `.env` into `ENV` automatically in development. In production, set them through your platform's env config.

| Variable | Purpose |
|---|---|
| `MYOTP_API_KEY` | Your MyOTP API key — never expose to the browser. |
| `MYOTP_BASE_URL` | API base URL. Defaults to `https://api.myotp.app`. |
| `SECRET_KEY_BASE` | Signs and encrypts the session cookie. Generate with `bin/rails secret`. |

The API key is read once at boot in `config/application.rb` and exposed to the rest of the app via `Rails.configuration.x.myotp.api_key` (Rails' standard `config.x` namespace for custom config). Controllers and lib code never call `ENV[]` directly.

## Scaffolding from scratch

This is roughly what `rails new myotp-demo --minimal` produces (no ActiveRecord, ActionMailer, ActionCable, ActiveJob, ActiveStorage, jbuilder), with one controller, one route group, dotenv-rails added, and the verify-flow filled in.

## Code walkthrough

```
app/
  controllers/
    application_controller.rb     -- CSRF protection (default Rails)
    verification_controller.rb    -- skinny controller, 5 actions
  lib/
    myotp_client.rb               -- MyOTP API client (Net::HTTP)
    phone_sanitizer.rb            -- digits-only sanitiser
  views/
    layouts/application.html.erb
    verification/
      index.html.erb              -- phone form
      verify_form.html.erb        -- code form
      success.html.erb            -- verified state
  assets/
    stylesheets/application.css
config/
  application.rb                  -- reads MyOTP env into config.x.myotp
  routes.rb                       -- named routes
  initializers/session_store.rb   -- cookie-based session
Gemfile                           -- rails, puma, dotenv-rails
```

### Routes

```ruby
root "verification#index"
get  "/verify",  to: "verification#verify_form", as: :verify_form
post "/send",    to: "verification#send_otp",    as: :send_otp
post "/verify",  to: "verification#verify",      as: :verify_otp
get  "/success", to: "verification#success",     as: :success
```

- `GET /` — renders the phone form.
- `POST /send` — sanitises the phone, calls `generate_otp`, stashes `message_id`/`phone`/`channel` in `session`, redirects to `/verify`.
- `GET /verify` — renders the code form (redirects home if no `message_id` is in session).
- `POST /verify` — calls `verify_otp` with the stored `message_id`, redirects to `/success` on success.
- `GET /success` — shows the verified phone.

### CSRF

Rails handles CSRF automatically. `protect_from_forgery` is on by default in `ApplicationController`, and `form_with` injects an authenticity token into every non-GET form. You don't need to do anything — but never replace `form_with` with a hand-rolled `<form>` tag without including `<%= hidden_field_tag :authenticity_token, form_authenticity_token %>`.

### Where does the API key live?

In server-side environment variables only. We load it once at boot in `config/application.rb`:

```ruby
config.x.myotp.api_key = ENV["MYOTP_API_KEY"].to_s
config.x.myotp.base_url = ENV.fetch("MYOTP_BASE_URL", "https://api.myotp.app")
```

…and `MyotpClient.post` reads from `Rails.configuration.x.myotp.*`. This keeps `ENV[...]` calls out of controllers/lib and makes the credential easy to mock in tests.

### Why `session` and not the DB?

Rails' default `cookie_store` encrypts and signs the session payload with `SECRET_KEY_BASE`. The `message_id` is opaque to the browser (a UUID), and the encryption prevents tampering. For multi-server deployments where you want to invalidate sessions server-side, switch to `:active_record_store` or `:cache_store`.

## Production hardening

This example skips:

- Rate limiting — add `rack-attack` and throttle `POST /send`.
- HTTPS — set `config.force_ssl = true` (already on in `production.rb`) and serve through a TLS-terminating proxy.
- Persistent storage — cookie sessions are fine for this flow; if you'd rather server-side sessions, swap the store in `config/initializers/session_store.rb`.
- Tighter CSP — Rails ships a CSP scaffold in `config/initializers/content_security_policy.rb`.

## License

MIT — BroadNet Technologies.
