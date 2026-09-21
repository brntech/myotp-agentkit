/**
 * Guards on the two files the MCP registry reads: package.json and server.json.
 *
 * Every rule here is one the registry enforces at publish time and nowhere
 * earlier, so a mismatch surfaces as a rejected publish after npm has already
 * shipped the package.
 */

import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";

const read = async (name: string): Promise<Record<string, unknown>> =>
  JSON.parse(await readFile(fileURLToPath(new URL(`../${name}`, import.meta.url)), "utf8"));

describe("registry metadata", () => {
  it("keeps the package.json and server.json versions equal", async () => {
    const pkg = await read("package.json");
    const server = await read("server.json");
    const packages = server.packages as Array<Record<string, unknown>>;

    expect(server.version).toBe(pkg.version);
    expect(packages[0]?.version).toBe(pkg.version);
  });

  it("matches package.json mcpName to the server.json name", async () => {
    const pkg = await read("package.json");
    const server = await read("server.json");

    // The registry verifies ownership by reading mcpName out of the published
    // npm package, so these two must agree and npm must publish first.
    expect(pkg.mcpName).toBe(server.name);
  });

  it("keeps the server.json description within the registry's 100-character limit", async () => {
    const server = await read("server.json");

    // Not in the published schema; a longer description is only rejected at
    // publish time, as a 422.
    expect(String(server.description).length).toBeLessThanOrEqual(100);
  });

  it("points the npm package entry at the published package name", async () => {
    const pkg = await read("package.json");
    const server = await read("server.json");
    const packages = server.packages as Array<Record<string, unknown>>;

    expect(packages[0]?.identifier).toBe(pkg.name);
  });
});
