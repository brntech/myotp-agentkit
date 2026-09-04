import { test } from "node:test";
import assert from "node:assert/strict";
import { loadSpec, buildCollection, buildEnvironment, sampleBody } from "../build.mjs";

const spec = loadSpec();
const col = buildCollection(spec);
const byPath = (p, method = "POST") =>
  col.item.find((i) => "/" + i.request.url.path.join("/") === p && i.request.method === method);

test("every operation in the spec becomes one request", () => {
  let n = 0;
  for (const methods of Object.values(spec.paths)) n += Object.keys(methods).length;
  assert.equal(col.item.length, n);
});

test("collection-level X-API-Key auth bound to {{api_key}}", () => {
  assert.equal(col.auth.type, "apikey");
  const kv = Object.fromEntries(col.auth.apikey.map((a) => [a.key, a.value]));
  assert.deepEqual(kv, { key: "X-API-Key", value: "{{api_key}}", in: "header" });
});

test("base_url defaults to the production server", () => {
  assert.equal(col.variable.find((v) => v.key === "base_url").value, "https://api.myotp.app");
  assert.ok(col.item.every((i) => i.request.url.raw.startsWith("{{base_url}}/")));
});

test("Send OTP body comes from spec defaults and phone_number is a variable", () => {
  const body = JSON.parse(byPath("/generate_otp").request.body.raw);
  assert.equal(body.phone_number, "{{phone_number}}");
  assert.equal(body.otp_length, 6);
  assert.equal(body.channel, "sms");
  assert.equal(body.brand, "MyOTP.App");
  assert.ok(!("return_otp" in body), "optional booleans are left out");
});

test("Send OTP stores message_id, the follow-up requests use it", () => {
  const script = byPath("/generate_otp").event[0].script.exec.join("\n");
  assert.match(script, /collectionVariables\.set\("message_id"/);
  const verify = JSON.parse(byPath("/verify_otp").request.body.raw);
  assert.equal(verify.message_id, "{{message_id}}");
  assert.equal(verify.otp, "{{otp}}");
  assert.equal(JSON.parse(byPath("/extend_otp").request.body.raw).message_id, "{{message_id}}");
  assert.equal(JSON.parse(byPath("/check_otp_status").request.body.raw).message_id, "{{message_id}}");
});

test("prose defaults on date fields are not sent as values", () => {
  const body = JSON.parse(byPath("/report").request.body.raw);
  assert.deepEqual(body, { page: 1, per_page: 10 });
});

test("explicit spec examples are used verbatim", () => {
  assert.deepEqual(JSON.parse(byPath("/v1/topup").request.body.raw), { credits: 100 });
  assert.deepEqual(JSON.parse(byPath("/v1/agent/register").request.body.raw), {
    email: "dev@example.com",
    name: "Acme",
  });
});

test("unauthenticated operations opt out of the collection auth", () => {
  assert.equal(byPath("/v1/topup/quote", "GET").request.auth?.type, "noauth");
  assert.equal(byPath("/v1/agent/register").request.auth?.type, "noauth");
  assert.equal(byPath("/me", "GET").request.auth, undefined);
});

test("query parameters carry the spec example", () => {
  const q = byPath("/v1/topup/quote", "GET").request.url.query;
  assert.deepEqual(q.map((x) => [x.key, x.value]), [["credits", "100"]]);
});

test("form-encoded bodies become urlencoded", () => {
  const confirm = byPath("/v1/agent/verify-email");
  assert.equal(confirm.request.body.mode, "urlencoded");
  assert.deepEqual(confirm.request.body.urlencoded[0], { key: "token", value: "TOKEN_FROM_THE_CONFIRMATION_EMAIL" });
});

test("environment marks api_key secret", () => {
  const env = buildEnvironment(spec);
  assert.equal(env.values.find((v) => v.key === "api_key").type, "secret");
});

test("sampleBody resolves $ref and allOf", () => {
  const s = sampleBody(spec, { schema: { $ref: "#/components/schemas/AgentRegistration" } });
  assert.equal(typeof s, "object");
  assert.equal("api_key" in s, true);
});
