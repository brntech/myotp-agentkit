import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { describe, expect, it } from "vitest";
import { VERSION } from "../../src/lib/version.js";

const packageJsonPath = fileURLToPath(new URL("../../package.json", import.meta.url));

describe("VERSION", () => {
  it("matches the version in package.json", async () => {
    const pkg = JSON.parse(await readFile(packageJsonPath, "utf8")) as { version: string };
    expect(VERSION).toBe(pkg.version);
  });

  it("is a bare semver string, the shape commander prints for --version", () => {
    // `--version` prints this value verbatim, so a prefix like "v" or
    // "@myotp/cli 0.1.6" would change the output every script parses.
    expect(VERSION).toMatch(/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/);
  });
});
