=== MyOTP Phone Verification ===
Contributors: myotp
Tags: otp, sms verification, phone verification, woocommerce, whatsapp
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verify phone numbers by SMS, WhatsApp or Telegram code at WooCommerce checkout, on the registration form, or anywhere with a shortcode.

== Description ==

MyOTP Phone Verification sends a one-time code to a visitor's phone and checks it before they can continue. It uses the MyOTP.App API (https://myotp.app), one key for SMS, WhatsApp and Telegram.

What it does:

* WooCommerce checkout: a Send code button under the billing phone. The order cannot be placed until the billing number is verified. Optionally only for guests.
* WordPress registration: a phone field with the same flow on wp-login.php?action=register. The verified number is saved as user meta `myotp_verified_phone`.
* Shortcode `[myotp_verify]`: the same widget on any page. Fires a `myotp:verified` event on `document` with the number in `event.detail.phone`.
* Settings page (Settings > MyOTP): API key, channel, code length, validity, brand, and a Send test code button.

How it stays safe:

* The API key never leaves the server. The browser talks to admin-ajax.php only.
* Every AJAX call carries a nonce. Admin actions check `manage_options`.
* Send limits, enforced together with atomic counters: 5 codes per visitor, 10 per client IP, 3 per destination number, each per 10 minutes. An unexpired code for the same number is reused rather than sent again.
* 5 wrong codes discard the pending code; the visitor must request a new one.
* A verification is valid for 30 minutes and is consumed when the order or the account is created.
* Phone numbers are reduced to digits before they are sent. Leading zeros are kept.

Not in this version: the WooCommerce block checkout. The classic shortcode checkout is supported.

= External service =

This plugin sends the phone number a visitor enters to the MyOTP.App API at https://api.myotp.app to deliver a one-time code and to check the code the visitor types. No other data is sent. MyOTP.App privacy policy: https://myotp.app/privacy-policy/. Terms: https://myotp.app/term-condition/.

= Data stored on your site =

* A cookie `myotp_pv_sid` (random id, one day) so a guest's verification can be tied to their browser.
* Short-lived rows in the options table: rate-limit counters (10 minutes), the pending number with its code reference and attempt count (up to one hour), and the verified number (30 minutes, in the WooCommerce session when one exists, otherwise a transient).
* Order meta `_myotp_verified_phone` on each verified WooCommerce order.
* User meta `myotp_verified_phone` on each account registered through the verified form.

Uninstalling removes the settings, the counters, the pending records and the transients. Order meta and user meta are part of your customer records and are kept. The plugin registers suggested text for your privacy policy under Settings > Privacy.

== Installation ==

1. Upload the `myotp-phone-verification` folder to `/wp-content/plugins/`, or upload the zip from Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Sign up at https://myotp.app/sign-up/ and copy an API key from User API Keys in the dashboard.
4. Go to Settings > MyOTP, paste the key, pick a channel and save.
5. Send a test code to your own number from the same page.

== Frequently Asked Questions ==

= What format do phone numbers need? =

Country code first, digits only, no plus sign. 14155551234, not +1 (415) 555-1234. The plugin strips spaces, dashes and the plus sign before sending.

= Does it work with the WooCommerce block checkout? =

No. This version hooks the classic checkout (the `[woocommerce_checkout]` shortcode page). Block checkout support is planned.

= Can logged-in customers skip verification? =

Yes. Tick "Only for guests" under WooCommerce checkout in Settings > MyOTP.

= How do I react to a successful verification from the shortcode? =

Listen for the event:

`document.addEventListener('myotp:verified', function (e) { console.log(e.detail.phone); });`

= Does each test send cost credits? =

Yes. A test send is a real send.

= Where does the API key live? =

In the `myotp_pv_options` option, on the server only. It is shown masked on the settings page and removed on uninstall.

== Screenshots ==

1. Settings > MyOTP with the test send.
2. Checkout billing phone with the Send code button.
3. Registration form with the phone field.

== Changelog ==

= 1.0.0 =
* First release. WooCommerce classic checkout, registration form, `[myotp_verify]` shortcode, settings page with test send.

== Upgrade Notice ==

= 1.0.0 =
First release.
