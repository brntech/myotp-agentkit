import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MyOtpClient } from "../src/client.js";
import { MyOtpApiError } from "../src/types.js";
import { installMockFetch, installStalledFetch } from "./helpers/mock-fetch.js";

const VALID_KEY = "k_test_abcdef123";

describe("MyOtpClient — URL construction", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("joins base URL and path with a single slash when path has a leading /", async () => {
    const { calls } = installMockFetch({ body: { ok: true } });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    await client.post("/generate_otp", {}, VALID_KEY);
    expect(calls()[0]?.url).toBe("https://api.example.com/generate_otp");
  });

  it("inserts a slash when the path is missing one", async () => {
    const { calls } = installMockFetch({ body: { ok: true } });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    await client.post("generate_otp", {}, VALID_KEY);
    expect(calls()[0]?.url).toBe("https://api.example.com/generate_otp");
  });

  it("strips trailing slashes from the base URL", async () => {
    const { calls } = installMockFetch({ body: { ok: true } });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com////" });
    await client.post("/generate_otp", {}, VALID_KEY);
    expect(calls()[0]?.url).toBe("https://api.example.com/generate_otp");
  });

  it("falls back to MYOTP_BASE_URL env var when no baseUrl option is given", async () => {
    const prev = process.env.MYOTP_BASE_URL;
    process.env.MYOTP_BASE_URL = "https://staging.example.com";
    try {
      const { calls } = installMockFetch({ body: {} });
      const client = new MyOtpClient();
      await client.get("/me", VALID_KEY);
      expect(calls()[0]?.url).toBe("https://staging.example.com/me");
    } finally {
      if (prev === undefined) delete process.env.MYOTP_BASE_URL;
      else process.env.MYOTP_BASE_URL = prev;
    }
  });
});

describe("MyOtpClient — auth header and body", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("sends X-API-Key on every request", async () => {
    const { calls } = installMockFetch({ body: {} });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    await client.post("/x", { a: 1 }, VALID_KEY);
    await client.get("/y", VALID_KEY);
    expect(calls()[0]?.headers["X-API-Key"]).toBe(VALID_KEY);
    expect(calls()[1]?.headers["X-API-Key"]).toBe(VALID_KEY);
  });

  it("sets the User-Agent header", async () => {
    const { calls } = installMockFetch({ body: {} });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com", userAgent: "custom-ua/1.2.3" });
    await client.get("/y", VALID_KEY);
    expect(calls()[0]?.headers["User-Agent"]).toBe("custom-ua/1.2.3");
  });

  it("serializes the body to JSON for POST requests", async () => {
    const { calls } = installMockFetch({ body: { ok: true } });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    await client.post("/generate_otp", { phone_number: "14155551234" }, VALID_KEY);
    expect(calls()[0]?.body).toBe(JSON.stringify({ phone_number: "14155551234" }));
  });

  it("does not send a body for GET requests", async () => {
    const { calls } = installMockFetch({ body: { email: "a@b.com" } });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    await client.get("/me", VALID_KEY);
    expect(calls()[0]?.body).toBeUndefined();
  });
});

describe("MyOtpClient — API key validation", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("throws MyOtpApiError when the API key is empty", async () => {
    const { fetchMock } = installMockFetch({ body: {} });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    await expect(client.post("/x", {}, "")).rejects.toBeInstanceOf(MyOtpApiError);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("throws MyOtpApiError when the API key is whitespace only", async () => {
    installMockFetch({ body: {} });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    await expect(client.post("/x", {}, "   ")).rejects.toMatchObject({
      name: "MyOtpApiError",
      status: 401,
    });
  });

  it("does not echo the API key back in the missing-key error", async () => {
    installMockFetch({ body: {} });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    let captured: unknown;
    try {
      await client.post("/x", {}, "");
    } catch (e) {
      captured = e;
    }
    expect(captured).toBeInstanceOf(MyOtpApiError);
    expect((captured as Error).message).not.toContain(VALID_KEY);
  });
});

describe("MyOtpClient — error responses", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("throws MyOtpApiError with status when response is 4xx", async () => {
    installMockFetch({ status: 400, body: { message: "Bad phone number" } });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    try {
      await client.post("/generate_otp", {}, VALID_KEY);
      throw new Error("should have thrown");
    } catch (err) {
      expect(err).toBeInstanceOf(MyOtpApiError);
      expect((err as MyOtpApiError).status).toBe(400);
      expect((err as MyOtpApiError).message).toContain("Bad phone number");
    }
  });

  it("throws MyOtpApiError when response is 5xx", async () => {
    installMockFetch({ status: 503, statusText: "Service Unavailable" });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    await expect(client.post("/generate_otp", {}, VALID_KEY)).rejects.toMatchObject({
      name: "MyOtpApiError",
      status: 503,
    });
  });

  it("handles HTML error bodies without exposing them as the message", async () => {
    installMockFetch({
      status: 502,
      statusText: "Bad Gateway",
      textBody: "<html><body>nginx error</body></html>",
      headers: { "content-type": "text/html" },
    });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    try {
      await client.post("/x", {}, VALID_KEY);
    } catch (err) {
      expect((err as MyOtpApiError).status).toBe(502);
      expect((err as MyOtpApiError).message).not.toContain("<html");
      expect((err as MyOtpApiError).message).toContain("502");
    }
  });

  it("handles empty error bodies", async () => {
    installMockFetch({ status: 500, statusText: "Internal Server Error", empty: true });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    try {
      await client.post("/x", {}, VALID_KEY);
    } catch (err) {
      expect((err as MyOtpApiError).status).toBe(500);
      expect((err as MyOtpApiError).message).toContain("500");
    }
  });

  it("uses 'error' or 'description' or 'detail' fields when 'message' is absent", async () => {
    installMockFetch({ status: 422, body: { detail: "phone_number is required" } });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    try {
      await client.post("/x", {}, VALID_KEY);
    } catch (err) {
      expect((err as MyOtpApiError).message).toContain("phone_number is required");
    }
  });

  it("returns parsed body on success", async () => {
    installMockFetch({ status: 200, body: { message_id: "abc-123", cost: 0.01 } });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    const result = await client.post<{ message_id: string; cost: number }>(
      "/generate_otp",
      {},
      VALID_KEY
    );
    expect(result.message_id).toBe("abc-123");
    expect(result.cost).toBe(0.01);
  });
});

describe("MyOtpClient — timeouts and network errors", () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("aborts the request after timeoutMs and throws a 408 MyOtpApiError", async () => {
    installStalledFetch();
    const client = new MyOtpClient({ baseUrl: "https://api.example.com", timeoutMs: 50 });
    const p = client.post("/x", {}, VALID_KEY);
    // Attach a catch to avoid an unhandled rejection while we advance timers.
    const captured = p.catch((e: unknown) => e);
    await vi.advanceTimersByTimeAsync(60);
    const err = await captured;
    expect(err).toBeInstanceOf(MyOtpApiError);
    expect((err as MyOtpApiError).status).toBe(408);
    expect((err as MyOtpApiError).message.toLowerCase()).toContain("timed out");
  });

  it("wraps generic network errors in MyOtpApiError with status 0", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("connect ECONNREFUSED");
      })
    );
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    await expect(client.post("/x", {}, VALID_KEY)).rejects.toMatchObject({
      name: "MyOtpApiError",
      status: 0,
    });
  });
});
