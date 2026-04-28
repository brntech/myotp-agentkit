require("dotenv").config();

const path = require("path");
const express = require("express");
const { generateOtp, verifyOtp, MyOtpError } = require("./myotp");
const { sanitisePhone } = require("./phone");

const app = express();
const PORT = process.env.PORT || 3000;

app.use(express.json());
app.use(express.static(path.join(__dirname, "..", "public")));

// In-memory map of phone -> { messageId, createdAt }. Entries are pruned
// after 10 minutes — comfortably longer than the default 5-minute OTP TTL.
// Replace with Redis or a session store for multi-process deployments.
const pendingByPhone = new Map();
const TTL_MS = 10 * 60 * 1000;

function rememberMessageId(phone, messageId) {
  pendingByPhone.set(phone, { messageId, createdAt: Date.now() });
}

function lookupMessageId(phone) {
  const entry = pendingByPhone.get(phone);
  if (!entry) return null;
  if (Date.now() - entry.createdAt > TTL_MS) {
    pendingByPhone.delete(phone);
    return null;
  }
  return entry.messageId;
}

setInterval(() => {
  const cutoff = Date.now() - TTL_MS;
  for (const [phone, entry] of pendingByPhone) {
    if (entry.createdAt < cutoff) pendingByPhone.delete(phone);
  }
}, 60 * 1000).unref?.();

app.post("/api/send-otp", async (req, res) => {
  const phone = sanitisePhone(req.body?.phone);
  const channel = ["sms", "whatsapp", "telegram"].includes(req.body?.channel)
    ? req.body.channel
    : "sms";

  if (!phone) {
    return res.status(400).json({ error: "Phone number is required." });
  }

  try {
    const result = await generateOtp({ phone_number: phone, channel });
    rememberMessageId(phone, result.message_id);
    res.json({ phone, channel });
  } catch (err) {
    const status = err instanceof MyOtpError ? err.status : 500;
    res.status(status).json({ error: err.message });
  }
});

app.post("/api/verify-otp", async (req, res) => {
  const phone = sanitisePhone(req.body?.phone);
  const otp = String(req.body?.otp || "").trim();

  if (!phone || !otp) {
    return res.status(400).json({ error: "Phone and code are required." });
  }

  const messageId = lookupMessageId(phone);
  if (!messageId) {
    return res.status(400).json({ error: "No active OTP for this number. Send a new code." });
  }

  try {
    const result = await verifyOtp({ otp, message_id: messageId });
    if (result.status !== "success") {
      return res.status(400).json({ error: result.message || "Verification failed." });
    }
    pendingByPhone.delete(phone);
    res.json({ verified: true, phone });
  } catch (err) {
    const status = err instanceof MyOtpError ? err.status : 500;
    res.status(status).json({ error: err.message });
  }
});

app.listen(PORT, () => {
  console.log(`MyOTP.App example running at http://localhost:${PORT}`);
});
