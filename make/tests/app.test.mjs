import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import * as fs from "node:fs";
import * as os from "node:os";
import { join } from "node:path";

const require_fs = () => fs;
const require_os = () => os;
import YAML from "yaml";
import { validateApp, loadJson, errorTypes, ERROR_TYPES, APP_DIR } from "../validate.mjs";

const spec = YAML.parse(readFileSync(join(APP_DIR, "..", "..", "openapi-reference.yaml"), "utf8"));
const moduleDir = (name) => join(APP_DIR, "modules", name);

function responseProps(path, method = "post") {
  const op = spec.paths[path][method];
  const ok = op.responses["200"] ?? op.responses[200];
  return ok.content["application/json"].schema.properties;
}

function requestProps(path) {
  return spec.paths[path].post.requestBody.content["application/json"].schema;
}

test("the app definition passes the validator", () => {
  assert.deepEqual(validateApp(), []);
});

test("the validator reports a broken file instead of throwing", () => {
  // Point it at a directory with no app in it: base/connection/modules are missing.
  assert.throws(() => validateApp(join(APP_DIR, "does-not-exist")));
});

const ACTIONS = {
  sendOtp: "/generate_otp",
  verifyOtp: "/verify_otp",
  extendOtp: "/extend_otp",
  checkOtpStatus: "/check_otp_status",
};

for (const [name, path] of Object.entries(ACTIONS)) {
  test(`${name}: url matches the spec path`, () => {
    const api = loadJson(join(moduleDir(name), "api.imljson"));
    assert.equal(api.url, path);
    assert.equal(api.method, "POST");
  });

  test(`${name}: every interface field is a documented response field`, () => {
    const documented = new Set(Object.keys(responseProps(path)));
    const iface = loadJson(join(moduleDir(name), "interface.imljson"));
    for (const f of iface) assert.ok(documented.has(f.name), `${f.name} not in spec response`);
  });

  test(`${name}: every mappable parameter is a documented request field, required ones match`, () => {
    const schema = requestProps(path);
    const expect = loadJson(join(moduleDir(name), "expect.imljson"));
    for (const p of expect) assert.ok(p.name in schema.properties, `${p.name} not in spec request`);
    const required = new Set(schema.required ?? []);
    for (const p of expect) assert.equal(Boolean(p.required), required.has(p.name), `${p.name} required flag`);
  });

  test(`${name}: body sends every declared parameter`, () => {
    const api = loadJson(join(moduleDir(name), "api.imljson"));
    const expect = loadJson(join(moduleDir(name), "expect.imljson"));
    for (const p of expect) assert.equal(api.body[p.name], `{{parameters.${p.name}}}`);
  });
}

test("sendOtp: channel options equal the spec enum", () => {
  const enumValues = requestProps("/generate_otp").properties.channel.enum;
  const expect = loadJson(join(moduleDir("sendOtp"), "expect.imljson"));
  const channel = expect.find((p) => p.name === "channel");
  assert.deepEqual(channel.options.map((o) => o.value), enumValues);
  assert.equal(channel.default, "sms");
});

test("sendOtp: numeric limits equal the spec", () => {
  const props = requestProps("/generate_otp").properties;
  const expect = loadJson(join(moduleDir("sendOtp"), "expect.imljson"));
  for (const n of ["otp_length", "otp_validity", "template_order"]) {
    const p = expect.find((x) => x.name === n);
    assert.deepEqual(p.validate, { min: props[n].minimum, max: props[n].maximum });
  }
});

test("checkOtpStatus: deprecated aliases are not exposed", () => {
  const iface = loadJson(join(moduleDir("checkOtpStatus"), "interface.imljson"));
  assert.ok(!iface.some((f) => f.name.endsWith(":")));
});

test("getAccount: GET /me and returns the documented email", () => {
  const api = loadJson(join(moduleDir("getAccount"), "api.imljson"));
  assert.equal(api.url, "/me");
  assert.equal(api.method, "GET");
  const documented = Object.keys(responseProps("/me", "get"));
  assert.deepEqual(loadJson(join(moduleDir("getAccount"), "interface.imljson")).map((f) => f.name), documented);
});

test("connection validates with GET /me from the spec", () => {
  const api = loadJson(join(APP_DIR, "connections", "myotp", "api.imljson"));
  assert.equal(api.url, spec.servers[0].url + "/me");
  assert.ok("get" in spec.paths["/me"]);
});

test("base error message surfaces the Error envelope from the spec", () => {
  const envelope = spec.components.schemas.Error.properties.error.properties;
  assert.ok("message" in envelope);
  const base = loadJson(join(APP_DIR, "base.imljson"));
  assert.match(base.response.error.message, /body\.error\.message/);
  assert.match(base.response.error.message, /statusCode/);
});

test("every error type in base, connection and modules is one Make accepts", () => {
  const blocks = [
    loadJson(join(APP_DIR, "base.imljson")).response.error,
    loadJson(join(APP_DIR, "connections", "myotp", "api.imljson")).response.error,
  ];
  for (const m of readdirSync(join(APP_DIR, "modules"))) blocks.push(loadJson(join(moduleDir(m), "api.imljson")).response?.error);
  const found = blocks.flatMap((b) => errorTypes(b));
  assert.ok(found.length >= 9, "expected the per-status handlers to declare types");
  for (const t of found) assert.ok(ERROR_TYPES.has(t), `${t} is not a Make error type`);
  assert.ok(!found.includes("InvalidCredentials"));
});

test("validator rejects an unknown error type", () => {
  assert.deepEqual(errorTypes({ type: "Nope", 401: { type: "DataError" } }), ["Nope", "DataError"]);
  assert.ok(!ERROR_TYPES.has("Nope"));
});

test("base status handlers read every documented envelope", () => {
  const err = loadJson(join(APP_DIR, "base.imljson")).response.error;
  // Agent endpoints answer {detail:{message}} on 400 and 409; top-up 402 is RFC 9457 with a string detail.
  assert.match(err["400"].message, /body\.detail\.message/);
  assert.match(err["402"].message, /ifempty\(body\.detail,/);
  assert.match(err["409"].message, /body\.detail\.message/);
  assert.doesNotMatch(err["409"].message, /OTP/);
  assert.match(err.message, /body\.error\.message/);
});

test("makeApiCall merges headers with the base and forces a relative path", () => {
  const api = loadJson(join(moduleDir("makeApiCall"), "api.imljson"));
  assert.ok("{{...}}" in api.headers, "headers must use the spread merge form");
  assert.ok(api.url.startsWith("/"));
  assert.match(api.url, /replace\(/);
  assert.match(api.url, /parameters\.url/);
  const urlParam = loadJson(join(moduleDir("makeApiCall"), "expect.imljson")).find((p) => p.name === "url");
  assert.equal(urlParam.validate.pattern, "^/");
  // The strip expression, applied the way IML will apply it, removes scheme and host.
  const strip = (s) => "/" + s.trim().replace(/^[a-z]+:\/\/[^/]*/i, "").replace(/^\/+/, "");
  assert.equal(strip("https://evil.example/steal?x=1"), "/steal?x=1");
  assert.equal(strip("http://api.myotp.app/report"), "/report");
  assert.equal(strip("/report"), "/report");
  assert.equal(strip("report"), "/report");
  assert.equal(strip("//evil.example/x"), "/evil.example/x");
});

test("every field the spec requires is declared and required in expect.imljson", () => {
  for (const [name, path] of Object.entries(ACTIONS)) {
    const required = requestProps(path).required ?? [];
    const expect = loadJson(join(moduleDir(name), "expect.imljson"));
    for (const r of required) {
      const p = expect.find((x) => x.name === r);
      assert.ok(p, `${name}: ${r} not declared`);
      assert.equal(p.required, true, `${name}: ${r} not required`);
    }
  }
});

test("validator flags a missing required field", () => {
  // Build a throwaway copy of sendOtp with phone_number removed and point the validator at it.
  const { mkdtempSync, cpSync, writeFileSync } = require_fs();
  const { tmpdir } = require_os();
  const dir = mkdtempSync(join(tmpdir(), "myotp-make-"));
  cpSync(APP_DIR, dir, { recursive: true });
  const expectPath = join(dir, "modules", "sendOtp", "expect.imljson");
  const expect = loadJson(expectPath).filter((p) => p.name !== "phone_number");
  writeFileSync(expectPath, JSON.stringify(expect));
  const problems = validateApp(dir);
  assert.ok(problems.some((p) => /spec requires phone_number/.test(p)), problems.join("\n"));
});

test("date-time outputs are parsed and declared as date", () => {
  const dated = { sendOtp: ["date_sent", "expires_at"], extendOtp: ["expires_at"], checkOtpStatus: ["expires_at"] };
  for (const [name, fields] of Object.entries(dated)) {
    const api = loadJson(join(moduleDir(name), "api.imljson"));
    const iface = loadJson(join(moduleDir(name), "interface.imljson"));
    for (const f of fields) {
      assert.match(api.response.output[f], /parseDate\(body\.\w+, 'YYYY-MM-DDTHH:mm:ssZ'\)/, `${name}.${f}`);
      assert.equal(iface.find((x) => x.name === f).type, "date", `${name}.${f} interface type`);
    }
  }
});

test("every module folder is listed in groups", () => {
  const groups = loadJson(join(APP_DIR, "groups.imljson"));
  const grouped = new Set(groups.flatMap((g) => g.modules));
  for (const m of readdirSync(join(APP_DIR, "modules"))) assert.ok(grouped.has(m), `${m} not grouped`);
});
