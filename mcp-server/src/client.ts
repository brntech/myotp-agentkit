/**
 * Thin HTTP client for the MyOTP.App REST API.
 *
 * Auth: every request must carry an `X-API-Key` header. We don't bake the key in;
 * callers (tool handlers) hand it in per-request so we can support both stdio
 * (single key from env) and HTTP (per-request header forwarded from the agent)
 * transport modes with the same client.
 */

import { MyOtpApiError } from "./types.js";

export interface MyOtpClientOptions {
  /** Base URL of the MyOTP API. Defaults to env `MYOTP_BASE_URL` or production. */
  baseUrl?: string;
  /** Override the User-Agent sent on each request. */
  userAgent?: string;
  /** Per-request timeout in milliseconds. Default 30s. */
  timeoutMs?: number;
}

const DEFAULT_BASE_URL = "https://api.myotp.app";
const DEFAULT_TIMEOUT_MS = 30_000;

export class MyOtpClient {
  private readonly baseUrl: string;
  private readonly userAgent: string;
  private readonly timeoutMs: number;

  constructor(options: MyOtpClientOptions = {}) {
    const envBase = process.env.MYOTP_BASE_URL?.trim();
    this.baseUrl = (options.baseUrl ?? envBase ?? DEFAULT_BASE_URL).replace(/\/+$/, "");
    this.userAgent = options.userAgent ?? "myotp-mcp/0.1.0";
    this.timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
  }

  /** POST a JSON body and parse the JSON response. Throws MyOtpApiError on failure. */
  async post<T>(path: string, body: Record<string, unknown>, apiKey: string): Promise<T> {
    return this.request<T>("POST", path, body, apiKey);
  }

  /** GET an endpoint and parse the JSON response. Throws MyOtpApiError on failure. */
  async get<T>(path: string, apiKey: string): Promise<T> {
    return this.request<T>("GET", path, undefined, apiKey);
  }

  private async request<T>(
    method: "GET" | "POST",
    path: string,
    body: Record<string, unknown> | undefined,
    apiKey: string
  ): Promise<T> {
    if (!apiKey || apiKey.trim() === "") {
      throw new MyOtpApiError(
        "Missing MyOTP API key. In stdio mode, set MYOTP_API_KEY in your MCP server config. In HTTP mode, send the X-API-Key header on every request.",
        401,
        path,
        null
      );
    }

    const url = `${this.baseUrl}${path.startsWith("/") ? path : `/${path}`}`;
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), this.timeoutMs);

    let response: Response;
    try {
      response = await fetch(url, {
        method,
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-API-Key": apiKey,
          "User-Agent": this.userAgent,
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
        signal: controller.signal,
      });
    } catch (err) {
      if (err instanceof Error && err.name === "AbortError") {
        throw new MyOtpApiError(
          `Request to ${path} timed out after ${this.timeoutMs}ms.`,
          408,
          path,
          null
        );
      }
      const msg = err instanceof Error ? err.message : String(err);
      throw new MyOtpApiError(`Network error calling ${url}: ${msg}`, 0, path, null);
    } finally {
      clearTimeout(timer);
    }

    const rawText = await response.text();
    let parsed: unknown = null;
    if (rawText.length > 0) {
      try {
        parsed = JSON.parse(rawText);
      } catch {
        parsed = rawText;
      }
    }

    if (!response.ok) {
      const message = extractErrorMessage(parsed, response.status, response.statusText);
      throw new MyOtpApiError(message, response.status, path, parsed);
    }

    return parsed as T;
  }
}

/**
 * Pull a human-readable error message out of a MyOTP error response.
 * Flask's `abort(code, "msg")` returns either JSON `{message|error|description: "msg"}` or HTML.
 */
function extractErrorMessage(body: unknown, status: number, statusText: string): string {
  if (body && typeof body === "object") {
    const obj = body as Record<string, unknown>;
    for (const key of ["message", "error", "description", "detail"]) {
      const v = obj[key];
      if (typeof v === "string" && v.trim().length > 0) {
        return `MyOTP API error (${status}): ${v}`;
      }
    }
  }
  if (typeof body === "string" && body.trim().length > 0 && !body.includes("<html")) {
    return `MyOTP API error (${status}): ${body}`;
  }
  return `MyOTP API error (${status} ${statusText || ""}).`.trim();
}
