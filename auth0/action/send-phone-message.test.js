const { test } = require("node:test");
const assert = require("node:assert/strict");
const { onExecuteCustomPhoneProvider, onExecuteSendPhoneMessage, buildRequest } = require("./send-phone-message.js");

function mockFetch(status, body, calls) {
  return async (url, init) => {
    calls.push({ url, init, body: JSON.parse(init.body) });
    return {
      ok: status >= 200 && status < 300,
      status,
      statusText: "",
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
    secrets: { MYOTP_API_KEY: "k".repeat(32), ...secrets },
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
  assert.equal(calls[0].init.headers["X-API-Key"], "k".repeat(32));
  assert.equal(calls[0].init.headers["Content-Type"], "application/json");
  assert.deepEqual(api.log, []);
});

test("strips the leading + and any non-digits from the recipient", async () => {
  const calls = [];
  const event = otpEvent({ recipient: "+44 7700 900123" });
  event.fetch = mockFetch(200, {}, calls);
  await onExecuteCustomPhoneProvider(event, mockApi());
  assert.equal(calls[0].body.phone_number, "447700900123");
});

test("code passthrough: otp_code is the code Auth0 generated, otp_length matches", async () => {
  const calls = [];
  const event = otpEvent({ code: "1234" });
  event.fetch = mockFetch(200, {}, calls);
  await onExecuteCustomPhoneProvider(event, mockApi());
  assert.equal(calls[0].body.otp_code, "1234");
  assert.equal(calls[0].body.otp_length, 4);
  assert.equal(calls[0].body.force_send, true);
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

test("API 5xx and network failures are surfaced as retry", async () => {
  let api = mockApi();
  let event = otpEvent();
  event.fetch = mockFetch(502, "Bad Gateway", []);
  await onExecuteCustomPhoneProvider(event, api);
  assert.deepEqual(api.log, [["retry", "MyOTP responded 502: Bad Gateway"]]);

  api = mockApi();
  event = otpEvent();
  event.fetch = async () => { throw new Error("ECONNRESET"); };
  await onExecuteCustomPhoneProvider(event, api);
  assert.deepEqual(api.log, [["retry", "MyOTP request failed: ECONNRESET"]]);
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
    secrets: { MYOTP_API_KEY: "k".repeat(32), MYOTP_CHANNEL: "telegram" },
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
