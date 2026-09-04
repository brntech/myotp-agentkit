# n8n-nodes-myotp

An [n8n](https://n8n.io) community node for [MyOTP.App](https://myotp.app). Send one-time passcodes over SMS, WhatsApp and Telegram, verify the code the user typed, and check delivery, from inside a workflow.

One credential (your API key) and one node with six operations. No runtime dependencies.

## Install

In n8n go to **Settings > Community Nodes**, choose **Install**, enter `n8n-nodes-myotp` and confirm. n8n restarts the node runtime and the **MyOTP** node appears in the node panel.

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
- Agents: `POST https://api.myotp.app/v1/agent/register` with `{"email": "..."}`. The 201 body carries the key once. The balance starts at zero, so top up with `POST /v1/topup` before sending.

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

Optional fields left empty are not sent, so the API applies its own defaults. `Force Send` and `Return OTP` are sent as the strings `"true"` and `"false"`, which is what the API expects.

The node runs once per input item and keeps the paired item, so you can map fields from a webhook body or a previous node with expressions. The node is marked usable as a tool for the AI Agent node.

### Errors

Every rejected MyOTP request carries `{"error": {"http_code": N, "message": "..."}}`. The node reads that message and raises it as the n8n error, with the HTTP code attached. With **Continue on Fail** switched on, the item is output as `{ "error": "...", "http_code": "..." }` instead.

Common codes: `400` bad input, `401` wrong key, `402` no balance, `403` calling IP not on the key's allowlist, `409` an unexpired OTP already exists for that number (set Force Send to override).

## Example: webhook, send OTP, respond

Import [`examples/webhook-send-otp.json`](examples/webhook-send-otp.json) (**Workflow menu > Import from File**), open the **Send OTP** node and pick your MyOTP API credential.

The workflow is three nodes:

1. **Webhook** (`POST /webhook/send-otp`) receives `{"phone_number": "19876543210", "channel": "sms"}`.
2. **MyOTP > Send OTP** with Phone Number set to `{{ $json.body.phone_number }}` and Channel to `{{ $json.body.channel || 'sms' }}`.
3. **Respond to Webhook** returns `{"message_id": ..., "expires_at": ...}` to the caller.

Test it:

```bash
curl -X POST https://your-n8n.example.com/webhook/send-otp \
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
npm run build   # tsc + copy icon and codex json into dist/
```

The package publishes `dist/` only. API reference: https://myotp.app/developer-api/ and the OpenAPI file at the root of this repository.

## License

MIT
