// Server-side MyOTP.App client. Reads MYOTP_API_KEY from env at call time
// so the key never ends up in any browser-bound bundle.
//
// API reference: https://api.myotp.app

const BASE_URL = process.env.MYOTP_BASE_URL || "https://api.myotp.app";

class MyOtpError extends Error {
  constructor(status, message) {
    super(message);
    this.name = "MyOtpError";
    this.status = status;
  }
}

async function post(path, body) {
  const apiKey = process.env.MYOTP_API_KEY;
  if (!apiKey) throw new MyOtpError(500, "MYOTP_API_KEY is not configured");

  const res = await fetch(`${BASE_URL}${path}`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-API-Key": apiKey,
    },
    body: JSON.stringify(body),
  });

  let data = {};
  try {
    data = await res.json();
  } catch (_) {
    // ignore — MyOTP always returns JSON, but be defensive on network errors.
  }

  if (!res.ok) {
    throw new MyOtpError(res.status, data.message || data.error || `MyOTP error ${res.status}`);
  }
  return data;
}

function generateOtp({ phone_number, channel = "sms" }) {
  return post("/generate_otp", { phone_number, channel });
}

function verifyOtp({ otp, message_id, phone_number }) {
  const body = { otp };
  if (message_id) body.message_id = message_id;
  if (phone_number) body.phone_number = phone_number;
  return post("/verify_otp", body);
}

module.exports = { generateOtp, verifyOtp, MyOtpError };
