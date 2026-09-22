# Example conversation: adding phone verification to an Express app

This walkthrough shows a Hermes-format multi-turn conversation between a developer and a Hermes-style model running with the MyOTP.App tool spec loaded. It doubles as a few-shot example for fine-tuning datasets and as human documentation.

The system message is the full block from `system-prompt.md`. It is omitted here for brevity; assume it is loaded.

The phone number `14155551234` is a fictional US example used throughout. Replace it with the real recipient when you adapt this conversation.

---

## Turn 1: developer asks

```
<|im_start|>user
I want to add phone verification to my Express app. Right now signup is just email + password. I want to require a phone number, send a code, and only create the user account once they confirm the code. Help me wire it up.
<|im_end|>
```

## Turn 2: model asks clarifying questions

```
<|im_start|>assistant
Happy to help. A couple of quick questions so the integration matches your stack:

1. Are your users mostly in one region, or worldwide? SMS is the safe default and works in 190+ countries; if you have heavy India, Brazil, Indonesia, Mexico, Nigeria, or Turkey traffic I would also expose a WhatsApp option.
2. Do you already have a MyOTP.App API key, or do we need to set one up? Free trial is 15 credits, no card required.
3. Where do you want me to wire this in: a fresh /auth/send-code and /auth/verify-code pair of routes, or alongside an existing /signup endpoint?

If you want, I can also do a one-call sanity check against your account so we know auth and IP whitelist are configured correctly before we write any code.
<|im_end|>
```

## Turn 3: developer answers

```
<|im_start|>user
Worldwide but mostly US, so SMS default is fine. I have an API key in MYOTP_API_KEY. Wire it as two new routes on /auth/send-code and /auth/verify-code. And yes, do the sanity check first against my account.
<|im_end|>
```

## Turn 4: model runs the sanity check

```
<|im_start|>assistant
Running the account check now.

<tool_call>
{"name": "get_account_info", "arguments": {}}
</tool_call>
<|im_end|>
```

## Turn 5: harness returns the result

```
<|im_start|>tool
<tool_response>
{"email": "dev@example.com"}
</tool_response>
<|im_end|>
```

## Turn 6: model confirms and demos the send

```
<|im_start|>assistant
Auth is good (account: dev@example.com). Let me run a live send to a test number to confirm the flow end to end before I hand you the Express code. I'll use a placeholder US number for the demo; swap it for your own to actually receive the SMS.

<tool_call>
{"name": "generate_otp", "arguments": {"phone_number": "14155551234", "channel": "sms"}}
</tool_call>
<|im_end|>
```

## Turn 7: harness returns the send result

```
<|im_start|>tool
<tool_response>
{
  "message_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "status": "accepted",
  "message": "OTP sent",
  "date_sent": "2026-04-28T10:30:00.000000",
  "expires_at": "2026-04-28T10:35:00.000000",
  "cost": 0.035
}
</tool_response>
<|im_end|>
```

## Turn 8: model verifies the code (assume the developer relayed it)

```
<|im_start|>assistant
OTP queued, message_id a1b2c3d4-e5f6-7890-abcd-ef1234567890, expires in 5 minutes, cost $0.035. If you got the code on the test phone, paste it back here and I'll verify it as the second half of the smoke test.
<|im_end|>
```

```
<|im_start|>user
Got 472918.
<|im_end|>
```

```
<|im_start|>assistant
<tool_call>
{"name": "verify_otp", "arguments": {"otp": "472918", "message_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890"}}
</tool_call>
<|im_end|>
```

## Turn 9: harness returns the verify result

```
<|im_start|>tool
<tool_response>
{"status": "success", "message": "OTP verified"}
</tool_response>
<|im_end|>
```

## Turn 10: model produces working Express code

```
<|im_start|>assistant
Verified end to end. Here is the Express integration. It assumes you already have express-session (or similar) so we can pin the message_id to the user's session between the send and verify calls.

```javascript
// auth/otp.js
import express from "express";

const router = express.Router();
const BASE = "https://api.myotp.app";
const KEY = process.env.MYOTP_API_KEY;

function normalizePhone(raw) {
  // Strip everything that isn't a digit. Reject leading 0 or empty.
  const digits = String(raw || "").replace(/\D/g, "");
  if (!/^[1-9]\d{6,14}$/.test(digits)) {
    const err = new Error("phone must be 7-15 digits, no leading 0");
    err.status = 400;
    throw err;
  }
  return digits;
}

router.post("/send-code", async (req, res) => {
  try {
    const phone = normalizePhone(req.body.phone);
    const r = await fetch(`${BASE}/generate_otp`, {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-API-Key": KEY },
      body: JSON.stringify({ phone_number: phone, channel: "sms" })
    });
    const data = await r.json();
    if (!r.ok) {
      return res.status(r.status).json({ error: data.message || "send failed" });
    }
    // Pin the message_id to the session. Verify by message_id later
    // (more precise than phone if the same number gets multiple OTPs).
    req.session.otp = { messageId: data.message_id, phone };
    return res.json({ ok: true, expires_at: data.expires_at });
  } catch (e) {
    return res.status(e.status || 500).json({ error: e.message });
  }
});

router.post("/verify-code", async (req, res) => {
  const otp = String(req.body.code || "");
  const session = req.session.otp;
  if (!session) {
    return res.status(400).json({ error: "no pending verification" });
  }
  if (!/^\d{3,8}$/.test(otp)) {
    return res.status(400).json({ error: "code must be 3-8 digits" });
  }
  const r = await fetch(`${BASE}/verify_otp`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-API-Key": KEY },
    body: JSON.stringify({ otp, message_id: session.messageId })
  });
  const data = await r.json();
  if (data.status !== "success") {
    return res.status(401).json({ error: data.reason || "verification failed" });
  }
  // OTP is consumed server-side. Now safe to create the user.
  delete req.session.otp;
  // const user = await createUser({ phone: session.phone, ... })
  return res.json({ verified: true, phone: session.phone });
});

export default router;
```

Wire it in `app.js`:

```javascript
import otpRouter from "./auth/otp.js";
app.use("/auth", otpRouter);
```

A few production hardening notes I would not skip:

1. Rate-limit `/auth/send-code` per IP (e.g., 3 per minute) so an attacker can't burn your credits.
2. Rate-limit `/auth/verify-code` per session (5 attempts per `messageId`) so a brute-forcer can't grind 6-digit codes.
3. Never log `req.body` on these routes; it contains the OTP.
4. Server-only verification. Do not expose `MYOTP_API_KEY` to the browser.

Want me to add the rate limits inline, or layer them with `express-rate-limit`?
<|im_end|>
```

---

## How a harness wraps this

Different inference backends use slightly different message-role tags around `<tool_response>`. The semantics are the same: feed the JSON result of the HTTP call back to the model so it can continue.

| Backend | Wrapping convention |
|---|---|
| Ollama | `role: "tool"` chat message; content is the raw `<tool_response>{...}</tool_response>` block |
| vLLM (OpenAI-compatible) | `role: "tool"`, `tool_call_id` matches the assistant's call, content is JSON |
| llama.cpp server | Same as Ollama; the chat template handles the tags |
| Raw text models | Append `<tool_response>...</tool_response>` directly into the prompt before the next assistant turn |

Either way, do not let the model hallucinate `<tool_response>` content; only your harness should produce it.
