# MyOTP.App custom app for Make

A [Make](https://www.make.com) custom app definition for the [MyOTP.App](https://myotp.app) API. One API key connection, five action modules and a Make an API Call module, all generated from `openapi-reference.yaml` and checked against it by tests.

| Module | Calls | Output |
|---|---|---|
| Send OTP | `POST /generate_otp` | message_id, status, message, date_sent, expires_at, cost, otp |
| Verify OTP | `POST /verify_otp` | status (success, failed, expired), message |
| Extend OTP | `POST /extend_otp` | status, message, expires_at |
| Check OTP Status | `POST /check_otp_status` | DLR, is_active, expires_at, message |
| Get Account | `GET /me` | email |
| Make an API Call | any path under `https://api.myotp.app` | body, headers, statusCode |

## Layout

Make stores an app as a set of JSON sections. This folder keeps one file per section so each can be pasted into the matching tab of the Make app editor, or pushed with the Make CLI.

```
myotp/
  app.json                      name, label, description, theme (listing metadata)
  base.imljson                  Base tab: baseUrl, X-API-Key header, error handling, log sanitization
  groups.imljson                Groups tab
  connections/myotp/
    metadata.json               type: apikey
    parameters.imljson          the apiKey field
    api.imljson                 Communication: validates the key with GET /me
  modules/<name>/
    metadata.json               label, description, type
    api.imljson                 Communication
    expect.imljson              Mappable parameters
    interface.imljson           Interface (output)
    samples.imljson             Samples
```

The files are plain JSON. Make's editor also accepts comments in `.imljson`; none are used here so every file parses with `JSON.parse`.

## Import into Make

You need a Make account with custom apps enabled (any plan). Three ways, pick one.

### A. Make web editor

1. In Make, open **Custom apps** in the left menu and press **Create a new app**.
2. Fill in the form from `myotp/app.json`: name `myotp`, label `MyOTP.App`, the description, theme `#1d4ed8`, language English, audience global.
3. **Base** tab: replace the contents with `myotp/base.imljson`.
4. **Connections** tab: **Create a new connection**, type **API Key**, label `MyOTP.App API key`. Paste `connections/myotp/parameters.imljson` into **Parameters** and `connections/myotp/api.imljson` into **Communication**.
5. **Modules** tab: for each folder under `modules/`, **Create a new module** of type **Action** (type **Universal**, subtype REST, for `makeApiCall`). Use the label and description from its `metadata.json`, pick the MyOTP.App connection, then paste `api.imljson` into **Communication**, `expect.imljson` into **Mappable parameters**, `interface.imljson` into **Interface** and `samples.imljson` into **Samples**. Set the action type shown in `metadata.json` (create, read or update).
6. **Groups** tab: paste `groups.imljson`.

### B. VS Code extension (Make Apps Editor)

1. Install the **Make Apps Editor** extension from the VS Code Marketplace.
2. Add your Make environment and an API key with the custom apps scopes when the extension asks.
3. Create the app from the extension's Apps view, then open each component and paste the matching file from this folder. The extension uploads on save.

### C. Make CLI

The Make CLI has `sdk-apps create`, `sdk-apps set-section`, `sdk-connections create` and `sdk-modules create` / `set-section` commands that take a section name and a JSON body. Map the files as above: `base.imljson` is the app `base` section, `api.imljson` is a module's `api` section, `expect.imljson` is `expect`, `interface.imljson` is `interface`, `samples.imljson` is `samples`. See the Make CLI reference under Custom app development for the exact flags.

## Test it

1. Create a MyOTP.App account at https://myotp.app/sign-up/ (15 free credits) and copy an API key from the dashboard.
2. Add Make's egress IP addresses to that key's IP allowlist, or set the allowlist to `*` while testing. Without this every module answers 403.
3. In a new scenario add **MyOTP.App > Get Account**, create the connection with the key, and run once. The connection is validated with `GET /me`, so a wrong key fails at connection time, not at run time.
4. Add **Send OTP** with your phone number (digits only, country code first, no plus sign) and run. Keep the `message_id`.
5. Add **Verify OTP** mapped to the code that arrived and the `message_id`. Run. `status` is `success`.
6. **Check OTP Status** with the same `message_id` shows the carrier delivery report a few seconds after the send.
7. For the public review, also run a scenario that fails on purpose (a wrong API key, or a phone number that is not a number) so the error handling shows up in the execution log.

Errors come back as `[<http status>] <message>` using the `error.message` field every rejected MyOTP request carries, with fallbacks to the `detail.message` envelope of the agent endpoints and the RFC 9457 `detail` string of a 402. A 401 is typed `InvalidAccessTokenError` so Make prompts to fix the connection, a 403 is `InvalidConfigurationError` (the IP allowlist), a 429 is `RateLimitError` so Make retries. The API key is stripped from Make's logs by the `log.sanitize` rule, and so is the `otp` field of the Send OTP response.

**Make an API Call** only reaches `https://api.myotp.app`. The path parameter is validated against an allowlist (a leading `/`, then URL path characters and an optional query; no backslash, no `//`, no scheme) and a value that fails it stops the module with "path must be relative to https://api.myotp.app". Before the request is built the value is also sanitised: backslashes become `/`, any scheme is removed, repeated slashes collapse to one, and a single `/` is prefixed, so the result can only ever be a path on the base host. The headers you add are merged with the connection's `X-API-Key` rather than replacing it. This is Make's security rule for universal modules.

Date-time outputs (`date_sent`, `expires_at`) are parsed with `parseDate` so they map as real dates in later modules.

## Validate the definition

Runs in Docker, no host install. From the repository root:

```bash
docker run --rm -v "$PWD:/w" -w /w/make node:22-alpine \
  sh -c "npm install --no-audit --no-fund && npm run build && npm test"
```

`npm run build` parses every file under `myotp/` and checks the app shape. `npm test` compares each module against `openapi-reference.yaml`: paths, request fields, required flags, enums, numeric limits and output fields must all exist in the spec.

## Listing metadata for public review

| Field | Value |
|---|---|
| Name | `myotp` |
| Label | MyOTP.App |
| Description (under 200 characters) | Send and verify one-time passcodes over SMS, WhatsApp and Telegram with one API key. Pay per message, no monthly minimum. |
| Theme colour | `#1d4ed8` |
| Service URL | https://myotp.app |
| API documentation | https://myotp.app/developer-api/ |
| Support contact | support@myotp.app |
| Categories | Communication, Security |

Logo: Make asks for the app logo in the review form. Supply a square PNG on a transparent background at 512 x 512 pixels or larger, plus an SVG if you have one. Make renders the icon on the theme colour, so the mark should be single colour white. The source files are in the MyOTP.Website social assets folder.

Review prerequisites Make checks: every module hits the real API, the base and connection have error handling, sensitive data is sanitized from logs, the connection URL validates the credential, a universal module exists, and each module was run in a test scenario shortly before submission. All of these are covered by this definition; the scenario runs are done in your Make account.

## Example

A verification flow in one scenario: a webhook receives a phone number, **Send OTP** sends the code, a second webhook receives what the user typed, and **Verify OTP** checks it.

Send OTP input:

```json
{
  "phone_number": "14155551234",
  "channel": "sms"
}
```

Send OTP output bundle:

```json
{
  "message_id": "8f14e45f-ea0d-4d1b-9c6a-2b7c1f0a9e33",
  "status": "accepted",
  "message": "OTP sent",
  "date_sent": "2026-09-02T03:02:01Z",
  "expires_at": "2026-09-02T03:07:01Z",
  "cost": 1
}
```

Verify OTP input, mapping the `message_id` from the bundle above:

```json
{
  "otp": "482917",
  "message_id": "8f14e45f-ea0d-4d1b-9c6a-2b7c1f0a9e33"
}
```
