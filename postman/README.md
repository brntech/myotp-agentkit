# MyOTP.App Postman collection

A Postman Collection (v2.1) and environment for the [MyOTP.App](https://myotp.app) API. Every request in `openapi-reference.yaml` is here: send, verify, extend and check OTPs over SMS, WhatsApp and Telegram, plus the account, top-up and agent signup endpoints.

Both JSON files are generated. Do not edit them by hand; edit the spec and rebuild.

| File | What it is |
|---|---|
| `MyOTP.App.postman_collection.json` | 13 requests, one per operation in the spec |
| `MyOTP.App.postman_environment.json` | `base_url`, `api_key` (secret), `phone_number`, `message_id`, `otp` |
| `build.mjs` | Converter, OpenAPI 3.0 to Collection v2.1 |
| `validate.mjs` | Structural check of the two JSON files |

## Import

1. In Postman, press **Import** (top left of the workspace).
2. Drop both JSON files in, or pick them with **files**.
3. Select the **MyOTP.App** environment in the environment picker (top right).
4. Open the environment, paste your API key into `api_key` and set `phone_number` to the number you want to test with. Digits only, country code first, no plus sign: `14155551234`.

The collection sends `X-API-Key: {{api_key}}` on every request that needs it. The three unauthenticated endpoints (`/v1/topup/quote`, `/v1/agent/register`, `/v1/agent/verify-email`) are set to **No Auth**.

## Two-request quickstart

1. Send **Generate OTP**. The test script stores the returned `message_id` in the collection variables. A code arrives on the phone.
2. Put that code in the `otp` variable and send **Verify OTP**. It already references `{{message_id}}` and `{{otp}}`.

**Extend OTP Expiry** and **Check message_id Status** use the same `{{message_id}}`, so they work right after step 1 too. **Account Identity** (`GET /me`) is the cheapest way to confirm the key and your IP allowlist before you spend a credit.

If you set `"return_otp": true` in the Generate OTP body, the test script also fills `otp` for you. The spec warns that this is not compliant in many markets, so leave it off outside testing.

## Fork from the Postman API Network

Once the collection is published to the public API Network:

1. Open the MyOTP.App collection page on the Postman API Network.
2. Press **Fork**, give the fork a label, and choose one of your workspaces.
3. Fork the environment the same way, then fill in `api_key`.
4. Later, **Pull changes** on the fork picks up new requests when the spec grows.

Until it is published, import the two files from this folder as described above.

## Rebuild

Runs in Docker, no host install. From the repository root:

```bash
docker run --rm -v "$PWD:/w" -w /w/postman node:22-alpine \
  sh -c "npm install --no-audit --no-fund && npm run build && npm test"
```

`npm run build` regenerates both files from `../openapi-reference.yaml` and then runs `validate.mjs`. `npm test` runs the converter's own tests with `node --test`.

## Example

The generated **Generate OTP** request, as sent:

```http
POST https://api.myotp.app/generate_otp
X-API-Key: <your key>
Content-Type: application/json

{
  "phone_number": "14155551234",
  "otp_length": 6,
  "otp_validity": 300,
  "channel": "sms",
  "template_order": 1,
  "brand": "MyOTP.App"
}
```
