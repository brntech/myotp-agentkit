#!/usr/bin/env node
// Parses every .imljson and .json under make/myotp and checks the app shape.
// Exit 1 on any problem. Usage: node validate.mjs
import { readFileSync, readdirSync, statSync, existsSync } from "node:fs";
import { dirname, join, relative } from "node:path";
import { fileURLToPath } from "node:url";
import YAML from "yaml";

const here = dirname(fileURLToPath(import.meta.url));
export const APP_DIR = join(here, "myotp");
export const SPEC_PATH = join(here, "..", "openapi-reference.yaml");

// Error types Make's Communication schema accepts. Complete enum from
// https://developers.make.com/custom-apps-documentation/component-blocks/api
// ("type": Enum[...] under response.error).
export const ERROR_TYPES = new Set([
  "RuntimeError",
  "DataError",
  "RateLimitError",
  "OutOfSpaceError",
  "ConnectionError",
  "InvalidConfigurationError",
  "InvalidAccessTokenError",
  "IncompleteDataError",
  "DuplicateDataError",
]);

// Allowlist for the universal module's path parameter. A leading slash, then
// unreserved and sub-delimiter characters, percent escapes and single slashes,
// with an optional query; no backslash and no "//" anywhere.
export const RELATIVE_PATH_PATTERN = "^(?!.*\\\\)(?!.*//)/[A-Za-z0-9._~:@!$&'()*+,;=%/-]*(\\?.*)?$";

// JS mirror of the IML sanitiser in makeApiCall/api.imljson, used by the tests.
export function sanitisePath(input) {
  let s = String(input).trim();
  s = s.replace(/\\/g, "/");
  s = s.replace(/^[a-z][a-z0-9+.-]*:\/\//i, "");
  s = s.replace(/\/+/g, "/");
  s = s.replace(/^\/+/, "");
  return "/" + s;
}

// Every if( call in an IML string must have exactly three arguments: Make
// documents if(expression, value1, value2) and nothing else.
export function ifArities(text) {
  const out = [];
  const re = /\bif\(/g;
  let m;
  while ((m = re.exec(text))) {
    let depth = 0;
    let commas = 0;
    let quote = null;
    for (let i = m.index + 3; i < text.length; i++) {
      const c = text[i];
      if (quote) {
        if (c === quote) quote = null;
        continue;
      }
      if (c === "'" || c === '"') quote = c;
      else if (c === "(") depth++;
      else if (c === ")") {
        if (depth === 0) break;
        depth--;
      } else if (c === "," && depth === 0) commas++;
    }
    out.push(commas + 1);
  }
  return out;
}

// Every `type` under a response.error block, at any nesting depth.
export function errorTypes(errorBlock, out = []) {
  if (!errorBlock || typeof errorBlock !== "object") return out;
  if (typeof errorBlock.type === "string") out.push(errorBlock.type);
  for (const [k, v] of Object.entries(errorBlock)) if (k !== "type" && v && typeof v === "object") errorTypes(v, out);
  return out;
}

function specOperation(spec, url, method) {
  const path = spec?.paths?.[url];
  return path?.[String(method).toLowerCase()];
}

function requestSchema(op) {
  return op?.requestBody?.content?.["application/json"]?.schema;
}

export function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (/\.(imljson|json)$/.test(name)) out.push(p);
  }
  return out;
}

export function loadJson(path) {
  return JSON.parse(readFileSync(path, "utf8"));
}

const MODULE_FILES = ["metadata.json", "api.imljson", "expect.imljson", "interface.imljson", "samples.imljson"];

export function validateApp(appDir = APP_DIR, specPath = SPEC_PATH) {
  const problems = [];
  const check = (ok, msg) => {
    if (!ok) problems.push(msg);
  };
  const spec = existsSync(specPath) ? YAML.parse(readFileSync(specPath, "utf8")) : null;
  check(spec, `spec not found at ${specPath}`);
  const checkErrorTypes = (block, where) => {
    for (const t of errorTypes(block)) check(ERROR_TYPES.has(t), `${where}: error type ${t} is not one of ${[...ERROR_TYPES].join(", ")}`);
  };

  // 1. Every file parses.
  const parsed = new Map();
  for (const file of walk(appDir)) {
    try {
      parsed.set(file, loadJson(file));
    } catch (e) {
      problems.push(`${relative(appDir, file)}: ${e.message}`);
    }
  }
  if (problems.length) return problems;
  for (const [file, json] of parsed) {
    for (const n of ifArities(JSON.stringify(json))) {
      check(n === 3, `${relative(appDir, file)}: if() has ${n} argument(s), Make documents exactly three`);
    }
  }

  // 2. Base.
  const base = parsed.get(join(appDir, "base.imljson"));
  check(base?.baseUrl === "https://api.myotp.app", "base.imljson: baseUrl must be https://api.myotp.app");
  check(base?.headers?.["X-API-Key"] === "{{connection.apiKey}}", "base.imljson: X-API-Key must come from connection.apiKey");
  check(typeof base?.response?.error?.message === "string" && base.response.error.message.includes("body.error.message"),
    "base.imljson: error message must surface body.error.message");
  check(base?.log?.sanitize?.includes("request.headers.x-api-key"), "base.imljson: must sanitize the API key header");
  checkErrorTypes(base?.response?.error, "base.imljson");

  // 3. Connection.
  const connDir = join(appDir, "connections", "myotp");
  const connMeta = parsed.get(join(connDir, "metadata.json"));
  const connParams = parsed.get(join(connDir, "parameters.imljson"));
  const connApi = parsed.get(join(connDir, "api.imljson"));
  check(connMeta?.type === "apikey", "connection: type must be apikey");
  check(connParams?.some((p) => p.name === "apiKey" && p.type === "password" && p.required), "connection: apiKey password parameter missing");
  check(connApi?.url === "https://api.myotp.app/me" && connApi?.method === "GET", "connection: must validate with GET /me");
  check(connApi?.headers?.["X-API-Key"] === "{{parameters.apiKey}}", "connection: X-API-Key must come from parameters.apiKey");
  check(connApi?.response?.metadata?.value === "{{body.email}}", "connection: metadata should show body.email");
  checkErrorTypes(connApi?.response?.error, "connection api.imljson");

  // 4. Modules.
  const modulesDir = join(appDir, "modules");
  const moduleNames = readdirSync(modulesDir);
  for (const name of moduleNames) {
    const dir = join(modulesDir, name);
    for (const f of MODULE_FILES) check(existsSync(join(dir, f)), `module ${name}: missing ${f}`);
    const meta = parsed.get(join(dir, "metadata.json"));
    const api = parsed.get(join(dir, "api.imljson"));
    const expect = parsed.get(join(dir, "expect.imljson"));
    const iface = parsed.get(join(dir, "interface.imljson"));
    const samples = parsed.get(join(dir, "samples.imljson"));
    check(meta?.name === name, `module ${name}: metadata name must match folder`);
    check(typeof meta?.label === "string" && meta.label, `module ${name}: label missing`);
    check(typeof meta?.description === "string" && meta.description, `module ${name}: description missing`);
    check(meta?.connection === "myotp", `module ${name}: must use the myotp connection`);
    check(["action", "universal"].includes(meta?.type), `module ${name}: type must be action or universal`);
    check(typeof api?.url === "string" && api.url, `module ${name}: api url missing`);
    check(api?.response?.output !== undefined, `module ${name}: response.output missing`);
    checkErrorTypes(api?.response?.error, `module ${name}`);
    if (meta?.type === "universal") {
      // Make's security rule: universal modules must stay relative to baseUrl, and a
      // programmatic header collection must be merged with the base headers or the
      // X-API-Key from the base is lost.
      const u = api?.url ?? "";
      check(u.startsWith("/"), `module ${name}: universal url must start with / so baseUrl always applies`);
      check(/replace\(.*parameters\.url/.test(u), `module ${name}: universal url must sanitise parameters.url`);
      // The parsed IML string (JSON escapes already resolved) must contain these
      // exact replace() steps. String.raw keeps the backslashes readable.
      check(u.includes(String.raw`'/\\/g', '/'`), `module ${name}: universal url must turn every backslash into /`);
      check(u.includes(String.raw`'/\/+/g', '/'`), `module ${name}: universal url must collapse repeated slashes`);
      check(u.includes(String.raw`'/^[a-z][a-z0-9+.-]*:\/\//i', ''`), `module ${name}: universal url must strip a scheme`);
      check(u.includes(String.raw`'/^\/+/', ''`), `module ${name}: universal url must strip leading slashes before the fixed /`);
      check(api?.headers && typeof api.headers === "object" && "{{...}}" in api.headers, `module ${name}: headers must merge with the base via the {{...}} form`);
      const urlParam = (expect ?? []).find((p) => p.name === "url");
      check(urlParam?.validate?.pattern === RELATIVE_PATH_PATTERN, `module ${name}: url parameter must validate the relative-path allowlist`);
      check(urlParam?.validate?.message === "path must be relative to https://api.myotp.app", `module ${name}: url validation message`);
    } else if (spec && typeof api?.url === "string") {
      // Action modules: every field the spec requires must be declared, and required.
      const op = specOperation(spec, api.url, api.method ?? "GET");
      check(op, `module ${name}: ${api.method} ${api.url} is not in the spec`);
      const declared = new Map((expect ?? []).map((p) => [p.name, p]));
      for (const r of requestSchema(op)?.required ?? []) {
        check(declared.has(r), `module ${name}: spec requires ${r} but expect.imljson does not declare it`);
        check(declared.get(r)?.required === true, `module ${name}: ${r} must be marked required`);
      }
    }
    check(Array.isArray(expect), `module ${name}: expect must be an array`);
    check(Array.isArray(iface) && iface.length > 0, `module ${name}: interface must be a non-empty array`);
    for (const p of expect ?? []) {
      check(p.name && p.type && p.label, `module ${name}: parameter without name/type/label`);
    }
    for (const f of iface ?? []) {
      check(f.name && f.type && f.label, `module ${name}: interface field without name/type/label`);
    }
    // Every mapped body/qs parameter must be declared in expect.
    const declared = new Set((expect ?? []).map((p) => p.name));
    const used = JSON.stringify(api).match(/parameters\.([A-Za-z_]+)/g) ?? [];
    for (const u of used) {
      const p = u.slice("parameters.".length);
      check(declared.has(p), `module ${name}: api references undeclared parameter ${p}`);
    }
    // Every output key must be described in the interface.
    if (api?.response?.output && typeof api.response.output === "object") {
      const ifaceNames = new Set((iface ?? []).map((f) => f.name));
      for (const k of Object.keys(api.response.output)) {
        check(ifaceNames.has(k), `module ${name}: output ${k} not in interface`);
      }
      // Samples only carry keys the interface knows.
      for (const k of Object.keys(samples ?? {})) check(ifaceNames.has(k), `module ${name}: sample key ${k} not in interface`);
    }
  }

  // 5. Groups reference real modules.
  const groups = parsed.get(join(appDir, "groups.imljson"));
  for (const g of groups ?? []) {
    for (const m of g.modules) check(moduleNames.includes(m), `groups: unknown module ${m}`);
  }

  // 6. App metadata for listing.
  const app = parsed.get(join(appDir, "app.json"));
  check(typeof app?.description === "string" && app.description.length <= 200, "app.json: description must be 200 chars or fewer");
  check(/^#[0-9a-fA-F]{6}$/.test(app?.theme ?? ""), "app.json: theme must be a hex colour");

  return problems;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const problems = validateApp();
  if (problems.length) {
    console.error(problems.join("\n"));
    process.exit(1);
  }
  console.log(`ok: ${walk(APP_DIR).length} files parsed, ${readdirSync(join(APP_DIR, "modules")).length} modules`);
}
