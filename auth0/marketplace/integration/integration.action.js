/* global fetch */
/**
 * MyOTP.App for the Auth0 Send Phone Message flow (MFA).
 *
 * Auth0 generates the one-time code and passes it in event.message_options.code.
 * This Action hands that code to MyOTP, which delivers it over SMS, WhatsApp or
 * Telegram. The user receives the exact code Auth0 will check. MyOTP never
 * verifies anything; Auth0 does.
 *
 * Secret:        MYOTP_API_KEY  (required)
 * Configuration: MYOTP_CHANNEL  sms (default), whatsapp or telegram
 *
 * The API host is fixed to https://api.myotp.app. There is no override: a
 * setting that could point the request elsewhere would also ship the API key,
 * the phone number and the code to that host.
 *
 * Failures throw, which makes Auth0 record a failed phone message in the tenant
 * logs. Error messages never contain the phone number, the code or the key.
 *
 * No npm dependencies. Uses the fetch built into the Actions runtime (Node 18+).
 */

const API_ORIGIN = "https://api.myotp.app";
const CHANNELS = ["sms", "whatsapp", "telegram"];
const TIMEOUT_MS = 8000;
const MAX_BODY_BYTES = 8192;
const MAX_DETAIL_CHARS = 300;

/** Replace control characters with spaces, collapse whitespace, and cap the length. */
const clean = (value) => {
  const printable = Array.from(String(value == null ? "" : value)).map((ch) => {
    const c = ch.charCodeAt(0);
    return c < 32 || (c >= 127 && c <= 159) ? " " : ch;
  });
  return printable.join("").replace(/\s+/g, " ").trim().slice(0, MAX_DETAIL_CHARS);
};

/** Show only the last two digits of a phone number. */
const maskPhone = (value) => {
  const digits = String(value == null ? "" : value).replace(/[^0-9]/g, "");
  if (digits.length < 3) return "(unreadable)";
  return "*".repeat(digits.length - 2) + digits.slice(-2);
};

/** Remove the phone number (full and masked), the code, the API key and 32-char hex tokens. */
const makeRedactor = ({ phone, otp, apiKey }) => {
  const literals = [apiKey, phone, otp, phone ? maskPhone(phone) : null].filter(
    (v) => v && v.length >= 3
  );
  return (text) => {
    const start = String(text == null ? "" : text);
    const scrubbed = literals.reduce((s, lit) => s.split(lit).join("[redacted]"), start);
    return scrubbed.replace(/\b[0-9a-f]{32}\b/gi, "[redacted]");
  };
};

const extractMessage = (parsed) => {
  if (!parsed) return null;
  if (typeof parsed === "string") return parsed;
  const nested = parsed.error && typeof parsed.error === "object" ? parsed.error.message : null;
  if (nested) return String(nested);
  if (typeof parsed.error === "string") return parsed.error;
  if (typeof parsed.message === "string") return parsed.message;
  if (typeof parsed.detail === "string") return parsed.detail;
  return JSON.stringify(parsed);
};

/** A promise that rejects like fetch does once the signal aborts. */
const whenAborted = (signal) => {
  const aborted = new Promise((resolve, reject) => {
    const fire = () => {
      const e = new Error("aborted");
      e.name = "AbortError";
      reject(e);
    };
    if (signal.aborted) {
      fire();
    } else {
      signal.addEventListener("abort", fire, { once: true });
    }
  });
  aborted.catch(() => {}); // handled by the races below; never an unhandled rejection
  return aborted;
};

/**
 * Read at most `limit` bytes of the body, then cancel the rest. Every read is
 * raced against the abort signal, so a body that stalls after the headers
 * arrive is bounded by the same timeout as the request.
 */
const readBounded = async (response, limit, signal) => {
  const aborted = whenAborted(signal);
  if (!response.body || typeof response.body.getReader !== "function") {
    const whole = await Promise.race([response.text(), aborted]);
    return String(whole).slice(0, limit);
  }
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  const step = async (acc, bytes) => {
    if (bytes >= limit) return { acc, full: true };
    const { value, done } = await Promise.race([reader.read(), aborted]);
    if (done) return { acc, full: false };
    const chunk = bytes + value.byteLength > limit ? value.subarray(0, limit - bytes) : value;
    return step(acc + decoder.decode(chunk, { stream: true }), bytes + chunk.byteLength);
  };
  try {
    const { acc, full } = await step("", 0);
    if (full) {
      // A hanging cancel must not outlive the request budget.
      await Promise.race([reader.cancel().catch(() => {}), aborted.catch(() => {})]);
    }
    return acc + decoder.decode();
  } finally {
    try {
      reader.releaseLock();
    } catch (e) {
      // already released
    }
  }
};

/** Build the /generate_otp request. Throws on bad configuration. */
const buildRequest = ({ settings, recipient, code }) => {
  const apiKey = String(settings.apiKey || "").trim();
  if (!apiKey) throw new Error("MYOTP_API_KEY secret is missing");

  const channel = String(settings.channel || "sms")
    .trim()
    .toLowerCase();
  if (!CHANNELS.includes(channel)) {
    throw new Error(
      `MYOTP_CHANNEL must be one of ${CHANNELS.join(", ")}; got "${clean(settings.channel)}"`
    );
  }

  const phone = String(recipient || "").replace(/[^0-9]/g, "");
  if (!/^[1-9][0-9]{6,14}$/.test(phone)) {
    throw new Error(`recipient ${maskPhone(recipient)} is not a valid E.164 number`);
  }

  const otp = String(code || "");
  const minDigits = channel === "telegram" ? 4 : 3;
  if (!/^[0-9]{3,8}$/.test(otp) || otp.length < minDigits) {
    throw new Error(
      `Auth0 did not supply a numeric code of ${minDigits} to 8 digits for this message`
    );
  }

  // force_send so an Auth0 resend always produces a new message.
  const body = { phone_number: phone, channel, otp_code: otp, force_send: true };

  return {
    url: `${API_ORIGIN}/generate_otp`,
    sensitive: { phone, otp, apiKey },
    init: {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-API-Key": apiKey,
        "User-Agent": "myotp-auth0-marketplace/1.0.0",
      },
      body: JSON.stringify(body),
    },
  };
};

/**
 * Send the request. Returns { ok, reason } and never throws.
 * The timeout covers the whole exchange, response body included.
 */
const deliver = async ({ settings, recipient, code, timeoutMs = TIMEOUT_MS }) => {
  let req;
  try {
    req = buildRequest({ settings, recipient, code });
  } catch (err) {
    return { ok: false, reason: `MyOTP configuration error: ${err.message}` };
  }
  if (typeof fetch !== "function") {
    return {
      ok: false,
      reason: "MyOTP configuration error: fetch is not available in this runtime",
    };
  }

  const redact = makeRedactor(req.sensitive);
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  let response;
  let text;
  try {
    response = await fetch(req.url, { ...req.init, signal: controller.signal });
    text = await readBounded(response, MAX_BODY_BYTES, controller.signal);
  } catch (err) {
    const timedOut = err && err.name === "AbortError";
    const detail = err && err.message ? err.message : String(err);
    const msg = timedOut ? `timed out after ${timeoutMs}ms` : clean(redact(detail));
    const stage = response ? "response body read failed" : "request failed";
    return { ok: false, reason: `MyOTP ${stage}: ${msg}` };
  } finally {
    clearTimeout(timer);
  }

  let parsed = null;
  if (text) {
    try {
      parsed = JSON.parse(text);
    } catch (e) {
      parsed = text;
    }
  }

  if (!response.ok) {
    const detail = clean(redact(extractMessage(parsed) || response.statusText || "no error body"));
    return { ok: false, reason: `MyOTP responded ${response.status}: ${detail}` };
  }
  return { ok: true, reason: "" };
};

/**
 * Handler for the Send Phone Message flow.
 *
 * @param {Event} event - Details about the user and the message Auth0 wants to send.
 */
exports.onExecuteSendPhoneMessage = async (event) => {
  const m = event.message_options || {};
  if (m.message_type && m.message_type !== "sms") {
    throw new Error(
      `MyOTP has no voice channel; message_type "${clean(m.message_type)}" was not sent`
    );
  }

  const secrets = event.secrets || {};
  const configuration = event.configuration || {};
  const outcome = await deliver({
    settings: {
      apiKey: secrets.MYOTP_API_KEY,
      // Configuration when installed from the Marketplace; secrets when the Action
      // was pasted into the editor by hand.
      channel: configuration.MYOTP_CHANNEL || secrets.MYOTP_CHANNEL,
    },
    recipient: m.recipient,
    code: m.code,
  });
  if (!outcome.ok) throw new Error(outcome.reason);
};
