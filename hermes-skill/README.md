# MyOTP.App Hermes function-calling kit

A drop-in tool spec, system prompt, and Ollama Modelfile that teach any Nous Research Hermes model (Hermes 2 Pro, Hermes 3, Hermes 4) to send SMS, WhatsApp, and Telegram OTPs through the MyOTP.App REST API.

This is for developers running self-hosted, open-weight LLMs on Ollama, vLLM, LM Studio, or llama.cpp who want their local agent to handle phone verification without depending on a hosted MCP server.

## Why this exists

Hermes is the de facto function-calling format for the open-weight LLM ecosystem. Tools are described in a JSON spec embedded inside `<tools>...</tools>` XML tags in the system prompt; tool calls come back from the model inside `<tool_call>...</tool_call>` tags; results are fed back inside `<tool_response>...</tool_response>` tags. The Hermes Function Calling repo (https://github.com/NousResearch/Hermes-Function-Calling) is the canonical reference.

This kit adds MyOTP.App to that ecosystem. No SDK install, no third-party cloud, no telemetry. Just JSON and markdown. A privacy-conscious developer can run Hermes locally and have phone verification work end to end after a `curl` call to the MyOTP REST API.

## What is in this directory

| File | Purpose |
|---|---|
| `tools.json` | OpenAI-style JSON Schema spec for the 7 MyOTP API tools. Load this dynamically in your harness, or embed it inline in the system prompt. |
| `system-prompt.md` | The full Hermes-format system message, with `<tools>` block embedded. Copy-paste into your chat config. |
| `example-conversation.md` | Multi-turn worked example: developer asks for Express phone verification, model calls tools, returns runnable code. Useful as documentation and as a few-shot fine-tuning sample. |
| `ollama-modelfile.example` | Bakes the system prompt into an Ollama model variant: `ollama create myotp-hermes -f ollama-modelfile.example` and you're done. |
| `LICENSE` | MIT. |

## The 7 tools

| Tool | HTTP | Purpose |
|---|---|---|
| `generate_otp` | POST /generate_otp | Send a code via SMS, WhatsApp, or Telegram |
| `verify_otp` | POST /verify_otp | Confirm a code submitted by the end user |
| `check_otp_status` | POST /check_otp_status | Status of a previously sent OTP |
| `extend_otp` | POST /extend_otp | Push out an active OTP's expiry |
| `get_account_info` | GET /me | Account email of the authenticated key |
| `get_usage_report` | POST /report | Paginated transaction history |
| `register_account` | POST /v1/agent/register | BETA. Programmatic signup. Returns 404 until live. |

Full API spec: https://myotp.app/api-reference/

## Quick start with Ollama

```bash
# Pull a Hermes base model
ollama pull hermes3:8b

# Build the MyOTP variant
ollama create myotp-hermes -f ollama-modelfile.example

# Talk to it
ollama run myotp-hermes
```

Ollama parses `<tool_call>` blocks automatically when you use the OpenAI-compatible chat completions endpoint at `http://localhost:11434/v1/chat/completions`. Wire your harness to:

1. Receive the assistant message.
2. Detect `tool_calls` in the response.
3. POST the matching MyOTP endpoint with the API key in `X-API-Key`.
4. Send the response back as a `role: "tool"` message; the model will continue.

If you call `ollama run myotp-hermes` interactively (no harness), the model still emits `<tool_call>` text. You then need to run the HTTP call yourself and paste the result back wrapped in `<tool_response>...</tool_response>`. That works for demos but a real agent should use the API.

## Quick start with vLLM

```bash
# Serve any Hermes-style model with the OpenAI-compatible API
vllm serve NousResearch/Hermes-3-Llama-3.1-8B \
  --enable-auto-tool-choice \
  --tool-call-parser hermes \
  --chat-template /path/to/chat_template.jinja
```

Then point your client at `http://localhost:8000/v1/chat/completions`, pass the contents of `system-prompt.md` as your system message, and pass `tools.json` as the `tools` argument on each request. vLLM's Hermes parser converts `<tool_call>` text into proper OpenAI `tool_calls` objects.

## Quick start with LM Studio

1. Load any Hermes 2 Pro, Hermes 3, or Hermes 4 GGUF in LM Studio.
2. Open the chat config sidebar, paste the entire content of `system-prompt.md` into the system prompt field.
3. Set temperature to 0.5.
4. Start chatting. LM Studio will not auto-execute tool calls; you copy the `<tool_call>` JSON, run the HTTP request manually (or via a small harness script), and paste the response back as a user message wrapped in `<tool_response>...</tool_response>`.

LM Studio also exposes an OpenAI-compatible local server. If you enable it, your harness can drive the loop programmatically the same way as Ollama or vLLM.

## Quick start with llama.cpp

```bash
./llama-cli \
  --model hermes-3-llama-3.1-8b.Q5_K_M.gguf \
  --chat-template hermes \
  --system-prompt-file system-prompt.md \
  --temp 0.5 \
  --ctx-size 8192 \
  -p "Add SMS phone verification to my Express signup flow."
```

For the OpenAI-compatible HTTP server bundled with llama.cpp:

```bash
./llama-server \
  --model hermes-3-llama-3.1-8b.Q5_K_M.gguf \
  --chat-template hermes \
  --port 8080
```

Then post chat completions with the system prompt and `tools` array exactly like vLLM.

## How the request flows

```
user                model                    your harness            api.myotp.app
 |  "send code"      |                            |                        |
 |------------------>|                            |                        |
 |                   | <tool_call>                |                        |
 |                   |   generate_otp({...})      |                        |
 |                   | </tool_call>               |                        |
 |                   |--------------------------->| POST /generate_otp     |
 |                   |                            |----------------------->|
 |                   |                            |<-{message_id, ...}-----|
 |                   |<--<tool_response>{...}-----|                        |
 |                   |                            |                        |
 |  "got code 472918"|                            |                        |
 |------------------>|                            |                        |
 |                   | <tool_call>                |                        |
 |                   |   verify_otp({...})        |                        |
 |                   | </tool_call>               |                        |
 |                   |--------------------------->| POST /verify_otp       |
 |                   |                            |----------------------->|
 |                   |                            |<-{status:"success"}----|
 |                   |<--<tool_response>{...}-----|                        |
 |  "verified"       |                            |                        |
 |<------------------|                            |                        |
```

The harness is the only thing that knows the MyOTP API key. The model never sees it, the user never sees it, and it never leaves your infrastructure.

## API key setup

1. Get a key at https://myotp.app/sign-up/. Free trial includes 15 credits, no credit card.
2. Add your server's IP to the dashboard whitelist (or `*` while testing).
3. Set `MYOTP_API_KEY` in your harness environment.
4. Have your harness send `X-API-Key: $MYOTP_API_KEY` on every request to `https://api.myotp.app/...`.

The model is told to never hardcode the key in generated code; it always reads from `MYOTP_API_KEY`.

## Running out of credits

When the balance cannot cover the next message, every key-authenticated call returns:

```
403 {"error":{"http_code":403,"message":"Insufficient balance"}}
```

The system prompt tells the model to stop retrying and buy credits. Buying is a paid HTTP flow, not a tool in `tools.json`, because the 402 has to be paid by an MPP client rather than a plain HTTP call. The harness (or the developer) runs it once and the model retries the send.

1. Get the quote. No auth.

```bash
curl -sS "https://api.myotp.app/v1/topup/quote?credits=100"
# 200 {"credits":100,"amount_usd":"2.00","price_per_credit_usd":0.02,"min_credits":25,"max_credits":50000,"currency":"usd","methods":["card via Stripe shared payment token","usdc on tempo"]}
```

2. `POST https://api.myotp.app/v1/topup` with `X-API-Key` and body `{"credits": 100}`. The reply is `402 Payment Required` following the Machine Payments Protocol (https://mpp.dev). The `WWW-Authenticate: Payment ...` header carries two offers: USDC on Tempo (`method="tempo"`) and card or Link via Stripe (`method="stripe"`).

3. Pay and retry the same request with an MPP client. The client handles the retry.

```bash
# USDC wallet. npx mppx account create makes a wallet; testnet auto-funds.
npx -y mppx@0.9.2 https://api.myotp.app/v1/topup -X POST -H "x-api-key: $MYOTP_API_KEY" -H "content-type: application/json" -d "{\"credits\":100}"

# Card via the Stripe Link agent wallet. Run npx @stripe/link-cli auth login once.
npx -y @stripe/link-cli mpp pay https://api.myotp.app/v1/topup -X POST -d "{\"credits\":100}" -H "x-api-key: $MYOTP_API_KEY" --context "MyOTP credits"
```

In a Node harness, `import { Mppx, tempo } from "mppx/client"` wraps global fetch, so a plain `fetch()` to the endpoint pays automatically.

4. Success is `200 {"status":"credited","credits":100,"amount_usd":"2.00","balance":115,...}`. A replayed credential returns `"already_credited"`; a payment never credits twice.

Rules: $0.02 per credit. Minimum 25 credits ($0.50). Maximum 50,000 per call. Card top-ups are capped at $100 per account per rolling 24 hours; over the cap the 402 offers USDC only. USDC is uncapped. Trial accounts move to the Starter pay-as-you-go pricing table on their first top-up, and no subscription is created. No checkout page, no card form, no human once the account exists. Signup itself is still human: https://myotp.app/sign-up/, email and phone verification, 15 free credits.

## Phone number format

- Digits only. No `+`. No spaces. No leading `0`.
- 7 to 15 digits.
- Country code is part of the number. US `(415) 555-1234` becomes `14155551234`. UK `0207 946 0958` becomes `442079460958`.

The system prompt enforces this. The model normalizes obvious variations silently and asks when intent is ambiguous.

## Trust note

Everything in this directory is plain JSON and markdown. There is no executable code, no install step, no network call until your harness makes one. You can read every file in under five minutes and confirm it talks only to `api.myotp.app`. The optional top-up commands above are the only thing that moves money, and you run them yourself.

## Reference

- Nous Research Hermes Function Calling spec: https://github.com/NousResearch/Hermes-Function-Calling
- MyOTP.App API reference: https://myotp.app/api-reference/
- MyOTP.App signup: https://myotp.app/sign-up/

## License

MIT. See `LICENSE`.
