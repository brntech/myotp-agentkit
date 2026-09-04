import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import YAML from "yaml";
import { validateApp, loadJson, APP_DIR } from "../validate.mjs";

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

test("every module folder is listed in groups", () => {
  const groups = loadJson(join(APP_DIR, "groups.imljson"));
  const grouped = new Set(groups.flatMap((g) => g.modules));
  for (const m of readdirSync(join(APP_DIR, "modules"))) assert.ok(grouped.has(m), `${m} not grouped`);
});
