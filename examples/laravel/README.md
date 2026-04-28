# MyOTP.App + Laravel

A minimal phone-verification demo using [MyOTP.App](https://myotp.app) and Laravel 11. Server-rendered Blade templates, the API key stays on the server, the `message_id` is stored in Laravel's encrypted session cookie.

## Prerequisites

- PHP 8.2+
- Composer
- A MyOTP API key — sign up at https://myotp.app/sign-up (15 free trial credits)
- Your server's IP whitelisted in the MyOTP dashboard (use `*` for local development)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate          # generates APP_KEY
# edit .env and paste your MYOTP_API_KEY
php artisan serve
```

Open http://localhost:8000.

## Environment variables

Laravel auto-loads `.env` into `$_ENV` at boot. The values are read by the config files in `config/` and cached when you run `php artisan config:cache`.

| Variable | Purpose |
|---|---|
| `MYOTP_API_KEY` | Your MyOTP API key — never expose to the browser. Read by `config/services.php`. |
| `MYOTP_BASE_URL` | API base URL. Defaults to `https://api.myotp.app`. |
| `APP_KEY` | Encrypts session cookies and signed URLs. Generate with `php artisan key:generate`. |
| `SESSION_ENCRYPT` | `true` (default in this example) so the `message_id` is unreadable client-side. |

### Why `config()` and not `env()` in app code?

Calling `env('MYOTP_API_KEY')` directly inside controllers, services, or Blade looks fine in development — but it returns `null` in production after `php artisan config:cache`, because the cached config file no longer reads `.env`. The Laravel convention is:

1. Reference `env()` only inside `config/*.php` files.
2. Reference `config('services.myotp.key')` everywhere else.

That's why `App\Services\MyOtpClient` is constructed in `AppServiceProvider` from `config('services.myotp.key')`, and the controller never touches `env` directly.

## Code walkthrough

```
app/
  Http/
    Controllers/
      Controller.php
      VerificationController.php   -- 5 actions: phoneForm, sendOtp, verifyForm,
                                        verifyOtp, success
    Requests/
      SendOtpRequest.php            -- form-request validation for /send
      VerifyOtpRequest.php          -- form-request validation for /verify
  Services/
    MyOtpClient.php                 -- API client (uses Http facade)
  Support/
    PhoneSanitizer.php              -- digits-only sanitiser
  Exceptions/
    MyOtpException.php              -- typed error with status + message
  Providers/
    AppServiceProvider.php          -- binds MyOtpClient as a singleton
resources/views/
  layouts/app.blade.php
  verification/
    index.blade.php                 -- phone form
    verify.blade.php                -- code form
    success.blade.php               -- verified state
public/
  index.php
  css/app.css
config/
  app.php
  services.php                      -- defines services.myotp.{key,base_url}
  session.php
routes/
  web.php
bootstrap/
  app.php                           -- Laravel 11 application bootstrap
composer.json
```

### Routes

```php
Route::get ('/',        [VerificationController::class, 'phoneForm'])->name('phone.form');
Route::post('/send',    [VerificationController::class, 'sendOtp']) ->name('phone.send');
Route::get ('/verify',  [VerificationController::class, 'verifyForm'])->name('verify.form');
Route::post('/verify',  [VerificationController::class, 'verifyOtp'])->name('verify.submit');
Route::get ('/success', [VerificationController::class, 'success']) ->name('verify.success');
```

### CSRF

Laravel's default middleware stack includes `VerifyCsrfToken`. The `@csrf` Blade directive in each form emits a hidden `_token` input that the middleware validates on every non-GET request. Don't remove it.

### Session storage

`config/session.php` is set to `cookie` driver with `SESSION_ENCRYPT=true` (Laravel 11 default). The whole session payload — including the `message_id` — is encrypted with `APP_KEY` before it's sent to the browser, and the cookie is `httpOnly` and `samesite=lax`. The browser cannot read or modify it.

#### Session vs cache storage tradeoff

For this single-step verify flow, the cookie session is ideal: state lives on the user's browser, no server memory cost, sticks to that user even across server restarts.

If you'd rather not put the `message_id` in a cookie at all (some compliance environments forbid it), switch to `SESSION_DRIVER=redis` (or `database`). The user still gets a session ID cookie, but the actual data — including the `message_id` — lives server-side. Tradeoffs:

|   | Cookie (this example) | Redis / database |
|---|---|---|
| Server state | None | One round-trip per request |
| Multi-server sticky | Not needed | Not needed |
| Max payload | 4 KB | Effectively unlimited |
| Server-side invalidation | Not possible | `Session::flush()` works globally |

For a 36-character UUID + a phone number + a channel string, cookie is plenty.

## Production hardening

This example skips:

- Rate limiting — wrap the `/send` route with `throttle:5,1` to allow 5 requests per minute per IP.
- HTTPS — set `SESSION_SECURE_COOKIE=true` and serve through a TLS-terminating proxy.
- Persistent storage — switch `SESSION_DRIVER` to `redis` if you want server-side sessions.
- Pinning the IP whitelist on MyOTP — replace `*` with your egress IP in production.

## License

MIT — BroadNet Technologies.
