# MyOTP.App for the Auth0 Marketplace

The MyOTP.App Action for Auth0's Send Phone Message flow (SMS multi-factor authentication), in the layout of Auth0's [Send Phone Message integration template](https://github.com/Auth0-Marketplace/TEMPLATE-action-phone-message).

Auth0 generates and checks the code. The Action hands it to MyOTP, which delivers it by SMS, WhatsApp or Telegram.

| Path | Purpose |
|---|---|
| `integration/integration.action.js` | The Action (`onExecuteSendPhoneMessage`). No npm dependencies. |
| `integration/integration.action.spec.js` | Jest tests. |
| `integration/configuration.json` | `MYOTP_API_KEY` secret, `MYOTP_CHANNEL` setting. |
| `integration/installation_guide.md` | The install steps shown on the Marketplace listing. |
| `media/` | Logo (256x256) and three column images (460x260). |

For the newer Custom Phone Provider (passwordless SMS and MFA from one Action), see the [parent folder](../).

## Test

```bash
make test   # Auth0's test image: Jest on the current Node LTS plus the configuration schema check
make lint
```

## Package

```bash
make zip    # writes integration-action.zip (integration/ + media/)
```
