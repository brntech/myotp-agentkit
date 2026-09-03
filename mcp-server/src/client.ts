/**
 * Thin HTTP client for the MyOTP.App REST API.
 *
 * Auth: authenticated requests carry an `X-API-Key` header. We don't bake the
 * key in; callers hand it in per request. The public top-up quote is the sole
 * anonymous request exposed by this client.
 */

import { MyOtpApiError } from "./types.js";
import type {
  TopUpFetchResponse,
  TopUpPaymentRequiredResponse,
  TopUpQuoteResponse,
  TopUpCreditsResponse,
} from "./types.js";

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

  /** Fetch a public top-up quote without sending an API key. */
  async getTopUpQuote(credits: number): Promise<TopUpQuoteResponse> {
    return this.request<TopUpQuoteResponse>(
      "GET",
      `/v1/topup/quote?credits=${encodeURIComponent(String(credits))}`,
      undefined,
      "",
      false
    );
  }

  /**
   * Ask the top-up endpoint to credit or challenge the request. A 402 is an
   * expected result here, so preserve its WWW-Authenticate header for the tool.
   */
  async requestTopUp(credits: number, apiKey: string): Promise<TopUpFetchResponse> {
    const path = "/v1/topup";
    const { response, parsed, url } = await this.fetchJson(
      "POST",
      path,
      { credits },
      apiKey,
      true
    );

    if (response.status === 200) {
      return {
        status: 200,
        url,
        body: parsed as TopUpCreditsResponse,
      };
    }

    if (response.status === 402) {
      return {
        status: 402,
        url,
        body: parsed as TopUpPaymentRequiredResponse,
        wwwAuthenticate: response.headers.get("www-authenticate") ?? "",
      };
    }

    throw makeApiError(response, path, parsed);
  }

  private async request<T>(
    method: "GET" | "POST",
    path: string,
    body: Record<string, unknown> | undefined,
    apiKey: string,
    requireApiKey = true
  ): Promise<T> {
    const { response, parsed } = await this.fetchJson(
      method,
      path,
      body,
      apiKey,
      requireApiKey
    );

    if (!response.ok) {
      throw makeApiError(response, path, parsed);
    }

    return parsed as T;
  }

  private async fetchJson(
    method: "GET" | "POST",
    path: string,
    body: Record<string, unknown> | undefined,
    apiKey: string,
    requireApiKey: boolean
  ): Promise<{ response: Response; parsed: unknown; url: string }> {
    if (requireApiKey && (!apiKey || apiKey.trim() === "")) {
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
    const headers: Record<string, string> = {
      "Content-Type": "application/json",
      "Accept": "application/json",
      "User-Agent": this.userAgent,
    };
    if (requireApiKey) {
      headers["X-API-Key"] = apiKey;
    }

    let response: Response;
    try {
      response = await fetch(url, {
        method,
        headers,
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

    return { response, parsed, url };
  }
}

function makeApiError(response: Response, path: string, body: unknown): MyOtpApiError {
  const message = extractErrorMessage(body, response.status, response.statusText);
  return new MyOtpApiError(message, response.status, path, body);
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

    const nestedError = obj.error;
    if (nestedError && typeof nestedError === "object") {
      const nested = nestedError as Record<string, unknown>;
      for (const key of ["message", "description", "detail"]) {
        const v = nested[key];
        if (typeof v === "string" && v.trim().length > 0) {
          return `MyOTP API error (${status}): ${v}`;
        }
      }
    }
  }
  if (typeof body === "string" && body.trim().length > 0 && !body.includes("<html")) {
    return `MyOTP API error (${status}): ${body}`;
  }
  return `MyOTP API error (${status} ${statusText || ""}).`.trim();
}
