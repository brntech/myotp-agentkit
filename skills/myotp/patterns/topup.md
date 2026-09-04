# Pattern: running out of credits (agent top-up)

## Creating the account first

If there is no account yet, the agent can create one: `POST https://api.myotp.app/v1/agent/register` with `{"email":"dev@example.com","name":"Acme"}` (no auth) answers 201 with the `api_key` shown once. The new account has a zero balance, no trial credits and no phone verification, so the very next step is the top-up below. USDC top-ups work immediately; card top-ups unlock once a human clicks the confirmation email (`POST /v1/agent/resend-verification` re-sends it, `GET /v1/agent/account` shows `email_verified`). Limit: 5 registrations per IP per day. The human path at https://myotp.app/sign-up still exists and gives 15 free credits with email and phone verification.

Every `generate_otp` call deducts credits. When the balance cannot cover the next message the API refuses the send. The fix is a paid top-up, and an agent can do it alone. There is no checkout page, no card form, and no human step; the account itself can be created by the agent too.

## What running out looks like

```
POST /generate_otp
403
{"error":{"http_code":403,"message":"Insufficient balance"}}
```

The same 403 comes from `/verify_otp` and the other key-authenticated endpoints, because the balance check runs in auth. Transactions that fail after acceptance carry the status `failed.NoBalance`.

Do not retry the send. Buy credits, then retry.

## Sequence

```
Agent                                   api.myotp.app
 | GET /v1/topup/quote?credits=100 ---->|
 |<-- 200 {amount_usd:"2.00", ...}      |
 | POST /v1/topup {credits:100} ------->|
 |<-- 402 Payment Required              |   WWW-Authenticate: Payment ... (two offers)
 | pay the challenge (MPP client)       |
 | POST /v1/topup + payment credential->|   same request, same body
 |<-- 200 {status:"credited",balance:..}|
 | POST /generate_otp (retry) --------->|
```

## Step 1: get the quote (no auth)

```bash
curl -sS "https://api.myotp.app/v1/topup/quote?credits=100"
```

```
200 {"credits":100,"amount_usd":"2.00","price_per_credit_usd":0.02,"min_credits":25,"max_credits":50000,"currency":"usd","methods":["card via Stripe shared payment token","usdc on tempo"]}
400 {"error":{"http_code":400,"message":"credits must be an integer between 25 and 50000"}}
```

Show the amount to the user if a spending policy requires it. The quote is free and idempotent.

## Step 2: POST /v1/topup and receive a 402

```
POST /v1/topup
X-API-Key: <32-char key>
Content-Type: application/json

{"credits": 100}
```

Responses:

- `401` `{"error":{"http_code":401,"message":"invalid or missing X-API-Key"}}`
- `403` account not active
- `402 Payment Required`. This is the expected response. It follows the Machine Payments Protocol (https://mpp.dev).

The 402 carries a `WWW-Authenticate: Payment ...` header with two offers:

- `method="tempo"`: USDC on Tempo. The decoded `request` is `{"amount":"2000000","currency":"0x20c0...","recipient":"0x...","methodDetails":{"chainId":...}}`. Amount is in USDC atomic units, 6 decimals.
- `method="stripe"`: card or Link via a Stripe shared payment token. The decoded `request` is `{"amount":"200","currency":"usd","methodDetails":{"networkId":"profile_...","paymentMethodTypes":["card","link"]}}`. Amount is in cents.

The body is RFC 9457 `problem+json` and includes a `challengeId`. Each offer also has `realm="api.myotp.app"`, `intent="charge"`, `description`, `expires`, and `opaque` fields. Pass the header through to the MPP client untouched.

## Step 3: pay and retry with an MPP client

Any MPP client pays the challenge and replays the same request with the payment credential attached. You do not build the retry yourself.

USDC wallet with the mppx CLI. `npx mppx account create` makes a wallet; testnet auto-funds.

```bash
npx -y mppx@0.9.2 https://api.myotp.app/v1/topup -X POST -H "x-api-key: YOUR_KEY" -H "content-type: application/json" -d "{\"credits\":100}"
```

Card via the Stripe Link agent wallet. Run `npx @stripe/link-cli auth login` once first.

```bash
npx -y @stripe/link-cli mpp pay https://api.myotp.app/v1/topup -X POST -d "{\"credits\":100}" -H "x-api-key: YOUR_KEY" --context "Buying MyOTP.App credits to send one-time passcodes over SMS, WhatsApp and Telegram for phone verification in my app."
```

Programmatic, inside your own code:

```javascript
import { Mppx, tempo } from "mppx/client";
// Mppx wraps global fetch. A plain fetch() to the endpoint now pays the 402 and retries by itself.
```

## Step 4: read the result

```
200 {"status":"credited","credits":100,"amount_usd":"2.00","currency":"usd","payment":{"method":"tempo","reference":"0x..."},"balance":115,"plan_id":6452}
```

- `status` is `"already_credited"` when a credential is replayed. A payment never credits twice, so retrying a top-up is safe.
- `500` `{"error":{...}}` means the payment settled but crediting failed. Email support@myotp.app with the `payment.reference`; the credit is applied from the ledger.

Then retry the original `generate_otp` call.

## Rules

| Rule | Value |
|---|---|
| Price | $0.02 per credit |
| Minimum per call | 25 credits ($0.50, the Stripe card minimum) |
| Maximum per call | 50,000 credits |
| Card cap | $100 per account per rolling 24 hours. Over the cap the 402 offers USDC only |
| USDC cap | none |
| Trial accounts | move to the Starter pay-as-you-go pricing table on the first top-up. No subscription is created |
| Phone verification | unchanged. You need an API key, and the dashboard shows it after email and phone verification |

## Backend pseudocode

```
send_otp(phone):
  res = POST /generate_otp { phone_number: phone }
  if res.status == 403 and res.error.message == "Insufficient balance":
    quote = GET /v1/topup/quote?credits=TOPUP_CREDITS
    if quote.amount_usd > policy.max_auto_spend: notify_human(quote); return
    pay = mpp_fetch(POST /v1/topup { credits: TOPUP_CREDITS })   // pays the 402, retries
    if pay.status not in ("credited", "already_credited"): escalate(pay)
    res = POST /generate_otp { phone_number: phone }
  return res
```

## Gotchas

- The top-up endpoint cannot create accounts. Create one first with `POST /v1/agent/register` (see the preface above) or use the human path at https://myotp.app/sign-up.
- Keep `TOPUP_CREDITS` between 25 and 50,000 or the quote returns 400 before any payment happens.
- Decide the spend limit in code, not in the prompt. The quote endpoint exists so the agent can check the dollar amount before paying.
- Treat the retry as the same request. Changing the body between the 402 and the paid retry invalidates the challenge.
- Do not log the `WWW-Authenticate` header or the payment credential.
