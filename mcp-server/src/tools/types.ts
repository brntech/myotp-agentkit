/**
 * Shared types for tool definitions.
 *
 * Each tool exports a `ToolDefinition`. server.ts iterates these and calls
 * `mcpServer.registerTool` with the provided shape — this keeps the registration
 * site declarative and one-file-per-tool.
 */

import type { ZodRawShape } from "zod";
import type { MyOtpClient } from "../client.js";

/**
 * Context passed to every tool handler. Includes the API key resolved for the
 * current request (from env in stdio mode, from `X-API-Key` header in HTTP mode).
 */
export interface ToolContext {
  client: MyOtpClient;
  apiKey: string;
}

/**
 * The structured content returned by an MCP tool call. We mirror the SDK's
 * `CallToolResult` shape minimally to keep this file decoupled from the SDK
 * deep imports — the actual SDK types are applied at the registration site.
 */
export interface ToolResult {
  content: Array<{ type: "text"; text: string }>;
  structuredContent?: Record<string, unknown>;
  isError?: boolean;
}

/**
 * Generic tool definition. `inputSchema` is a Zod raw shape (an object literal
 * of Zod schemas) — that's what `McpServer.registerTool` expects.
 */
export interface ToolDefinition<Shape extends ZodRawShape> {
  name: string;
  title: string;
  description: string;
  inputSchema: Shape;
  /**
   * Optional MCP annotations. We use `readOnlyHint` and `idempotentHint` to give
   * agents hints about whether they can safely retry or cache calls.
   */
  annotations?: {
    readOnlyHint?: boolean;
    idempotentHint?: boolean;
    destructiveHint?: boolean;
    openWorldHint?: boolean;
  };
  handler: (args: Record<string, unknown>, ctx: ToolContext) => Promise<ToolResult>;
}
