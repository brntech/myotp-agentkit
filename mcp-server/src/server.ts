/**
 * Builds the MCP server instance and registers all MyOTP tools.
 *
 * The API key is resolved per-tool-call via `resolveApiKey`, so the same server
 * instance can serve a stdio process (env-based key) or many HTTP requests
 * (header-based key) without leaking keys across requests.
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import { MyOtpClient } from "./client.js";
import { allTools } from "./tools/index.js";
import type { ToolContext } from "./tools/types.js";

export interface ServerOptions {
  /**
   * Resolves the MyOTP API key for a given request.
   * - stdio mode passes a function that always returns the env var.
   * - HTTP mode passes a function that reads `X-API-Key` from the current request.
   */
  resolveApiKey: (extra: {
    headers?: Record<string, string | string[] | undefined>;
    url?: string;
  }) => string;
  client?: MyOtpClient;
}

export const SERVER_NAME = "myotp-mcp";
import { createRequire } from "node:module";
// Read from package.json so the version a client sees in initialize() can never
// lag the published version again (0.1.8 and 0.1.9 still announced 0.1.7).
export const SERVER_VERSION: string = (createRequire(import.meta.url)("../package.json") as { version: string }).version;

export function createServer(options: ServerOptions): McpServer {
  const client = options.client ?? new MyOtpClient();

  const server = new McpServer(
    { name: SERVER_NAME, version: SERVER_VERSION },
    {
      instructions:
        "MyOTP.App MCP server — send and verify OTPs over SMS, WhatsApp, and Telegram. " +
        "Typical flow: call `generate_otp` with a phone number to send a code, save the returned `message_id`, " +
        "then call `verify_otp` with the code the end user typed. Use `check_otp_status` to debug delivery, " +
        "`extend_otp` to give users more time, and `get_usage_report` for transaction history. " +
        "Every tool except `create_account` needs a MyOTP API key. No key yet? Call `create_account` (zero balance, then `top_up_credits`), " +
        "or a human can sign up at https://myotp.app/sign-up/ for 15 free trial credits. " +
        "Phone numbers must be in international format with no leading + or 0 (e.g. '14155551234' for a US number).",
    }
  );

  for (const tool of allTools) {
    // Build a Zod object schema from the raw shape so the SDK gets a proper schema
    // (the SDK accepts either a ZodRawShape or a full Zod schema; we use the schema
    // form here for explicitness and so unknown keys are rejected).
    const inputSchema = z.object(tool.inputSchema);

    server.registerTool(
      tool.name,
      {
        title: tool.title,
        description: tool.description,
        inputSchema: tool.inputSchema,
        annotations: tool.annotations ?? {},
      },
      async (args, extra) => {
        // The SDK has already validated `args` against `tool.inputSchema`. Re-parse
        // here as defense-in-depth (and to coerce types for handlers that consume
        // args as Record<string, unknown>).
        const parsed = inputSchema.parse(args ?? {});

        // Pull headers off the original HTTP request (HTTP mode) or fall back to
        // an empty object (stdio mode — the resolver will use the env var).
        const headers = extra.requestInfo?.headers as
          | Record<string, string | string[] | undefined>
          | undefined;

        // The SDK also carries the request URL; gateways that pass config in the
        // query string (Smithery) are handled by the resolver through it.
        const url = extra.requestInfo?.url;
        const apiKey = options.resolveApiKey({
          headers: headers ?? {},
          url: url === undefined ? undefined : String(url),
        });

        const ctx: ToolContext = { client, apiKey };
        const result = await tool.handler(parsed as Record<string, unknown>, ctx);

        // The SDK's CallToolResult type accepts our shape directly.
        return {
          content: result.content,
          ...(result.structuredContent ? { structuredContent: result.structuredContent } : {}),
          ...(result.isError ? { isError: true } : {}),
        };
      }
    );
  }

  return server;
}

/**
 * Read a header value case-insensitively from a Node-style headers object.
 * Useful for resolveApiKey implementations.
 */
/**
 * Pick the MyOTP API key out of a request's headers.
 *
 * `X-API-Key` is the documented header and matches the REST API. `Authorization:
 * Bearer <key>` is accepted as an alias because several MCP clients can only
 * attach a bearer token to a remote server, never an arbitrary header: OpenAI's
 * Codex CLI takes a `bearer_token_env_var` and nothing else, so without this
 * alias a Codex user cannot reach the hosted endpoint at all.
 *
 * X-API-Key wins when both are present, so an explicit key is never overridden
 * by a stale Authorization header some proxy attached.
 */
export function resolveApiKeyFromHeaders(
  headers: Record<string, string | string[] | undefined> | undefined
): string {
  const direct = getHeader(headers, "x-api-key");
  if (direct && direct.trim() !== "") return direct.trim();

  const auth = getHeader(headers, "authorization") ?? "";
  const bearer = /^Bearer\s+(.+)$/i.exec(auth.trim());
  return bearer ? bearer[1].trim() : "";
}

/**
 * Some MCP gateways (Smithery is one) deliver per-user configuration as URL
 * query parameters instead of headers. Accept `?apiKey=` / `?api_key=` and the
 * base64url `?config=` JSON form as a fallback when no header carried a key.
 * Headers always win, so a key in the URL can never override an explicit one.
 */
export function resolveApiKeyFromQuery(url: string | undefined): string {
  if (!url) return "";
  let params: URLSearchParams;
  try {
    params = new URL(url, "http://localhost").searchParams;
  } catch {
    return "";
  }
  const direct = params.get("apiKey") ?? params.get("api_key");
  if (direct && direct.trim() !== "") return direct.trim();
  const packed = params.get("config");
  if (packed) {
    try {
      const json = Buffer.from(packed, "base64url").toString("utf8");
      const parsed = JSON.parse(json) as Record<string, unknown>;
      const value = parsed.apiKey ?? parsed.api_key;
      if (typeof value === "string" && value.trim() !== "") return value.trim();
    } catch {
      /* not a config blob we understand */
    }
  }
  return "";
}

export function getHeader(
  headers: Record<string, string | string[] | undefined> | undefined,
  name: string
): string | undefined {
  if (!headers) return undefined;
  const lower = name.toLowerCase();
  for (const [key, value] of Object.entries(headers)) {
    if (key.toLowerCase() === lower) {
      if (Array.isArray(value)) return value[0];
      return value;
    }
  }
  return undefined;
}
