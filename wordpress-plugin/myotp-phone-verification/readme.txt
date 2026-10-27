=== MyOTP Phone Verification ===
Contributors: rayasoren
Tags: otp, sms verification, phone verification, woocommerce, whatsapp
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verify phone numbers by SMS, WhatsApp or Telegram code at WooCommerce checkout, on the registration form, or anywhere with a shortcode.

== Description ==

MyOTP Phone Verification sends a one-time code to a visitor's phone and checks it before they can continue. It uses the MyOTP.App API (https://myotp.app), one key for SMS, WhatsApp and Telegram.

What it does:

* WooCommerce checkout: a Send code button under the billing phone. The order cannot be placed until the billing number is verified. Optionally only for guests.
* Apple Pay, Google Pay and other express wallet buttons: choose to allow those orders without a code, to hold them until the shopper verifies after paying, or to block them.
* WordPress registration: a phone field with the same flow on wp-login.php?action=register. The verified number is saved as user meta `myotp_verified_phone`.
* Shortcode `[myotp_verify]`: the same widget on any page. Fires a `myotp:verified` event on `document` with the number in `event.detail.phone`.
* Settings page (Settings > MyOTP): API key, channel, code length, validity, brand, and a Send test code button.

How it stays safe:

* The API key never leaves the server. The browser talks to admin-ajax.php only.
* Every AJAX call carries a nonce. Admin actions check `manage_options`.
* Send limits, enforced together with atomic counters: 5 codes per visitor, 10 per client IP, 3 per destination number, each per 10 minutes, plus a site-wide ceiling (default 100, setting and `myotp_pv_site_hourly_cap` filter). The site-wide count uses a fixed one-hour window that starts at the first send, so up to twice the ceiling can go out across a window boundary. It exists to bound what an attacker with many addresses and many numbers can make the site spend. A code that was not billed (provider answered 409 or a server error) is not counted against it.
* A visitor can only verify the code they requested: the challenge reference from the provider is stored with the pending record and sent back on every check. If the provider still has an active code that this visitor did not request, a fresh code is sent for this visitor instead (still within every send limit).
* 5 wrong codes put this visitor on a 15-minute cooldown for that number. Sending and checking are both refused for that visitor while it lasts; other visitors are not affected, and nothing is keyed on the phone number alone, so nobody can lock a number's owner out. Only a provider answer of "wrong code" counts; an expired code, a network failure or a provider error never does.
* A verification is valid for 30 minutes and is claimed by exactly one checkout or one registration at validation time, then consumed when the order or account exists. If checkout fails after validation (a declined payment, for example) the visitor verifies again.
* Phone numbers are reduced to digits before they are sent. Leading zeros are kept.

Both the classic (shortcode) checkout and the block checkout are supported. On the block checkout the plugin adds its widget under the phone field with a small script (no block to add in the editor) and validates when the order is placed. The block checkout's phone field must be shown: with the field hidden there is no number to verify and the order is refused.

Express wallet buttons (Apple Pay, Google Pay, Link, Amazon Pay) from WooPayments and WooCommerce Stripe Payment Gateway place the order without the checkout form, so the shopper never sees the code box. Settings > MyOTP has one setting for them, "Apple Pay / Google Pay and other express wallet orders", with three choices:

* Allow without a code (the default, also after upgrading): the wallet has already confirmed the payer and the order is paid. The order is placed as usual, is not marked as verified, and gets an order note saying it was an express wallet payment placed without phone verification.
* Ask for the code after payment: the order is accepted, and once paid it goes on hold instead of processing, with an order note. The order confirmation page (and My account > View order for a logged-in customer) shows a code box for the billing phone on the order. When the shopper verifies that number, the order is marked as verified like any other and moves on to the status the payment gateway set (usually processing). Codes use the same send limits as checkout. Nothing runs on a timer: an order that is never verified stays on hold for you.
* Block: the order is refused unless the shopper verified the same number at checkout first, which is how 1.0.0 behaved.

Other gateways can be added with the `myotp_pv_express_wallet_gateways` filter (gateway id => list of request fields that mark a wallet order) and `myotp_pv_express_wallet_types`.

= External service =

This plugin sends the phone number a visitor enters to the MyOTP.App API at https://api.myotp.app to deliver a one-time code and to check the code the visitor types. No other data is sent. MyOTP.App privacy policy: https://myotp.app/privacy-policy/. Terms: https://www.broadnet.me/legal/terms-of-service.

= Data stored on your site =

* A cookie `myotp_pv_sid` (random id, one day) so a guest's verification can be tied to their browser.
* Rows in the options table (`myotp_pv_kv_` prefix, not autoloaded): rate-limit counters (a row lives for one window after the last send it counted: 10 minutes, site-wide 1 hour), the pending number with its code reference and attempt count (kept for the configured code validity, at most 4 hours), a 15-minute per-visitor cooldown row after five wrong codes, and the verified number (30 minutes). Expired rows are removed on the next read of that row and by a daily WP-Cron sweep (`myotp_pv_sweep`). WP-Cron runs on page visits, so on a quiet site the sweep can run later than scheduled.
* Order meta `_myotp_verified_phone` on each verified WooCommerce order. With "Ask for the code after payment", a held order also carries `_myotp_pv_awaiting` (the wallet type) and `_myotp_pv_restore_status` (the status to restore) until it is verified, and the store keeps that order's pending code, attempt count and send counter for the code validity.
* User meta `myotp_verified_phone` on each account registered through the verified form.

Uninstalling removes the settings, the scheduled sweep, the counters, the pending records and the verified records. Order meta and user meta are part of your customer records and are kept. The plugin registers suggested text for your privacy policy under Settings > Privacy.

== Installation ==

1. Upload the `myotp-phone-verification` folder to `/wp-content/plugins/`, or upload the zip from Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Sign up at https://myotp.app/sign-up/ and copy an API key from User API Keys in the dashboard.
4. Go to Settings > MyOTP, paste the key, pick a channel and save.
5. Send a test code to your own number from the same page.

== Frequently Asked Questions ==

= What format do phone numbers need? =

Country code first, digits only, no plus sign. 14155550123, not +1 (415) 555-0123. The plugin strips spaces, dashes and the plus sign before sending.

= Does it work with the WooCommerce block checkout? =

Yes, from 1.1.0. The Send code button appears under the phone field and the order is refused until that number is verified, same as the classic `[woocommerce_checkout]` page. For a returning shopper whose saved address is shown as a card with an Edit link, the Send code button sits right under that card and sends to the saved phone number. If the saved address has no phone number, pressing Send code (or a refused Place order) opens the address form at the phone field. Keep the phone field visible in the checkout block; with it hidden there is nothing to verify.

= Can shoppers pay with Apple Pay or Google Pay? =

Yes. Wallet buttons skip the checkout form, so pick what happens to those orders under "Apple Pay / Google Pay and other express wallet orders" in Settings > MyOTP: allow them without a code (the default), hold them until the shopper verifies the order's phone after paying, or block them. The plugin recognises wallet orders from WooPayments and WooCommerce Stripe Payment Gateway; any other request goes through the normal code check.

= Can a shopper skip the code by pretending to use a wallet? =

Not with another payment method. A wallet order is recognised only when the chosen payment method is one of the wallet gateways (WooPayments or WooCommerce Stripe Payment Gateway) and the request carries that gateway's wallet marker. The same marker sent with cash on delivery, bank transfer or any other gateway is ignored and the code is required.

The plain limit: those markers come from the shopper's browser. Someone who edits the request by hand can send a wallet marker while paying with an ordinary card through WooPayments or Stripe, and the order is then treated as a wallet order (placed without a code, or held until verified, depending on the setting). They still had to pay through that gateway, with the gateway's own fraud checks. If you need every order verified with no exception, choose "Ask for the code after payment" or "Block".

= Why is the phone field required at checkout now? =

A number that is not there cannot be verified. While verification is on (and, with "Only for guests", for guests), the plugin shows WooCommerce's checkout phone field as required on both the block and the classic checkout, even when WooCommerce's own setting says optional. Your WooCommerce setting is not changed; Settings > MyOTP says when it is being overridden.

= Can logged-in customers skip verification? =

Yes. Tick "Only for guests" under WooCommerce checkout in Settings > MyOTP.

= How do I react to a successful verification from the shortcode? =

Listen for the event:

`document.addEventListener('myotp:verified', function (e) { console.log(e.detail.phone); });`

= I am behind a reverse proxy or CDN. Does the per-IP limit work? =

The plugin reads REMOTE_ADDR only, because forwarding headers can be forged by the client. Behind a proxy that address may be the proxy itself, so every visitor shares one per-IP bucket and the site-wide hourly ceiling is the real backstop. If your host guarantees a trusted header, return the real address from the `myotp_pv_client_ip` filter.

= A shopper says the code never arrives. Where do I look? =

Shoppers only ever see a short message asking them to try again or contact the shop; the verification service's own error text is not shown to them. The detail goes to WooCommerce > Status > Logs (source `myotp-phone-verification`) and to a notice in the WordPress admin for a day, with phone numbers removed. A common cause is the Brand setting: it must be 3 to 16 letters or digits, with no spaces.

= Does each test send cost credits? =

Yes. A test send is a real send.

= Where does the API key live? =

In the `myotp_pv_options` option, on the server only. It is shown masked on the settings page and removed on uninstall.

== Screenshots ==

1. Settings > MyOTP with the test send.
2. Checkout billing phone with the Send code button.
3. Registration form with the phone field.

== Changelog ==

= 1.1.0 =
* WooCommerce block checkout support (Store API validation and a Send code widget under the phone field).
* New setting "Apple Pay / Google Pay and other express wallet orders": allow without a code (default), ask for the code after payment (the paid order is held until the shopper verifies on the order confirmation page), or block. Wallet orders are recognised from WooPayments and WooCommerce Stripe Payment Gateway only when the wallet gateway is the chosen payment method.
* The code box shows one line saying a code will be sent, and the code field appears only after a code was sent.
* After a code is sent, Send again counts down 30 seconds before it can be pressed (the send limits are unchanged).
* A verified order gets an order note naming the last four digits of the verified phone.
* The checkout phone field is shown as required while verification is on (WooCommerce's own setting is left as it is).
* Shoppers never see the verification service's raw error text; the detail goes to the WooCommerce log and an admin notice, without phone numbers. The Brand setting accepts only 3 to 16 letters or digits and says so when a value is refused.

= 1.0.0 =
* First release. WooCommerce classic checkout, registration form, `[myotp_verify]` shortcode, settings page with test send.

== Upgrade Notice ==

= 1.1.0 =
Adds WooCommerce block checkout support. Apple Pay and Google Pay orders are now allowed without a code by default; see the new express wallet setting to hold them for verification after payment or to block them as before.

= 1.0.0 =
First release.
