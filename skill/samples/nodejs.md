# Node.js — MyOTP.App

Node 18+ has `fetch` built in. No npm install needed.
Set `MYOTP_API_KEY` in your environment.

## Plain script

```javascript
const BASE = "https://api.myotp.app";
const KEY = process.env.MYOTP_API_KEY;

async function generateOtp(phoneNumber, channel = "sms") {
  // channel can be "sms" (default), "whatsapp", or "telegram"
  const res = await fetch(`${BASE}/generate_otp`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-API-Key": KEY },
    body: JSON.stringify({ phone_number: phoneNumber, channel })
  });
  if (!res.ok) throw new Error(`generate_otp failed: ${res.status} ${await res.text()}`);
  return res.json(); // { message_id, status, expires_at, cost, ... }
}

async function verifyOtp(phoneNumber, otp) {
  const res = await fetch(`${BASE}/verify_otp`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-API-Key": KEY },
    body: JSON.stringify({ phone_number: phoneNumber, otp })
  });
  // verify_otp returns 200 even on logical failure — inspect status field
  return res.json(); // { status: "success" | "failed", reason?, message }
}

// Demo
const sent = await generateOtp("14155551234");
console.log("Sent:", sent.message_id);
// ...later, after user submits the code...
const result = await verifyOtp("14155551234", "123456");
console.log(result.status === "success" ? "verified" : `failed: ${result.reason}`);
```

## Express handler

```javascript
import express from "express";
const app = express();
app.use(express.json());

const KEY = process.env.MYOTP_API_KEY;

app.post("/auth/send-code", async (req, res) => {
  const { phone, channel = "sms" } = req.body;
  const r = await fetch("https://api.myotp.app/generate_otp", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-API-Key": KEY },
    body: JSON.stringify({ phone_number: phone, channel })
  });
  const data = await r.json();
  // Store message_id in the session/db so you can verify by id later if you want
  req.session.otpMessageId = data.message_id;
  res.json({ ok: r.ok, expires_at: data.expires_at });
});

app.post("/auth/verify-code", async (req, res) => {
  const { phone, code } = req.body;
  const r = await fetch("https://api.myotp.app/verify_otp", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-API-Key": KEY },
    body: JSON.stringify({ phone_number: phone, otp: code })
  });
  const data = await r.json();
  res.json({ verified: data.status === "success", reason: data.reason });
});

app.listen(3000);
```
