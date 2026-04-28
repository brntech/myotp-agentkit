import { describe, expect, it } from "vitest";
import { createServer, getHeader, SERVER_NAME, SERVER_VERSION } from "../src/server.js";
import { allTools } from "../src/tools/index.js";

describe("createServer", () => {
  it("uses the documented name and version", () => {
    expect(SERVER_NAME).toBe("myotp-mcp");
    expect(SERVER_VERSION).toMatch(/^\d+\.\d+\.\d+/);
  });

  it("returns an McpServer instance with a `connect` method", () => {
    const server = createServer({ resolveApiKey: () => "k" });
    // We don't import the SDK type here directly to keep the test lightweight;
    // a duck-type check is good enough.
    expect(server).toBeDefined();
    expect(typeof (server as unknown as { connect?: unknown }).connect).toBe("function");
  });

  it("registers all of the tools listed in src/tools/index.ts", () => {
    const server = createServer({ resolveApiKey: () => "k" });
    // Reach into the SDK's internal registry via the public-ish `_registeredTools`
    // member. If the SDK ever renames this, this test will fail loudly.
    const internal = server as unknown as { _registeredTools?: Record<string, unknown> };
    expect(internal._registeredTools).toBeDefined();
    const registered = Object.keys(internal._registeredTools ?? {});
    for (const tool of allTools) {
      expect(registered).toContain(tool.name);
    }
  });
});

describe("getHeader", () => {
  it("reads a header case-insensitively", () => {
    expect(getHeader({ "X-API-Key": "k" }, "x-api-key")).toBe("k");
    expect(getHeader({ "x-api-KEY": "k" }, "X-API-Key")).toBe("k");
    expect(getHeader({ Authorization: "Bearer t" }, "authorization")).toBe("Bearer t");
  });

  it("returns the first value when a header is an array", () => {
    expect(getHeader({ "x-api-key": ["a", "b"] }, "x-api-key")).toBe("a");
  });

  it("returns undefined when the header is missing", () => {
    expect(getHeader({ "x-foo": "1" }, "x-bar")).toBeUndefined();
  });

  it("returns undefined when headers is undefined", () => {
    expect(getHeader(undefined, "x-api-key")).toBeUndefined();
  });
});
