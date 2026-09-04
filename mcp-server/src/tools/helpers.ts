/**
 * Helpers shared across tool handlers — error formatting and result construction.
 */

import { z } from "zod";
import { MyOtpApiError } from "../types.js";
import type { ToolResult } from "./types.js";

/**
 * The `structuredContent` every `isError` result carries (built by `toToolError`).
 * `status`, `endpoint` and `body` are present only for MyOtpApiError; `body` is
 * `null` when the API answered with an empty or non-JSON body.
 */
export const toolErrorShape = {
  error: z.string().describe("Human-readable error message."),
  status: z.number().int().optional().describe("HTTP status the MyOTP API answered with."),
  endpoint: z.string().optional().describe("API path that failed, e.g. /generate_otp."),
  body: z.unknown().optional().describe("Parsed JSON error body from the API, or null."),
};

export const toolErrorSchema = z.object(toolErrorShape).passthrough();

/** Wrap a successful API response in a ToolResult, with both text and structured payloads. */
export function ok(data: Record<string, unknown>, summary: string): ToolResult {
  return {
    content: [
      { type: "text", text: `${summary}\n\n${JSON.stringify(data, null, 2)}` },
    ],
    structuredContent: data,
  };
}

/** Convert a MyOtpApiError (or unknown error) into a structured tool error. */
export function toToolError(err: unknown, fallbackPrefix: string): ToolResult {
  if (err instanceof MyOtpApiError) {
    return {
      content: [
        {
          type: "text",
          text: `${fallbackPrefix}: ${err.message}`,
        },
      ],
      structuredContent: {
        error: err.message,
        status: err.status,
        endpoint: err.endpoint,
        body: err.body ?? null,
      },
      isError: true,
    };
  }
  const msg = err instanceof Error ? err.message : String(err);
  return {
    content: [{ type: "text", text: `${fallbackPrefix}: ${msg}` }],
    structuredContent: { error: msg },
    isError: true,
  };
}

/** Build a JSON request body, dropping any keys whose value is `undefined`. */
export function compact(obj: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(obj)) {
    if (v !== undefined) out[k] = v;
  }
  return out;
}
