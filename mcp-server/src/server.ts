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
  resolveApiKey: (extra: { headers?: Record<string, string | string[] | undefined> }) => string;
  client?: MyOtpClient;
}

export const SERVER_NAME = "myotp-mcp";
export const SERVER_VERSION = "0.1.0";

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
        "All operations require a MyOTP API key — get one at https://myotp.app/sign-up/ (15 free trial credits, no card required). " +
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

        const apiKey = options.resolveApiKey({ headers: headers ?? {} });

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
