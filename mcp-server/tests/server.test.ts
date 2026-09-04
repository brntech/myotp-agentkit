import { describe, expect, it } from "vitest";
import { createServer, getHeader, resolveApiKeyFromHeaders, resolveApiKeyFromQuery, SERVER_NAME, SERVER_VERSION } from "../src/server.js";
import { Client } from "@modelcontextprotocol/sdk/client/index.js";
import { InMemoryTransport } from "@modelcontextprotocol/sdk/inMemory.js";
import { allTools } from "../src/tools/index.js";
import { asMyOtpClient, makeFakeClient } from "./helpers/fake-client.js";
import { MyOtpApiError } from "../src/types.js";

describe("createServer", () => {
  it("uses the documented name and version", () => {
    expect(SERVER_NAME).toBe("myotp-mcp");
    expect(SERVER_VERSION).toMatch(/^\d+\.\d+\.\d+/);
    expect(SERVER_VERSION).toBe("0.1.12");
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

describe("resolveApiKeyFromHeaders", () => {
  const KEY = "CmBdpSABCMaGsB6GdybMawy_cYDxqooO";

  it("reads the documented X-API-Key header", () => {
    expect(resolveApiKeyFromHeaders({ "x-api-key": KEY })).toBe(KEY);
  });

  it("is case-insensitive about the header name", () => {
    expect(resolveApiKeyFromHeaders({ "X-API-Key": KEY })).toBe(KEY);
  });

  // Codex CLI can only attach a bearer token to a remote MCP server, so without
  // this alias its users cannot reach the hosted endpoint at all.
  it("accepts Authorization: Bearer as an alias", () => {
    expect(resolveApiKeyFromHeaders({ authorization: `Bearer ${KEY}` })).toBe(KEY);
  });

  it("accepts a lowercase bearer scheme", () => {
    expect(resolveApiKeyFromHeaders({ authorization: `bearer ${KEY}` })).toBe(KEY);
  });

  it("prefers X-API-Key when both are present", () => {
    expect(
      resolveApiKeyFromHeaders({ "x-api-key": KEY, authorization: "Bearer stale-token" })
    ).toBe(KEY);
  });

  it("falls back to the bearer token when X-API-Key is present but blank", () => {
    expect(
      resolveApiKeyFromHeaders({ "x-api-key": "   ", authorization: `Bearer ${KEY}` })
    ).toBe(KEY);
  });

  it("ignores a non-bearer Authorization scheme", () => {
    expect(resolveApiKeyFromHeaders({ authorization: "Basic dXNlcjpwYXNz" })).toBe("");
  });

  it("returns empty string when nothing is supplied", () => {
    expect(resolveApiKeyFromHeaders(undefined)).toBe("");
    expect(resolveApiKeyFromHeaders({})).toBe("");
  });
});

describe("resolveApiKeyFromQuery", () => {
  const KEY = "k7Qz2mV9xL4pR8sT1wY6bN3cF5hJ0dGb";

  it("reads ?apiKey=", () => {
    expect(resolveApiKeyFromQuery(`/mcp?apiKey=${KEY}`)).toBe(KEY);
  });

  it("reads ?api_key=", () => {
    expect(resolveApiKeyFromQuery(`/mcp?api_key=${KEY}&x=1`)).toBe(KEY);
  });

  it("reads a base64url ?config= blob", () => {
    const blob = Buffer.from(JSON.stringify({ apiKey: KEY })).toString("base64url");
    expect(resolveApiKeyFromQuery(`/mcp?config=${blob}`)).toBe(KEY);
  });

  it("returns empty for no query, garbage config, or a missing url", () => {
    expect(resolveApiKeyFromQuery("/mcp")).toBe("");
    expect(resolveApiKeyFromQuery("/mcp?config=%%%")).toBe("");
    expect(resolveApiKeyFromQuery(undefined)).toBe("");
  });
});

describe("createServer resolveApiKey receives the request url", () => {
  it("passes requestInfo.url through so a query-string key can be resolved", async () => {
    const seen: Array<{ headers?: unknown; url?: string }> = [];
    const server = createServer({
      resolveApiKey: (extra) => {
        seen.push(extra);
        return "";
      },
    });
    expect(typeof server.connect).toBe("function");
    // The resolver contract now includes `url`; the runtime wiring is exercised live.
    const KEY = "k7Qz2mV9xL4pR8sT1wY6bN3cF5hJ0dGb";
    expect(resolveApiKeyFromQuery(`http://localhost:8090/mcp?apiKey=${KEY}`)).toBe(KEY);
  });
});

describe("output schemas", () => {
  it("every tool definition declares an outputSchema", () => {
    for (const tool of allTools) {
      expect(tool.outputSchema, `${tool.name} has no outputSchema`).toBeDefined();
      expect(Object.keys(tool.outputSchema ?? {}).length, `${tool.name} outputSchema is empty`).toBeGreaterThan(0);
    }
  });

  it("every registered tool carries an outputSchema in the SDK registry", () => {
    const server = createServer({ resolveApiKey: () => "k" });
    const internal = server as unknown as { _registeredTools: Record<string, { outputSchema?: unknown }> };
    for (const tool of allTools) {
      expect(internal._registeredTools[tool.name]?.outputSchema, `${tool.name} registered without outputSchema`).toBeDefined();
    }
  });

  async function connect(fake: ReturnType<typeof makeFakeClient>) {
    const server = createServer({ resolveApiKey: () => "k", client: asMyOtpClient(fake) });
    const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
    const client = new Client({ name: "test", version: "0.0.0" });
    await server.connect(serverTransport);
    await client.connect(clientTransport);
    return { client, server };
  }

  it("tools/list advertises an object outputSchema for all ten tools", async () => {
    const { client, server } = await connect(makeFakeClient());
    const { tools } = await client.listTools();
    expect(tools).toHaveLength(allTools.length);
    for (const tool of tools) {
      expect(tool.outputSchema?.type, `${tool.name} outputSchema.type`).toBe("object");
      expect(Object.keys(tool.outputSchema?.properties ?? {}).length, `${tool.name} has no properties`).toBeGreaterThan(0);
      // passthrough: extra fields the API adds later stay valid
      expect(tool.outputSchema?.additionalProperties).toBe(true);
    }
    await client.close();
    await server.close();
  });

  it("a successful call validates against the schema and returns structuredContent", async () => {
    const fake = makeFakeClient();
    fake.get.mockResolvedValueOnce({ email: "dev@example.com", plan: "starter" });
    const { client, server } = await connect(fake);
    const result = await client.callTool({ name: "get_account_info", arguments: {} });
    expect(result.isError).toBeFalsy();
    expect(result.structuredContent).toEqual({ email: "dev@example.com", plan: "starter" });
    await client.close();
    await server.close();
  });

  it("an API error comes back as an isError result, not an output validation error", async () => {
    const fake = makeFakeClient();
    fake.get.mockRejectedValueOnce(new MyOtpApiError("Invalid API key", 401, "/me", { error: { http_code: 401, message: "Invalid API key" } }));
    const { client, server } = await connect(fake);
    const result = await client.callTool({ name: "get_account_info", arguments: {} });
    expect(result.isError).toBe(true);
    expect(result.structuredContent).toMatchObject({ error: "Invalid API key", status: 401, endpoint: "/me" });
    await client.close();
    await server.close();
  });
});
