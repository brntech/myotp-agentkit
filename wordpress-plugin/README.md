# MyOTP Phone Verification for WordPress

A WordPress plugin that verifies phone numbers with a one-time code over SMS, WhatsApp or Telegram, using the [MyOTP.App](https://myotp.app) API. Source for the `myotp-phone-verification` plugin lives in this folder.

## What it does

- **WooCommerce checkout.** A Send code button under the billing phone. The order is blocked with a notice until the billing number is verified. Optionally only for guests. Classic (shortcode) checkout only in this version.
- **WordPress registration.** A phone field on `wp-login.php?action=register` with the same flow. The verified number is saved as user meta `myotp_verified_phone`.
- **Shortcode.** `[myotp_verify]` renders the widget anywhere. On success it fires `myotp:verified` on `document` with `event.detail.phone`.
- **Settings page.** Settings > MyOTP: API key (masked), channel, code length (4 to 8), validity in seconds, optional brand, and a Send test code button.

The API key stays on the server. The browser only calls `admin-ajax.php` and every call carries a nonce. Sends are capped with atomic counters on three dimensions at once: 5 per visitor, 10 per client IP, 3 per destination number, each per 10 minutes. Five wrong codes discard the pending code. A verification lasts 30 minutes and is consumed when the order or account is created.

## Get a key

Sign up at [myotp.app/sign-up](https://myotp.app/sign-up/) (15 free trial credits, no card). Copy a key from User API Keys in the dashboard.

## Install from zip

Build the zip from this folder:

```bash
cd wordpress-plugin
zip -r myotp-phone-verification.zip myotp-phone-verification -x '*.DS_Store'
```

Then in WordPress: Plugins > Add New > Upload Plugin, choose the zip, activate, and open Settings > MyOTP to paste the key.

Or copy the `myotp-phone-verification` folder into `wp-content/plugins/` directly.

Requires WordPress 6.0+ and PHP 7.4+. WooCommerce is optional.

## Phone number format

Country code first, digits only, no plus sign: `14155551234`. The plugin strips `+`, spaces, dashes, dots and brackets before sending, and keeps leading zeros untouched. The API rejects numbers that start with 0, so ask visitors for the international form.

## Layout

```
myotp-phone-verification/
  myotp-phone-verification.php   plugin header, constants, boot
  includes/functions.php         pure helpers (normalisation, atomic counters, attempt lockout, sanitisation)
  includes/class-myotp-pv-store.php        options-table store with INSERT IGNORE add() and guarded UPDATE cas()
  includes/class-myotp-pv-api.php          wp_remote_post to /generate_otp and /verify_otp
  includes/class-myotp-pv-session.php      per-visitor state (counters and pending in the store, verified in WC session or transient)
  includes/class-myotp-pv-widget.php       widget markup and assets
  includes/class-myotp-pv-ajax.php         send, verify, admin test
  includes/class-myotp-pv-settings.php     Settings > MyOTP
  includes/class-myotp-pv-shortcode.php    [myotp_verify]
  includes/class-myotp-pv-registration.php wp-login.php?action=register
  includes/class-myotp-pv-woocommerce.php  classic checkout
  assets/js, assets/css, languages/, readme.txt, uninstall.php
tests/run.php                     plain PHP tests: pure helpers, then the AJAX, registration and checkout handlers against tests/wp-stubs.php
```

## Test

Lint and unit tests run in Docker, no composer:

```bash
docker run --rm -v "$PWD:/w" -w /w/wordpress-plugin php:8.2-cli sh -c \
  'for f in $(find . -name "*.php"); do php -l $f || exit 1; done && php tests/run.php'
```

## Example

Put the widget on a page and react to it:

```
[myotp_verify label="Your mobile number" context="signup"]
```

```html
<script>
document.addEventListener('myotp:verified', function (e) {
  // e.detail.phone is digits only, e.g. "14155551234"
  document.querySelector('#continue').disabled = false;
});
</script>
```
