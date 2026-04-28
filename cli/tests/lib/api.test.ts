import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MyOtpApiError, MyOtpClient, MyOtpNetworkError } from "../../src/lib/api.js";

interface MockOpts {
  status?: number;
  statusText?: string;
  body?: unknown;
  textBody?: string;
}

function makeResponse(opts: MockOpts = {}): Response {
  const status = opts.status ?? 200;
  const text =
    opts.textBody !== undefined
      ? opts.textBody
      : opts.body !== undefined
        ? JSON.stringify(opts.body)
        : "";
  return {
    ok: status >= 200 && status < 300,
    status,
    statusText: opts.statusText ?? "OK",
    headers: new Headers(),
    text: async () => text,
    json: async () => JSON.parse(text),
  } as unknown as Response;
}

interface CapturedCall {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: string | undefined;
}

function captureFetch(response: MockOpts | (() => Promise<Response>)): {
  calls: CapturedCall[];
  fetchImpl: typeof fetch;
} {
  const calls: CapturedCall[] = [];
  const fetchImpl = (async (url: string, init?: RequestInit) => {
    const headers: Record<string, string> = {};
    const headerInput = init?.headers;
    if (headerInput) {
      if (headerInput instanceof Headers) {
        headerInput.forEach((v, k) => {
          headers[k] = v;
        });
      } else {
        for (const [k, v] of Object.entries(headerInput as Record<string, string>)) {
          headers[k] = v;
        }
      }
    }
    calls.push({
      url: String(url),
      method: String(init?.method ?? "GET"),
      headers,
      body: typeof init?.body === "string" ? init.body : undefined,
    });
    if (typeof response === "function") return response();
    return makeResponse(response);
  }) as unknown as typeof fetch;
  return { calls, fetchImpl };
}

describe("MyOtpClient — base URL", () => {
  it("strips trailing slashes from baseUrl", async () => {
    const { calls, fetchImpl } = captureFetch({ body: {} });
    const c = new MyOtpClient({ baseUrl: "https://api.example.com////", apiKey: "k", fetchImpl });
    await c.me();
    expect(calls[0]?.url).toBe("https://api.example.com/me");
  });

  it("appends path with no extra slashes", async () => {
    const { calls, fetchImpl } = captureFetch({ body: {} });
    const c = new MyOtpClient({ baseUrl: "https://api.example.com", apiKey: "k", fetchImpl });
    await c.generateOtp({ phone_number: "14155551234" });
    expect(calls[0]?.url).toBe("https://api.example.com/generate_otp");
  });
});

describe("MyOtpClient — headers", () => {
  it("sends X-API-Key when apiKey is provided", async () => {
    const { calls, fetchImpl } = captureFetch({ body: {} });
    const c = new MyOtpClient({ baseUrl: "https://api.example.com", apiKey: "secret", fetchImpl });
    await c.me();
    expect(calls[0]?.headers["X-API-Key"]).toBe("secret");
  });

  it("omits X-API-Key when no apiKey is given (e.g. register)", async () => {
    const { calls, fetchImpl } = captureFetch({ body: {} });
    const c = new MyOtpClient({ baseUrl: "https://api.example.com", fetchImpl });
    await c.register({ email: "a@b.com", phone: "14155551234", company_name: "X", source: "cli" });
    expect("X-API-Key" in (calls[0]?.headers ?? {})).toBe(false);
  });

  it("sends a Content-Type header on POST but not GET", async () => {
    const { calls, fetchImpl } = captureFetch({ body: {} });
    const c = new MyOtpClient({ baseUrl: "https://api.example.com", apiKey: "k", fetchImpl });
    await c.me(); // GET
    await c.generateOtp({ phone_number: "14155551234" }); // POST
    expect(calls[0]?.headers["Content-Type"]).toBeUndefined();
    expect(calls[1]?.headers["Content-Type"]).toBe("application/json");
  });

  it("appends userAgentSuffix to the User-Agent", async () => {
    const { calls, fetchImpl } = captureFetch({ body: {} });
    const c = new MyOtpClient({
      baseUrl: "https://api.example.com",
      apiKey: "k",
      fetchImpl,
      userAgentSuffix: "0.1.0",
    });
    await c.me();
    expect(calls[0]?.headers["User-Agent"]).toBe("@myotp/cli/0.1.0");
  });
});

describe("MyOtpClient — error responses", () => {
  it("throws MyOtpApiError with status on 4xx with a JSON message", async () => {
    const { fetchImpl } = captureFetch({ status: 400, body: { message: "Bad phone" } });
    const c = new MyOtpClient({ baseUrl: "https://api.example.com", apiKey: "k", fetchImpl });
    try {
      await c.generateOtp({ phone_number: "00000" });
      throw new Error("expected throw");
    } catch (err) {
      expect(err).toBeInstanceOf(MyOtpApiError);
      expect((err as MyOtpApiError).status).toBe(400);
      expect((err as MyOtpApiError).message).toBe("Bad phone");
    }
  });

  it("throws on 5xx with a synthesized message when body is empty", async () => {
    const { fetchImpl } = captureFetch({ status: 503 });
    const c = new MyOtpClient({ baseUrl: "https://api.example.com", apiKey: "k", fetchImpl });
    await expect(c.me()).rejects.toMatchObject({ status: 503 });
  });

  it("does not echo the API key in error messages", async () => {
    const { fetchImpl } = captureFetch({
      status: 401,
      body: { message: "auth failed" },
    });
    const c = new MyOtpClient({
      baseUrl: "https://api.example.com",
      apiKey: "k_supersecret_value_1",
      fetchImpl,
    });
    let captured: unknown;
    try {
      await c.me();
    } catch (e) {
      captured = e;
    }
    expect(captured).toBeInstanceOf(MyOtpApiError);
    expect((captured as Error).message).not.toContain("k_supersecret_value_1");
    // Body and endpoint should still be available for the renderer, but the
    // bare message must not leak the key.
    expect(JSON.stringify((captured as MyOtpApiError).body)).not.toContain("k_supersecret_value_1");
  });

  it("handles HTML error bodies without crashing", async () => {
    const { fetchImpl } = captureFetch({
      status: 502,
      textBody: "<html><body>Bad Gateway</body></html>",
    });
    const c = new MyOtpClient({ baseUrl: "https://api.example.com", apiKey: "k", fetchImpl });
    await expect(c.me()).rejects.toMatchObject({ status: 502 });
  });
});

describe("MyOtpClient — timeouts and network", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
  });

  it("aborts and throws MyOtpNetworkError on timeout", async () => {
    const stalledFetch = ((_url: string, init?: RequestInit) => {
      return new Promise<Response>((_resolve, reject) => {
        const signal = init?.signal as AbortSignal | undefined;
        signal?.addEventListener("abort", () => {
          const err = new Error("aborted");
          err.name = "AbortError";
          reject(err);
        });
      });
    }) as unknown as typeof fetch;
    const c = new MyOtpClient({
      baseUrl: "https://api.example.com",
      apiKey: "k",
      fetchImpl: stalledFetch,
      timeoutMs: 50,
    });
    const p = c.me();
    const captured = p.catch((e: unknown) => e);
    await vi.advanceTimersByTimeAsync(60);
    const err = await captured;
    expect(err).toBeInstanceOf(MyOtpNetworkError);
  });

  it("wraps connection errors in MyOtpNetworkError", async () => {
    const fetchImpl = (async () => {
      throw new TypeError("connect ECONNREFUSED");
    }) as unknown as typeof fetch;
    const c = new MyOtpClient({ baseUrl: "https://api.example.com", apiKey: "k", fetchImpl });
    await expect(c.me()).rejects.toBeInstanceOf(MyOtpNetworkError);
  });
});

describe("MyOtpClient — endpoint methods", () => {
  it("generateOtp posts to /generate_otp with the request body", async () => {
    const { calls, fetchImpl } = captureFetch({
      body: {
        message_id: "id",
        status: "queued",
        message: "ok",
        date_sent: "2026-04-28",
        expires_at: "2026-04-28",
        cost: 0.01,
      },
    });
    const c = new MyOtpClient({ baseUrl: "https://api.example.com", apiKey: "k", fetchImpl });
    await c.generateOtp({ phone_number: "14155551234", channel: "sms" });
    expect(calls[0]?.method).toBe("POST");
    expect(calls[0]?.url.endsWith("/generate_otp")).toBe(true);
    expect(JSON.parse(calls[0]?.body ?? "{}")).toMatchObject({
      phone_number: "14155551234",
      channel: "sms",
    });
  });

  it("verifyOtp posts to /verify_otp", async () => {
    const { calls, fetchImpl } = captureFetch({
      body: { status: "success", message: "ok" },
    });
    const c = new MyOtpClient({ baseUrl: "https://api.example.com", apiKey: "k", fetchImpl });
    await c.verifyOtp({ otp: "123456", phone_number: "14155551234" });
    expect(calls[0]?.method).toBe("POST");
    expect(calls[0]?.url.endsWith("/verify_otp")).toBe(true);
  });

  it("me does GET /me", async () => {
    const { calls, fetchImpl } = captureFetch({ body: { email: "a@b.com" } });
    const c = new MyOtpClient({ baseUrl: "https://api.example.com", apiKey: "k", fetchImpl });
    const r = await c.me();
    expect(calls[0]?.method).toBe("GET");
    expect(r.email).toBe("a@b.com");
  });

  it("register does POST /v1/agent/register", async () => {
    const { calls, fetchImpl } = captureFetch({ body: { account_id: "a", status: "pending" } });
    const c = new MyOtpClient({ baseUrl: "https://api.example.com", fetchImpl });
    await c.register({ email: "a@b.com", phone: "14155551234", company_name: "X", source: "cli" });
    expect(calls[0]?.url.endsWith("/v1/agent/register")).toBe(true);
  });
});
