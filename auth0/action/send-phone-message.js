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
 *
 * The API host is fixed to https://api.myotp.app. There is no override: a
 * secret that could point the request elsewhere would also ship the API key,
 * the phone number and the code to that host.
 *
 * Retry policy: every send uses force_send so an Auth0 "resend" always goes
 * out. To avoid duplicate messages and duplicate billing, the Action asks
 * Auth0 to retry only when the request provably never reached MyOTP: DNS or
 * connection failures raised before any bytes were sent, or a 429, which
 * MyOTP's nginx limit_req (100 req/min per client IP) answers before the
 * request reaches the application. Timeouts, connection resets and 5xx
 * responses are ambiguous, so they are dropped with a logged reason and the
 * user can press resend.
 *
 * No npm dependencies. Uses the fetch built into the Actions runtime (Node 18+).
 */

const API_ORIGIN = "https://api.myotp.app";
const CHANNELS = ["sms", "whatsapp", "telegram"];
const OTP_TYPES = ["otp_verify", "otp_enroll"];
const TIMEOUT_MS = 8000;
const MAX_BODY_BYTES = 8192;
const MAX_DETAIL_CHARS = 300;
// Errors raised before any bytes leave the runtime. Safe to retry.
const NEVER_SENT_CODES = ["ENOTFOUND", "EAI_AGAIN", "ECONNREFUSED", "EHOSTUNREACH", "ENETUNREACH"];

/**
 * Handler for the custom-phone-provider trigger (Unified Phone Experience).
 * Covers passwordless SMS, SMS MFA enrollment and MFA challenges.
 */
exports.onExecuteCustomPhoneProvider = async (event, api) => {
  const n = event.notification || {};

  if (!OTP_TYPES.includes(n.message_type)) {
    return fail(api, "drop", `MyOTP delivers OTP codes only; notification type "${clean(n.message_type)}" has no code and was not sent`);
  }
  if (n.delivery_method && n.delivery_method !== "text") {
    return fail(api, "drop", `MyOTP has no voice channel; delivery_method "${clean(n.delivery_method)}" was not sent`);
  }

  const outcome = await deliver({
    secrets: event.secrets || {},
    recipient: n.recipient,
    code: n.code,
    fetchImpl: event.fetch,
  });
  if (!outcome.ok) {
    return fail(api, outcome.retryable ? "retry" : "drop", outcome.reason);
  }
};

/**
 * Handler for the legacy send-phone-message trigger (MFA Notifications flow,
 * MFA only). This needs its own Action bound in that flow; the export is
 * inert inside a custom-phone-provider Action. Same delivery, different event
 * shape, and no api.notification, so failures are thrown.
 */
exports.onExecuteSendPhoneMessage = async (event) => {
  const m = event.message_options || {};
  if (m.message_type && m.message_type !== "sms") {
    throw new Error(`MyOTP has no voice channel; message_type "${clean(m.message_type)}" was not sent`);
  }
  const outcome = await deliver({
    secrets: event.secrets || {},
    recipient: m.recipient,
    code: m.code,
    fetchImpl: event.fetch,
  });
  if (!outcome.ok) throw new Error(outcome.reason);
};

/**
 * Build the /generate_otp request. Exported for tests.
 * Returns { url, init } or throws on bad configuration. Error messages never
 * contain the full phone number or the code.
 */
function buildRequest({ secrets, recipient, code }) {
  const apiKey = (secrets.MYOTP_API_KEY || "").trim();
  if (!apiKey) throw new Error("MYOTP_API_KEY secret is missing");

  const channel = (secrets.MYOTP_CHANNEL || "sms").trim().toLowerCase();
  if (!CHANNELS.includes(channel)) {
    throw new Error(`MYOTP_CHANNEL must be one of ${CHANNELS.join(", ")}; got "${clean(secrets.MYOTP_CHANNEL)}"`);
  }

  const phone = String(recipient || "").replace(/[^0-9]/g, "");
  if (!/^[1-9][0-9]{6,14}$/.test(phone)) {
    throw new Error(`recipient ${maskPhone(recipient)} is not a valid E.164 number`);
  }

  const otp = String(code || "");
  const minDigits = channel === "telegram" ? 4 : 3;
  if (!/^[0-9]{3,8}$/.test(otp) || otp.length < minDigits) {
    throw new Error(`Auth0 did not supply a numeric code of ${minDigits} to 8 digits for this notification`);
  }

  // otp_length is ignored by MyOTP when otp_code is supplied, so it is not sent.
  const body = {
    phone_number: phone,
    channel,
    otp_code: otp,
    force_send: true,
  };
  const brand = (secrets.MYOTP_BRAND || "").trim();
  if (brand) body.brand = brand;

  return {
    url: `${API_ORIGIN}/generate_otp`,
    sensitive: { phone, otp, apiKey },
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
 * The timeout covers the whole exchange, response body included.
 */
async function deliver({ secrets, recipient, code, fetchImpl, timeoutMs = TIMEOUT_MS }) {
  let req;
  try {
    req = buildRequest({ secrets, recipient, code });
  } catch (err) {
    return { ok: false, retryable: false, reason: `MyOTP configuration error: ${err.message}` };
  }
  const doFetch = typeof fetchImpl === "function" ? fetchImpl : globalThis.fetch;
  if (typeof doFetch !== "function") {
    return { ok: false, retryable: false, reason: "MyOTP configuration error: fetch is not available in this runtime" };
  }

  const redact = makeRedactor(req.sensitive);
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  let response;
  let text;
  try {
    response = await doFetch(req.url, { ...req.init, signal: controller.signal });
    text = await readBounded(response, MAX_BODY_BYTES, controller.signal);
  } catch (err) {
    const timedOut = err && err.name === "AbortError";
    const msg = timedOut ? `timed out after ${timeoutMs}ms` : clean(redact(err && err.message ? err.message : String(err)));
    const stage = response ? "response body read failed" : "request failed";
    return { ok: false, retryable: !response && neverSent(err), reason: `MyOTP ${stage}: ${msg}` };
  } finally {
    clearTimeout(timer);
  }

  let parsed = null;
  if (text) {
    try { parsed = JSON.parse(text); } catch { parsed = text; }
  }

  if (!response.ok) {
    const detail = clean(redact(extractMessage(parsed) || response.statusText || "no error body"));
    // 429 is answered by MyOTP's nginx limit_req (100 req/min per client IP) before the
    // request reaches the application, so nothing was sent or charged. Safe to retry.
    const retryable = response.status === 429;
    return { ok: false, retryable, reason: `MyOTP responded ${response.status}: ${detail}` };
  }
  return { ok: true, retryable: false, reason: "", body: parsed };
}

/**
 * Read at most `limit` bytes of the body. Honours the fetch abort signal
 * because the reader is on the same response. Cancels the rest.
 */
async function readBounded(response, limit, signal) {
  if (!response.body || typeof response.body.getReader !== "function") {
    const t = await response.text();
    return t.slice(0, limit);
  }
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let out = "";
  let bytes = 0;
  let truncated = false;
  try {
    while (bytes < limit) {
      const { value, done } = await reader.read();
      if (done) break;
      // Count bytes before decoding; cut an oversized chunk at the limit.
      let chunk = value;
      if (bytes + chunk.byteLength > limit) {
        chunk = chunk.subarray(0, limit - bytes);
        truncated = true;
      }
      bytes += chunk.byteLength;
      out += decoder.decode(chunk, { stream: true });
      if (truncated) break;
    }
    if (bytes >= limit) {
      // A hanging cancel must not outlive the request budget: race it against the abort signal.
      await Promise.race([
        reader.cancel().catch(() => {}),
        new Promise((resolve) => {
          if (!signal) return resolve();
          if (signal.aborted) return resolve();
          signal.addEventListener("abort", () => resolve(), { once: true });
        }),
      ]);
    }
  } finally {
    try { reader.releaseLock(); } catch { /* stream already released */ }
  }
  out += decoder.decode();
  return out;
}

/**
 * Remove values that must never reach a log: the recipient digits (full and
 * masked-suffix form), the OTP code, the API key, and any 32-char hex token.
 */
function makeRedactor({ phone, otp, apiKey }) {
  const literals = [apiKey, phone, otp].filter((v) => v && v.length >= 3);
  const masked = phone ? maskPhone(phone) : null;
  return (text) => {
    let s = String(text == null ? "" : text);
    for (const lit of literals) s = s.split(lit).join("[redacted]");
    if (masked) s = s.split(masked).join("[redacted]");
    s = s.replace(/\b[0-9a-f]{32}\b/gi, "[redacted]");
    return s;
  };
}

function neverSent(err) {
  const code = err && (err.code || (err.cause && err.cause.code));
  return NEVER_SENT_CODES.includes(code);
}

function extractMessage(parsed) {
  if (!parsed) return null;
  if (typeof parsed === "string") return parsed;
  if (parsed.error && typeof parsed.error === "object" && parsed.error.message) return String(parsed.error.message);
  if (typeof parsed.error === "string") return parsed.error;
  if (typeof parsed.message === "string") return parsed.message;
  if (typeof parsed.detail === "string") return parsed.detail;
  return JSON.stringify(parsed);
}

/** Strip control characters and newlines from provider-supplied text, and cap it. */
function clean(value) {
  return String(value == null ? "" : value)
    .replace(/[\u0000-\u001f\u007f-\u009f]/g, " ")
    .replace(/\s+/g, " ")
    .trim()
    .slice(0, MAX_DETAIL_CHARS);
}

/** Show only the last two digits of a phone number in log text. */
function maskPhone(value) {
  const digits = String(value == null ? "" : value).replace(/[^0-9]/g, "");
  if (digits.length < 3) return "(unreadable)";
  return "*".repeat(digits.length - 2) + digits.slice(-2);
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
