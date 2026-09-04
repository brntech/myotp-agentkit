# n8n-nodes-myotp

An [n8n](https://n8n.io) community node for [MyOTP.App](https://myotp.app). Send one-time passcodes over SMS, WhatsApp and Telegram, verify the code the user typed, and check delivery, from inside a workflow.

One credential (your API key) and one node with six operations. No runtime dependencies.

## Install

Two routes, depending on how you run n8n:

- **Self-hosted, as the instance owner or an admin:** go to **Settings > Community Nodes**, choose **Install**, enter `n8n-nodes-myotp` and confirm. The **MyOTP** node then appears in the node panel for everyone on the instance.
- **n8n Cloud, or self-hosted with verified community nodes enabled:** once this package is verified by n8n, search for **MyOTP** in the node panel and install it from there. Until then, Cloud cannot install it.

Self-hosted from the command line:

```bash
cd ~/.n8n/nodes
npm install n8n-nodes-myotp
```

Then restart n8n.

## Credentials

The node uses one credential, **MyOTP API**, with a single field: the API key. It is sent as the `X-API-Key` header on every request. The credential test calls `GET /me`, so a wrong key or an IP that is not on the key's allowlist fails right there.

Where to get a key:

- Humans: sign up at https://myotp.app/sign-up/ (15 free credits, email and phone verification). Keys live under **API Keys** in the dashboard.
- Agents: `POST https://api.myotp.app/v1/agent/register` with `{"email": "..."}`. The 201 body carries the key once. The balance starts at zero. To buy credits, `POST /v1/topup` with `{"credits": N}` (25 to 50000, $0.02 each). The first call answers 402 with payment challenges (USDC or card); pay one and repeat the request. Details: https://myotp.app/developer-api/

Phone numbers are digits only in E.164 order, country code first, no plus sign. `19876543210`, not `+1 987 654 3210`.

## Operations

| Operation | Endpoint | Required | Optional |
|---|---|---|---|
| Send OTP | `POST /generate_otp` | Phone Number | Channel (`sms`, `whatsapp`, `telegram`), OTP Length (4 to 8), OTP Validity (60 to 86400 s, SMS only), Template Order (1 to 5), Brand, Force Send, Return OTP |
| Verify OTP | `POST /verify_otp` | OTP | Phone Number, Message ID |
| Extend OTP | `POST /extend_otp` | Message ID, Duration (60 to 14400 s) | |
| Check OTP Status | `POST /check_otp_status` | Message ID | |
| Account | `GET /me` | | |
| Usage Report | `POST /report` | | Start Date, End Date (`YYYY-MM-DD`), Page, Per Page (1 to 100) |

Optional fields left empty are not sent, so the API applies its own defaults. `Force Send` is sent as the string `"true"` or `"false"`, which is what the API expects for that field. `Return OTP` is sent as a boolean.

The node runs once per input item and keeps the paired item, so you can map fields from a webhook body or a previous node with expressions. The node is marked usable as a tool for the AI Agent node.

### Errors

Every rejected MyOTP request carries `{"error": {"http_code": N, "message": "..."}}`. The node reads that message and raises it as the n8n error, with the HTTP code attached. With **Continue on Fail** switched on, the item is output as `{ "error": "...", "http_code": "..." }` instead.

Common codes: `400` bad input, `401` wrong key, `402` no balance, `403` calling IP not on the key's allowlist, `409` an unexpired OTP already exists for that number (set Force Send to override).

## Example: webhook, send OTP, respond

Import [`examples/webhook-send-otp.json`](examples/webhook-send-otp.json) (**Workflow menu > Import from File**). Then:

1. Open **Webhook** and create the **Header Auth** credential it asks for (a header name such as `X-Webhook-Key` and a long random value). The webhook rejects requests without it.
2. Open **Send OTP** and pick your MyOTP API credential.

The workflow is five nodes:

1. **Webhook** (`POST /webhook/send-otp`, Header Auth) receives `{"phone_number": "19876543210", "channel": "sms"}`.
2. **IF** checks `phone_number` against `^[1-9][0-9]{6,14}$`. Anything else answers 400 without touching MyOTP.
3. **MyOTP > Send OTP** with Phone Number from the body and Channel limited to `sms`, `whatsapp` or `telegram`.
4. **Respond to Webhook** returns `{"message_id": ..., "expires_at": ...}` to the caller.
5. **Respond 400** for the invalid-number branch.

A send endpoint spends your credits and sends messages to whatever number it is given, so never expose one without authentication. Keep the Header Auth on, and rate limit it as well: n8n has no per-webhook rate limit, so put the limit in the reverse proxy in front of n8n (for example nginx `limit_req`) or call the webhook only from your own backend.

Test it:

```bash
curl -X POST https://your-n8n.example.com/webhook/send-otp \
  -H "X-Webhook-Key: your-long-random-value" \
  -H "Content-Type: application/json" \
  -d '{"phone_number": "19876543210", "channel": "sms"}'
```

Response:

```json
{ "message_id": "8f14e45f-ea0d-4d1b-9c6a-2b7c1f0a9e33", "expires_at": "2026-09-04T12:05:00Z" }
```

To finish the flow, add a second webhook that takes `{"otp": "123456", "message_id": "..."}` and feeds **MyOTP > Verify OTP**. A `status` of `success` in its output means the user holds the phone.

## Development

```bash
npm install
npm test        # vitest: request building for each operation, error mapping, node wiring
npm run lint    # eslint-plugin-n8n-nodes-base
npm run build   # tsc + copy icons and codex json into dist/
```

To run n8n's community package scanner (`@n8n/scan-community-package`) on the built package before it is published, install the scanner in a scratch directory, `npm pack` this package, unpack the tarball and run `node scripts/scan-package.mjs <unpacked-dir> <source-dir>`. The exact Docker one-liner is in the repository's lane notes.

The package publishes `dist/` only. API reference: https://myotp.app/developer-api/ and the OpenAPI file at the root of this repository.

## License

MIT
