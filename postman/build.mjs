#!/usr/bin/env node
// Builds a Postman Collection v2.1 and an environment from openapi-reference.yaml.
// Usage: node build.mjs [path/to/openapi.yaml] [outDir]
import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import YAML from "yaml";

const here = dirname(fileURLToPath(import.meta.url));
const SPEC = resolve(process.argv[2] ?? join(here, "..", "openapi-reference.yaml"));
const OUT = resolve(process.argv[3] ?? here);

// Fields that become Postman variables so the requests chain together.
const VARIABLE_FIELDS = {
  phone_number: "{{phone_number}}",
  message_id: "{{message_id}}",
  otp: "{{otp}}",
};

const SEND_OTP_TEST = [
  "const json = pm.response.json();",
  'pm.test("status is 200", () => pm.response.to.have.status(200));',
  'pm.test("message_id present", () => pm.expect(json.message_id).to.be.a("string"));',
  "// Verify OTP, Extend OTP and Check OTP Status read this variable.",
  'pm.collectionVariables.set("message_id", json.message_id);',
  "// Only present when the request asked for return_otp: true.",
  'if (json.otp) pm.collectionVariables.set("otp", json.otp);',
];

const JSON_TEST = ['pm.test("response is JSON", () => pm.response.to.be.json);'];

export function loadSpec(path = SPEC) {
  return YAML.parse(readFileSync(path, "utf8"));
}

export function resolveRef(spec, node) {
  if (!node || typeof node !== "object") return node;
  if (node.$ref) {
    const parts = node.$ref.replace(/^#\//, "").split("/");
    let cur = spec;
    for (const p of parts) cur = cur[p];
    return resolveRef(spec, cur);
  }
  if (node.allOf) {
    const merged = { type: "object", properties: {}, required: [] };
    for (const part of node.allOf) {
      const r = resolveRef(spec, part);
      Object.assign(merged.properties, r.properties ?? {});
      merged.required.push(...(r.required ?? []));
    }
    return merged;
  }
  return node;
}

// Sample body: an explicit media example wins, then per-field examples and defaults.
export function sampleBody(spec, media) {
  if (!media) return undefined;
  if (media.example !== undefined) return withVariables(media.example);
  const schema = resolveRef(spec, media.schema);
  if (!schema || schema.type !== "object") return undefined;
  const out = {};
  const required = new Set(schema.required ?? []);
  for (const [name, raw] of Object.entries(schema.properties ?? {})) {
    const prop = resolveRef(spec, raw);
    if (name in VARIABLE_FIELDS) out[name] = VARIABLE_FIELDS[name];
    else if (prop.example !== undefined) out[name] = prop.example;
    else if (usableDefault(prop) && (required.has(name) || typeof prop.default !== "boolean")) {
      out[name] = prop.default;
    } else if (required.has(name)) out[name] = placeholder(prop);
  }
  return out;
}

// A default is only a usable sample value if the API would accept it verbatim.
// The spec documents prose defaults like "7 days ago" on date fields; skip those.
function usableDefault(prop) {
  if (prop.default === undefined) return false;
  if (prop.format === "date") return /^\d{4}-\d{2}-\d{2}$/.test(String(prop.default));
  if (prop.format === "date-time") return !Number.isNaN(Date.parse(String(prop.default)));
  return true;
}

function withVariables(example) {
  if (!example || typeof example !== "object") return example;
  const out = { ...example };
  for (const k of Object.keys(out)) if (k in VARIABLE_FIELDS) out[k] = VARIABLE_FIELDS[k];
  return out;
}

function placeholder(prop) {
  if (prop.type === "integer" || prop.type === "number") return prop.minimum ?? 0;
  if (prop.type === "boolean") return false;
  return "";
}

function needsApiKey(op) {
  return (op.parameters ?? []).some((p) => p.in === "header" && p.name === "X-API-Key");
}

function queryParams(op) {
  return (op.parameters ?? [])
    .filter((p) => p.in === "query")
    .map((p) => ({
      key: p.name,
      value: p.example !== undefined ? String(p.example) : "",
      description: p.description ?? "",
    }));
}

function buildBody(spec, op) {
  const content = op.requestBody?.content;
  if (!content) return undefined;
  if (content["application/json"]) {
    const sample = sampleBody(spec, content["application/json"]);
    if (sample === undefined) return undefined;
    return {
      mode: "raw",
      raw: JSON.stringify(sample, null, 2),
      options: { raw: { language: "json" } },
    };
  }
  if (content["application/x-www-form-urlencoded"]) {
    const sample = sampleBody(spec, content["application/x-www-form-urlencoded"]) ?? {};
    return {
      mode: "urlencoded",
      urlencoded: Object.entries(sample).map(([key, value]) => ({ key, value: String(value) })),
    };
  }
  return undefined;
}

function urlFor(path, query) {
  const qs = query.length ? "?" + query.map((q) => `${q.key}=${q.value}`).join("&") : "";
  const url = {
    raw: "{{base_url}}" + path + qs,
    host: ["{{base_url}}"],
    path: path.split("/").filter(Boolean),
  };
  if (query.length) url.query = query;
  return url;
}

export function buildItem(spec, path, method, op) {
  const query = queryParams(op);
  const body = buildBody(spec, op);
  const request = {
    method: method.toUpperCase(),
    header: [],
    url: urlFor(path, query),
    description: (op.description ?? "").trim(),
  };
  if (body?.mode === "raw") request.header.push({ key: "Content-Type", value: "application/json" });
  if (body) request.body = body;
  if (!needsApiKey(op)) request.auth = { type: "noauth" };

  const exec = path === "/generate_otp" ? SEND_OTP_TEST : JSON_TEST;
  return {
    name: op.summary ?? `${method.toUpperCase()} ${path}`,
    event: [{ listen: "test", script: { type: "text/javascript", exec } }],
    request,
    response: [],
  };
}

export function buildCollection(spec) {
  const items = [];
  for (const [path, methods] of Object.entries(spec.paths)) {
    for (const [method, op] of Object.entries(methods)) {
      if (!["get", "post", "put", "patch", "delete"].includes(method)) continue;
      items.push(buildItem(spec, path, method, op));
    }
  }
  const baseUrl = spec.servers?.[0]?.url ?? "https://api.myotp.app";
  return {
    info: {
      name: "MyOTP.App",
      description:
        (spec.info.description ?? "").trim() +
        `\n\nGenerated from openapi-reference.yaml (API ${spec.info.version}).`,
      schema: "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
    },
    auth: {
      type: "apikey",
      apikey: [
        { key: "key", value: "X-API-Key", type: "string" },
        { key: "value", value: "{{api_key}}", type: "string" },
        { key: "in", value: "header", type: "string" },
      ],
    },
    variable: [
      { key: "base_url", value: baseUrl, type: "string" },
      { key: "api_key", value: "", type: "string" },
      { key: "phone_number", value: "19876543210", type: "string" },
      { key: "message_id", value: "", type: "string" },
      { key: "otp", value: "", type: "string" },
    ],
    item: items,
  };
}

export function buildEnvironment(spec) {
  const baseUrl = spec.servers?.[0]?.url ?? "https://api.myotp.app";
  return {
    name: "MyOTP.App",
    values: [
      { key: "base_url", value: baseUrl, type: "default", enabled: true },
      { key: "api_key", value: "", type: "secret", enabled: true },
      { key: "phone_number", value: "19876543210", type: "default", enabled: true },
      { key: "message_id", value: "", type: "default", enabled: true },
      { key: "otp", value: "", type: "default", enabled: true },
    ],
    _postman_variable_scope: "environment",
  };
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const spec = loadSpec();
  mkdirSync(OUT, { recursive: true });
  const col = join(OUT, "MyOTP.App.postman_collection.json");
  const env = join(OUT, "MyOTP.App.postman_environment.json");
  writeFileSync(col, JSON.stringify(buildCollection(spec), null, 2) + "\n");
  writeFileSync(env, JSON.stringify(buildEnvironment(spec), null, 2) + "\n");
  console.log(`wrote ${col}\nwrote ${env}`);
}
