#!/usr/bin/env node
// Structural check of the generated collection and environment. Exit 1 on any problem.
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const col = JSON.parse(readFileSync(join(here, "MyOTP.App.postman_collection.json"), "utf8"));
const env = JSON.parse(readFileSync(join(here, "MyOTP.App.postman_environment.json"), "utf8"));
const problems = [];
const check = (ok, msg) => {
  if (!ok) problems.push(msg);
};

check(col.info?.schema?.includes("v2.1.0"), "collection is not v2.1");
check(col.auth?.type === "apikey", "collection auth is not apikey");
check(col.auth?.apikey?.some((a) => a.key === "value" && a.value === "{{api_key}}"), "auth not bound to {{api_key}}");
check(col.variable?.some((v) => v.key === "base_url" && v.value === "https://api.myotp.app"), "base_url variable missing");
check(Array.isArray(col.item) && col.item.length > 0, "collection has no items");
for (const it of col.item ?? []) {
  check(typeof it.name === "string" && it.name, "item without name");
  check(["GET", "POST"].includes(it.request?.method), `bad method on ${it.name}`);
  check(it.request?.url?.raw?.startsWith("{{base_url}}/"), `url not on {{base_url}}: ${it.name}`);
  if (it.request?.body?.mode === "raw") {
    try {
      JSON.parse(it.request.body.raw);
    } catch {
      problems.push(`body not JSON: ${it.name}`);
    }
  }
}
const send = col.item.find((i) => i.request.url.path.join("/") === "generate_otp");
check(send?.event?.[0]?.script?.exec?.join("\n").includes('set("message_id"'), "Send OTP does not store message_id");
const verify = col.item.find((i) => i.request.url.path.join("/") === "verify_otp");
check(verify?.request?.body?.raw?.includes("{{message_id}}"), "Verify OTP does not use {{message_id}}");
check(env.values?.some((v) => v.key === "api_key" && v.type === "secret"), "environment api_key is not secret");
// Environment scope beats collection scope, so a script-set variable must not exist there.
const scriptSet = new Set();
for (const it of col.item ?? []) {
  for (const line of it.event?.[0]?.script?.exec ?? []) {
    for (const m of line.matchAll(/collectionVariables\.set\("([^"]+)"/g)) scriptSet.add(m[1]);
  }
}
for (const v of env.values ?? []) check(!scriptSet.has(v.key), `environment shadows script-set variable ${v.key}`);

if (problems.length) {
  console.error(problems.join("\n"));
  process.exit(1);
}
console.log(`ok: ${col.item.length} requests, ${env.values.length} environment values`);
