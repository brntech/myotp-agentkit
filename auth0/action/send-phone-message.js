/**
 * MyOTP.App Custom Phone Provider for Auth0.
 *
 * Paste this file into the Action editor at Branding > Phone Provider > Custom
 * (trigger: custom-phone-provider). Auth0 generates the code and renders the
 * message. This Action hands the code to MyOTP, which delivers it over SMS,
 * WhatsApp or Telegram. The user receives the exact code Auth0 expects.
 *
 * Secrets (Action > Secrets):
 *   MYOTP_API_KEY   required. From https://myotp.app/dashboard/user-api-keys/
 *   MYOTP_CHANNEL   optional. sms (default), whatsapp or telegram.
 *   MYOTP_BRAND     optional. 3 to 16 letters, digits or dots, shown in the message.
 *   MYOTP_BASE_URL  optional. Defaults to https://api.myotp.app.
 *
 * No npm dependencies. Uses the fetch built into the Actions runtime (Node 18+).
 */

const DEFAULT_BASE_URL = "https://api.myotp.app";
const CHANNELS = ["sms", "whatsapp", "telegram"];
const OTP_TYPES = ["otp_verify", "otp_enroll"];
const TIMEOUT_MS = 15000;

/**
 * Handler for the custom-phone-provider trigger (Unified Phone Experience).
 * Covers passwordless SMS, SMS MFA enrollment and MFA challenges.
 */
exports.onExecuteCustomPhoneProvider = async (event, api) => {
  const n = event.notification || {};

  if (!OTP_TYPES.includes(n.message_type)) {
    return fail(api, "drop", `MyOTP delivers OTP codes only; notification type "${n.message_type}" has no code and was not sent`);
  }
  if (n.delivery_method && n.delivery_method !== "text") {
    return fail(api, "drop", `MyOTP has no voice channel; delivery_method "${n.delivery_method}" was not sent`);
  }

  const outcome = await deliver({
    secrets: event.secrets || {},
    recipient: n.recipient,
    code: n.code,
    fetchImpl: event.fetch || globalThis.fetch,
  });
  if (!outcome.ok) {
    return fail(api, outcome.retryable ? "retry" : "drop", outcome.reason);
  }
};

/**
 * Handler for the legacy send-phone-message trigger (MFA only, deprecated by
 * Auth0 in favour of custom-phone-provider). Same delivery, different event shape.
 */
exports.onExecuteSendPhoneMessage = async (event) => {
  const m = event.message_options || {};
  if (m.message_type && m.message_type !== "sms") {
    throw new Error(`MyOTP has no voice channel; message_type "${m.message_type}" was not sent`);
  }
  const outcome = await deliver({
    secrets: event.secrets || {},
    recipient: m.recipient,
    code: m.code,
    fetchImpl: event.fetch || globalThis.fetch,
  });
  if (!outcome.ok) throw new Error(outcome.reason);
};

/**
 * Build the /generate_otp request. Exported for tests.
 * Returns { url, init } or throws on bad configuration.
 */
function buildRequest({ secrets, recipient, code }) {
  const apiKey = (secrets.MYOTP_API_KEY || "").trim();
  if (!apiKey) throw new Error("MYOTP_API_KEY secret is missing");

  const channel = (secrets.MYOTP_CHANNEL || "sms").trim().toLowerCase();
  if (!CHANNELS.includes(channel)) {
    throw new Error(`MYOTP_CHANNEL must be one of ${CHANNELS.join(", ")}; got "${secrets.MYOTP_CHANNEL}"`);
  }

  const phone = String(recipient || "").replace(/[^0-9]/g, "");
  if (!/^[1-9][0-9]{6,14}$/.test(phone)) {
    throw new Error(`recipient "${recipient}" is not a valid E.164 number`);
  }

  const otp = String(code || "");
  if (!/^[0-9]{3,8}$/.test(otp)) {
    throw new Error("Auth0 did not supply a numeric code (3 to 8 digits) for this notification");
  }

  const body = {
    phone_number: phone,
    channel,
    otp_code: otp,
    otp_length: otp.length,
    force_send: true,
  };
  const brand = (secrets.MYOTP_BRAND || "").trim();
  if (brand) body.brand = brand;

  const baseUrl = (secrets.MYOTP_BASE_URL || DEFAULT_BASE_URL).replace(/\/+$/, "");
  return {
    url: `${baseUrl}/generate_otp`,
    init: {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-API-Key": apiKey,
        "User-Agent": "myotp-auth0-phone-provider/0.1.0",
      },
      body: JSON.stringify(body),
    },
  };
}

/**
 * Send the request. Never throws on transport or API errors; returns
 * { ok, retryable, reason } so the caller can report through the Auth0 api.
 */
async function deliver({ secrets, recipient, code, fetchImpl }) {
  let req;
  try {
    req = buildRequest({ secrets, recipient, code });
  } catch (err) {
    return { ok: false, retryable: false, reason: `MyOTP configuration error: ${err.message}` };
  }
  if (typeof fetchImpl !== "function") {
    return { ok: false, retryable: false, reason: "MyOTP configuration error: fetch is not available in this runtime" };
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);
  let response;
  try {
    response = await fetchImpl(req.url, { ...req.init, signal: controller.signal });
  } catch (err) {
    const msg = err && err.name === "AbortError" ? `timed out after ${TIMEOUT_MS}ms` : (err && err.message) || String(err);
    return { ok: false, retryable: true, reason: `MyOTP request failed: ${msg}` };
  } finally {
    clearTimeout(timer);
  }

  const text = await response.text();
  let parsed = null;
  if (text) {
    try { parsed = JSON.parse(text); } catch { parsed = text; }
  }

  if (!response.ok) {
    const detail = extractMessage(parsed) || response.statusText || "no error body";
    const retryable = response.status >= 500 || response.status === 429;
    return { ok: false, retryable, reason: `MyOTP responded ${response.status}: ${detail}` };
  }
  return { ok: true, retryable: false, reason: "", body: parsed };
}

function extractMessage(parsed) {
  if (!parsed) return null;
  if (typeof parsed === "string") return parsed.slice(0, 500);
  if (parsed.error && typeof parsed.error === "object" && parsed.error.message) return parsed.error.message;
  if (typeof parsed.error === "string") return parsed.error;
  if (typeof parsed.message === "string") return parsed.message;
  if (typeof parsed.detail === "string") return parsed.detail;
  return JSON.stringify(parsed).slice(0, 500);
}

/**
 * Report a failure to Auth0. Prefers api.notification.retry / drop so the
 * tenant log carries the reason; throws when those methods are absent.
 */
function fail(api, mode, reason) {
  const truncated = reason.slice(0, 1024);
  const notification = api && api.notification;
  if (notification && typeof notification[mode] === "function") {
    notification[mode](truncated);
    return;
  }
  throw new Error(truncated);
}

exports.buildRequest = buildRequest;
exports.deliver = deliver;
