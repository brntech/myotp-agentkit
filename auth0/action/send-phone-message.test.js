const { test } = require("node:test");
const assert = require("node:assert/strict");
const { onExecuteCustomPhoneProvider, onExecuteSendPhoneMessage, buildRequest, deliver } = require("./send-phone-message.js");

const KEY = "k".repeat(32);

function mockFetch(status, body, calls) {
  return async (url, init) => {
    calls.push({ url, init, body: JSON.parse(init.body) });
    return {
      ok: status >= 200 && status < 300,
      status,
      statusText: "",
      body: null,
      text: async () => (typeof body === "string" ? body : JSON.stringify(body)),
    };
  };
}

function mockApi() {
  const log = [];
  return {
    log,
    notification: {
      retry: (reason) => log.push(["retry", reason]),
      drop: (reason) => log.push(["drop", reason]),
    },
  };
}

function otpEvent(overrides = {}, secrets = {}) {
  return {
    secrets: { MYOTP_API_KEY: KEY, ...secrets },
    notification: {
      message_type: "otp_verify",
      delivery_method: "text",
      recipient: "+14155550123",
      code: "482913",
      as_text: "Your verification code is: 482913",
      ...overrides,
    },
  };
}

test("happy path: posts Auth0's code to /generate_otp with X-API-Key", async () => {
  const calls = [];
  const api = mockApi();
  const event = otpEvent();
  event.fetch = mockFetch(200, { message_id: "abc", status: "accepted" }, calls);

  await onExecuteCustomPhoneProvider(event, api);

  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, "https://api.myotp.app/generate_otp");
  assert.equal(calls[0].init.method, "POST");
  assert.equal(calls[0].init.headers["X-API-Key"], KEY);
  assert.equal(calls[0].init.headers["Content-Type"], "application/json");
  assert.deepEqual(api.log, []);
});

test("uses globalThis.fetch when the event carries no fetch", async () => {
  const calls = [];
  const saved = globalThis.fetch;
  globalThis.fetch = mockFetch(200, { message_id: "g1" }, calls);
  try {
    const api = mockApi();
    await onExecuteCustomPhoneProvider(otpEvent(), api);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].body.otp_code, "482913");
    assert.deepEqual(api.log, []);
  } finally {
    globalThis.fetch = saved;
  }
});

test("strips the leading + and any non-digits from the recipient", async () => {
  const calls = [];
  const event = otpEvent({ recipient: "+44 7700 900123" });
  event.fetch = mockFetch(200, {}, calls);
  await onExecuteCustomPhoneProvider(event, mockApi());
  assert.equal(calls[0].body.phone_number, "447700900123");
});

test("code passthrough: otp_code is the code Auth0 generated, no otp_length, force_send on", async () => {
  const calls = [];
  const event = otpEvent({ code: "1234" });
  event.fetch = mockFetch(200, {}, calls);
  await onExecuteCustomPhoneProvider(event, mockApi());
  assert.equal(calls[0].body.otp_code, "1234");
  assert.equal("otp_length" in calls[0].body, false);
  assert.equal(calls[0].body.force_send, true);
});

test("three-digit code is sent on sms but refused on telegram", async () => {
  const calls = [];
  let event = otpEvent({ code: "123" });
  event.fetch = mockFetch(200, {}, calls);
  await onExecuteCustomPhoneProvider(event, mockApi());
  assert.equal(calls[0].body.otp_code, "123");

  const api = mockApi();
  event = otpEvent({ code: "123" }, { MYOTP_CHANNEL: "telegram" });
  event.fetch = mockFetch(200, {}, calls);
  await onExecuteCustomPhoneProvider(event, api);
  assert.equal(calls.length, 1);
  assert.match(api.log[0][1], /4 to 8 digits/);
});

test("the API origin is fixed: a base URL secret is ignored", () => {
  const req = buildRequest({
    secrets: { MYOTP_API_KEY: KEY, MYOTP_BASE_URL: "http://collector.invalid" },
    recipient: "+14155550123",
    code: "123456",
  });
  assert.equal(req.url, "https://api.myotp.app/generate_otp");
});

test("channel defaults to sms and follows the MYOTP_CHANNEL secret", async () => {
  let calls = [];
  let event = otpEvent();
  event.fetch = mockFetch(200, {}, calls);
  await onExecuteCustomPhoneProvider(event, mockApi());
  assert.equal(calls[0].body.channel, "sms");

  calls = [];
  event = otpEvent({}, { MYOTP_CHANNEL: "WhatsApp" });
  event.fetch = mockFetch(200, {}, calls);
  await onExecuteCustomPhoneProvider(event, mockApi());
  assert.equal(calls[0].body.channel, "whatsapp");
});

test("invalid MYOTP_CHANNEL is dropped with a clear reason and nothing is sent", async () => {
  const calls = [];
  const api = mockApi();
  const event = otpEvent({}, { MYOTP_CHANNEL: "voice" });
  event.fetch = mockFetch(200, {}, calls);
  await onExecuteCustomPhoneProvider(event, api);
  assert.equal(calls.length, 0);
  assert.equal(api.log[0][0], "drop");
  assert.match(api.log[0][1], /MYOTP_CHANNEL must be one of sms, whatsapp, telegram/);
});

test("MYOTP_BRAND is passed as brand only when set", async () => {
  const calls = [];
  const event = otpEvent({}, { MYOTP_BRAND: "Acme" });
  event.fetch = mockFetch(200, {}, calls);
  await onExecuteCustomPhoneProvider(event, mockApi());
  assert.equal(calls[0].body.brand, "Acme");

  const req = buildRequest({ secrets: { MYOTP_API_KEY: "x" }, recipient: "+14155550123", code: "123456" });
  assert.equal("brand" in JSON.parse(req.init.body), false);
});

test("API 4xx is surfaced as drop with status and MyOTP's message", async () => {
  const api = mockApi();
  const event = otpEvent();
  event.fetch = mockFetch(403, { error: { http_code: 403, message: "Access from this IP not allowed" } }, []);
  await onExecuteCustomPhoneProvider(event, api);
  assert.deepEqual(api.log, [["drop", "MyOTP responded 403: Access from this IP not allowed"]]);
});

test("retry policy: 5xx and ECONNRESET are dropped; ENOTFOUND, EAI_AGAIN, ECONNREFUSED, EHOSTUNREACH, ENETUNREACH and 429 are retried", async () => {
  let api = mockApi();
  let event = otpEvent();
  event.fetch = mockFetch(502, "Bad Gateway", []);
  await onExecuteCustomPhoneProvider(event, api);
  assert.deepEqual(api.log, [["drop", "MyOTP responded 502: Bad Gateway"]]);

  api = mockApi();
  event = otpEvent();
  event.fetch = async () => { const e = new Error("fetch failed"); e.cause = { code: "ECONNRESET" }; throw e; };
  await onExecuteCustomPhoneProvider(event, api);
  assert.deepEqual(api.log, [["drop", "MyOTP request failed: fetch failed"]]);

  for (const code of ["ENOTFOUND", "EAI_AGAIN", "ECONNREFUSED", "EHOSTUNREACH", "ENETUNREACH"]) {
    api = mockApi();
    event = otpEvent();
    event.fetch = async () => { const e = new Error("fetch failed"); e.cause = { code }; throw e; };
    await onExecuteCustomPhoneProvider(event, api);
    assert.deepEqual(api.log, [["retry", "MyOTP request failed: fetch failed"]], code);
  }

  api = mockApi();
  event = otpEvent();
  event.fetch = mockFetch(429, "", []);
  await onExecuteCustomPhoneProvider(event, api);
  assert.deepEqual(api.log, [["retry", "MyOTP responded 429: no error body"]]);
});

test("timeout covers the response body read and is reported as a drop", async () => {
  const fetchImpl = async (url, init) => ({
    ok: true,
    status: 200,
    statusText: "",
    body: null,
    text: () => new Promise((_, reject) => {
      init.signal.addEventListener("abort", () => {
        const e = new Error("aborted");
        e.name = "AbortError";
        reject(e);
      });
    }),
  });
  const outcome = await deliver({
    secrets: { MYOTP_API_KEY: KEY }, recipient: "+14155550123", code: "123456", fetchImpl, timeoutMs: 20,
  });
  assert.equal(outcome.ok, false);
  assert.equal(outcome.retryable, false);
  assert.equal(outcome.reason, "MyOTP response body read failed: timed out after 20ms");

  const rejecting = async () => ({
    ok: true, status: 200, statusText: "", body: null,
    text: async () => { throw new Error("socket hang up"); },
  });
  const out2 = await deliver({ secrets: { MYOTP_API_KEY: KEY }, recipient: "+14155550123", code: "123456", fetchImpl: rejecting });
  assert.equal(out2.reason, "MyOTP response body read failed: socket hang up");
});

function streamFetch(chunks, { cancelImpl } = {}) {
  const state = { reads: 0, cancelled: false, released: false };
  let i = 0;
  const fetchImpl = async () => ({
    ok: false,
    status: 500,
    statusText: "",
    body: {
      getReader: () => ({
        read: async () => {
          state.reads += 1;
          return i < chunks.length ? { value: chunks[i++], done: false } : { value: undefined, done: true };
        },
        cancel: cancelImpl || (async () => { state.cancelled = true; }),
        releaseLock: () => { state.released = true; },
      }),
    },
  });
  return { fetchImpl, state };
}

const enc = (s) => new TextEncoder().encode(s);
const sensitive = { secrets: { MYOTP_API_KEY: KEY }, recipient: "+14155550123", code: "123456" };

test("body cap: stops reading at 8 KB across chunks, cancels, releases the lock", async () => {
  const chunks = [enc("x".repeat(6000)), enc("y".repeat(6000)), enc("z".repeat(6000))];
  const { fetchImpl, state } = streamFetch(chunks);
  const outcome = await deliver({ ...sensitive, fetchImpl });
  assert.equal(state.reads, 2, "third chunk must never be read");
  assert.equal(state.cancelled, true);
  assert.equal(state.released, true);
  assert.equal(outcome.reason.length <= "MyOTP responded 500: ".length + 300, true);
});

test("body cap: a single oversized chunk is cut at 8 KB before decoding", async () => {
  const chunks = [enc("a".repeat(20000)), enc("b".repeat(10))];
  const { fetchImpl, state } = streamFetch(chunks);
  const outcome = await deliver({ ...sensitive, fetchImpl });
  assert.equal(state.reads, 1);
  assert.equal(state.cancelled, true);
  assert.equal(state.released, true);
  assert.equal(/b/.test(outcome.reason), false);
});

test("body cap: a hanging cancel is bounded by the request budget and the lock is still released", async () => {
  const chunks = [enc("c".repeat(9000))];
  const { fetchImpl, state } = streamFetch(chunks, { cancelImpl: () => new Promise(() => {}) });
  const started = Date.now();
  const outcome = await deliver({ ...sensitive, fetchImpl, timeoutMs: 30 });
  assert.equal(Date.now() - started < 2000, true);
  assert.equal(state.released, true);
  assert.match(outcome.reason, /^MyOTP responded 500: c+$/);
});

test("stream completes under the cap: lock released, no cancel", async () => {
  const { fetchImpl, state } = streamFetch([enc('{"error":{"message":"Low balance"}}')]);
  const outcome = await deliver({ ...sensitive, fetchImpl });
  assert.equal(state.reads, 2);
  assert.equal(state.cancelled, false);
  assert.equal(state.released, true);
  assert.equal(outcome.reason, "MyOTP responded 500: Low balance");
});

test("reflected recipient, code, API key and hex tokens are redacted from reasons", async () => {
  const api = mockApi();
  const event = otpEvent();
  const echo = `phone 14155550123 masked *********23 code 482913 key ${KEY} tok deadbeefdeadbeefdeadbeefdeadbeef ok`;
  event.fetch = mockFetch(400, { error: { message: echo } }, []);
  await onExecuteCustomPhoneProvider(event, api);
  const reason = api.log[0][1];
  assert.equal(reason.includes("14155550123"), false);
  assert.equal(reason.includes("*********23"), false);
  assert.equal(reason.includes("482913"), false);
  assert.equal(reason.includes(KEY), false);
  assert.equal(reason.includes("deadbeef"), false);
  assert.equal(reason, "MyOTP responded 400: phone [redacted] masked [redacted] code [redacted] key [redacted] tok [redacted] ok");

  const thrower = async () => { throw new Error(`connect to 14155550123 with ${KEY}`); };
  const outcome = await deliver({ ...sensitive, fetchImpl: thrower });
  assert.equal(outcome.reason, "MyOTP request failed: connect to [redacted] with [redacted]");
});

test("log reasons mask the phone and strip control characters from provider text", async () => {
  let api = mockApi();
  let event = otpEvent({ recipient: "+1\n555" });
  event.fetch = mockFetch(200, {}, []);
  await onExecuteCustomPhoneProvider(event, api);
  assert.equal(api.log[0][1], "MyOTP configuration error: recipient **55 is not a valid E.164 number");

  api = mockApi();
  event = otpEvent();
  event.fetch = mockFetch(400, { error: { message: "bad\r\nfake log line " + "z".repeat(500) } }, []);
  await onExecuteCustomPhoneProvider(event, api);
  const reason = api.log[0][1];
  assert.equal(/[\r\n]/.test(reason), false);
  assert.match(reason, /^MyOTP responded 400: bad fake log line z+$/);
  assert.equal(reason.length <= "MyOTP responded 400: ".length + 300, true);
});

test("throws when the api object has no notification methods (never swallows)", async () => {
  const event = otpEvent();
  event.fetch = mockFetch(402, { error: { message: "Low balance" } }, []);
  await assert.rejects(() => onExecuteCustomPhoneProvider(event, {}), /MyOTP responded 402: Low balance/);
});

test("missing MYOTP_API_KEY is reported and nothing is sent", async () => {
  const calls = [];
  const api = mockApi();
  const event = otpEvent({}, { MYOTP_API_KEY: "" });
  event.fetch = mockFetch(200, {}, calls);
  await onExecuteCustomPhoneProvider(event, api);
  assert.equal(calls.length, 0);
  assert.deepEqual(api.log, [["drop", "MyOTP configuration error: MYOTP_API_KEY secret is missing"]]);
});

test("non-OTP notification types and voice delivery are dropped, not sent", async () => {
  const calls = [];
  let api = mockApi();
  let event = otpEvent({ message_type: "blocked_account", code: undefined });
  event.fetch = mockFetch(200, {}, calls);
  await onExecuteCustomPhoneProvider(event, api);
  assert.equal(api.log[0][0], "drop");
  assert.match(api.log[0][1], /blocked_account/);

  api = mockApi();
  event = otpEvent({ delivery_method: "voice" });
  event.fetch = mockFetch(200, {}, calls);
  await onExecuteCustomPhoneProvider(event, api);
  assert.match(api.log[0][1], /no voice channel/);
  assert.equal(calls.length, 0);
});

test("legacy send-phone-message trigger: delivers from message_options and throws on error", async () => {
  const calls = [];
  const event = {
    secrets: { MYOTP_API_KEY: KEY, MYOTP_CHANNEL: "telegram" },
    message_options: { recipient: "+9611234567", code: "55667", message_type: "sms", action: "enrollment" },
    fetch: mockFetch(200, { message_id: "m1" }, calls),
  };
  await onExecuteSendPhoneMessage(event);
  assert.equal(calls[0].body.phone_number, "9611234567");
  assert.equal(calls[0].body.otp_code, "55667");
  assert.equal(calls[0].body.channel, "telegram");

  event.fetch = mockFetch(400, { error: { message: "Service not available" } }, []);
  await assert.rejects(() => onExecuteSendPhoneMessage(event), /MyOTP responded 400: Service not available/);
});
