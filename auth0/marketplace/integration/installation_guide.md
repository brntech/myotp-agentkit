The MyOTP.App integration delivers the one-time codes for SMS multi-factor authentication. Auth0 still generates and checks every code. MyOTP carries it to the user's phone over SMS, WhatsApp or Telegram, from one API key.

## Prerequisites

1. An Auth0 account and tenant. [Sign up for free](https://auth0.com/signup).
2. A MyOTP.App account. [Sign up](https://myotp.app/sign-up/) and verify your email and phone number. New accounts get 15 free codes without a card.

## Set up MyOTP.App

To configure the integration with MyOTP.App:

1. Sign in to the [MyOTP.App dashboard](https://myotp.app/sms-dashboard/).
2. Go to **User API Keys** and create a key. Copy it; you will paste it into Auth0 below.
3. Allow Auth0 to call MyOTP. A MyOTP account only accepts API requests from the IP addresses on its allowlist. Auth0 lists the addresses its Actions call from, per region, in [Auth0's IP addresses for allow lists](https://auth0.com/docs/secure/security-guidance/data-security/allowlist). Add each address for your tenant's region on the [IP Whitelist](https://myotp.app/ip-whitelist/) page of the MyOTP dashboard.
4. Make sure the account has credits for the countries your users are in. Each message is billed at the destination country's rate, listed at [myotp.app/pricing](https://myotp.app/pricing/).

## Add the Auth0 Action

1. Select **Add Integration** (at the top of this page).
1. Read the necessary access requirements, and select **Continue**.
1. Configure the integration using the following fields:
   * **Delivery channel**: how MyOTP delivers the code. **SMS** (default), **WhatsApp** or **Telegram**. WhatsApp and Telegram reach the account registered to the user's phone number.
   * **MyOTP API key**: the key you created in the MyOTP dashboard. It is stored encrypted.
1. Add the integration to your Library by selecting **Create**.
1. In the modal that appears, select the **Add to flow** link.
1. Drag the Action into the desired location in the flow.
1. Select **Apply Changes**.

## Activate custom SMS factor

To use the SMS factor, your tenant needs to have MFA enabled globally or required for specific contexts using rules. To learn how to enable the MFA feature, see:

* [Enable MFA](https://auth0.com/docs/secure/multi-factor-authentication/enable-mfa)
* [Customize MFA](https://auth0.com/docs/secure/multi-factor-authentication/customize-mfa)

Finally, configure the SMS factor to use the custom code and test the MFA flow.

**Note:** Once you complete the steps below, Auth0 will begin using this factor for MFA during login. Before activating the integration in production, make sure you have configured all components correctly, and [install and verify this Action on a test tenant](https://auth0.com/docs/get-started/auth0-overview/create-tenants/set-up-multiple-environments).

1. Go to **[Dashboard > Security > Multi-factor Auth](https://manage.auth0.com/select-tenant?path=/mfa)**, and select **Phone Message**.
1. In the modal that appears, select **Custom** for the delivery provider, and make your preferred adjustments to the templates. When complete, select **Save** and close the modal.
1. To begin using this factor, enable the SMS factor using the toggle switch.

The integration delivers text messages only. If **Voice** is offered as a delivery method, voice messages fail with a logged reason.

## Test MFA flow

Trigger an MFA flow and verify that everything works as intended. The code arrives on the channel you chose, and the send appears in the MyOTP dashboard's OTP history.

## Troubleshoot

If you do not receive the text message, look in the [tenant logs](https://auth0.com/docs/deploy-monitor/logs) for a failed SMS log entry. To learn which event types to search, see the [Log Event Type Code list](https://auth0.com/docs/deploy-monitor/logs/log-event-type-codes), or you can use the Filter control to find MFA errors.

The log entry carries MyOTP's answer:

* `MyOTP responded 403: Access from this IP address is not allowed`: the message names the address Auth0 called from. Add it to your allowed IP addresses (step 3 of **Set up MyOTP.App**).
* `MyOTP responded 401`: the API key is wrong or was deleted. Check the **MyOTP API key** field.
* `MyOTP responded 403: Insufficient balance`: the account is out of credits. Top up in the MyOTP dashboard.
* `MyOTP responded 400` naming a country: that destination is not enabled on your account. Write to [sales@myotp.app](mailto:sales@myotp.app) with the country and channel.

**Make sure that:**

* The Action is in the Send Phone Message flow.
* The secrets match the ones you created in the steps above.
* Your MyOTP.App account is active (not suspended).
* Your phone number is formatted using the [E.164 format](https://en.wikipedia.org/wiki/E.164).
