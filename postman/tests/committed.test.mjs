import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { loadSpec, buildCollection, buildEnvironment } from "../build.mjs";

// The committed JSON is what people import. It must be exactly what build.mjs
// produces from the spec, or the two drift and the tests prove nothing about it.
test("committed collection and environment match the generator output", () => {
  const spec = loadSpec();
  const col = JSON.parse(readFileSync(new URL("../MyOTP.App.postman_collection.json", import.meta.url), "utf8"));
  const env = JSON.parse(readFileSync(new URL("../MyOTP.App.postman_environment.json", import.meta.url), "utf8"));
  assert.deepEqual(col, JSON.parse(JSON.stringify(buildCollection(spec))));
  assert.deepEqual(env, JSON.parse(JSON.stringify(buildEnvironment(spec))));
});
