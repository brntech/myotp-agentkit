import { describe, it, expect, vi, beforeEach } from "vitest";
import { myotpSendOtp, MyotpDeliveryError } from "../src/index.js";

const okResponse = (body: unknown = { message_id: "abc", status: "accepted" }): Response =>
  new Response(JSON.stringify(body), { status: 200, headers: { "content-type": "application/json" } });

const errResponse = (status: number, body: unknown): Response =>
  new Response(typeof body === "string" ? body : JSON.stringify(body), {
    status,
    headers: { "content-type": typeof body === "string" ? "text/plain" : "application/json" },
  });

describe("myotpSendOtp", () => {
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchMock = vi.fn();
  });

  it("throws synchronously when apiKey is missing", () => {
    expect(() => myotpSendOtp({ apiKey: "" })).toThrow(/apiKey is required/);
    expect(() => myotpSendOtp({ apiKey: "   " })).toThrow(/apiKey is required/);
  });

  it("posts to /generate_otp with the correct shape", async () => {
    fetchMock.mockResolvedValueOnce(okResponse());
    const send = myotpSendOtp({ apiKey: "test-key", fetch: fetchMock });
    await send({ phoneNumber: "+14155551234", code: "654321" });

    expect(fetchMock).toHaveBeenCalledOnce();
    const [url, init] = fetchMock.mock.calls[0]!;
    expect(url).toBe("https://api.myotp.app/generate_otp");
    expect(init.method).toBe("POST");
    expect(init.headers["X-API-Key"]).toBe("test-key");
    expect(init.headers["Content-Type"]).toBe("application/json");

    const body = JSON.parse(init.body);
    expect(body.phone_number).toBe("14155551234");
    expect(body.channel).toBe("sms");
    expect(body.otp_code).toBe("654321");
    expect(body.otp_validity).toBe(300);
    expect(body.force_send).toBe("true");
  });

  it("respects channel option (whatsapp)", async () => {
    fetchMock.mockResolvedValueOnce(okResponse());
    const send = myotpSendOtp({ apiKey: "k", channel: "whatsapp", fetch: fetchMock });
    await send({ phoneNumber: "14155551234", code: "111111" });

    const body = JSON.parse(fetchMock.mock.calls[0]![1].body);
    expect(body.channel).toBe("whatsapp");
  });

  it("includes brand when provided", async () => {
    fetchMock.mockResolvedValueOnce(okResponse());
    const send = myotpSendOtp({ apiKey: "k", brand: "Acme", fetch: fetchMock });
    await send({ phoneNumber: "14155551234", code: "111111" });

    const body = JSON.parse(fetchMock.mock.calls[0]![1].body);
    expect(body.brand).toBe("Acme");
  });

  it("omits brand when undefined", async () => {
    fetchMock.mockResolvedValueOnce(okResponse());
    const send = myotpSendOtp({ apiKey: "k", fetch: fetchMock });
    await send({ phoneNumber: "14155551234", code: "111111" });

    const body = JSON.parse(fetchMock.mock.calls[0]![1].body);
    expect(body.brand).toBeUndefined();
  });

  it("uses custom validitySeconds", async () => {
    fetchMock.mockResolvedValueOnce(okResponse());
    const send = myotpSendOtp({ apiKey: "k", validitySeconds: 600, fetch: fetchMock });
    await send({ phoneNumber: "14155551234", code: "111111" });

    const body = JSON.parse(fetchMock.mock.calls[0]![1].body);
    expect(body.otp_validity).toBe(600);
  });

  it("uses custom baseUrl and strips trailing slash", async () => {
    fetchMock.mockResolvedValueOnce(okResponse());
    const send = myotpSendOtp({ apiKey: "k", baseUrl: "https://staging.myotp.app/", fetch: fetchMock });
    await send({ phoneNumber: "14155551234", code: "111111" });

    expect(fetchMock.mock.calls[0]![0]).toBe("https://staging.myotp.app/generate_otp");
  });

  it("throws MyotpDeliveryError with status on 403", async () => {
    // Response bodies are streams (consumed on first read) — return a fresh one per call.
    fetchMock.mockImplementation(() => errResponse(403, { message: "Insufficient balance" }));
    const send = myotpSendOtp({ apiKey: "k", fetch: fetchMock });
    await expect(send({ phoneNumber: "14155551234", code: "111111" })).rejects.toThrow(MyotpDeliveryError);
    await expect(send({ phoneNumber: "14155551234", code: "111111" })).rejects.toMatchObject({
      status: 403,
    });
  });

  it("surfaces useful error message text", async () => {
    fetchMock
      .mockResolvedValueOnce(errResponse(403, { message: "Insufficient balance" }))
      .mockResolvedValueOnce(errResponse(403, { message: "Insufficient balance" }));
    const send = myotpSendOtp({ apiKey: "k", fetch: fetchMock });
    await expect(send({ phoneNumber: "14155551234", code: "111111" })).rejects.toThrow(/Insufficient balance/);
  });

  it("handles non-JSON error bodies", async () => {
    fetchMock.mockResolvedValueOnce(errResponse(502, "Bad Gateway"));
    const send = myotpSendOtp({ apiKey: "k", fetch: fetchMock });
    await expect(send({ phoneNumber: "14155551234", code: "111111" })).rejects.toMatchObject({
      status: 502,
    });
  });

  it("throws on invalid phone before calling fetch", async () => {
    const send = myotpSendOtp({ apiKey: "k", fetch: fetchMock });
    await expect(send({ phoneNumber: "abc", code: "111111" })).rejects.toThrow(/invalid phone/);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("never echoes the API key in error messages", async () => {
    const apiKey = "super-secret-key-do-not-leak";
    fetchMock.mockResolvedValueOnce(errResponse(401, { message: "Unauthorized" }));
    const send = myotpSendOtp({ apiKey, fetch: fetchMock });
    try {
      await send({ phoneNumber: "14155551234", code: "111111" });
    } catch (err) {
      expect(String(err)).not.toContain(apiKey);
      if (err instanceof MyotpDeliveryError) {
        expect(JSON.stringify(err.body)).not.toContain(apiKey);
      }
    }
  });

  it("times out per timeoutMs", async () => {
    const ctrl = new AbortController();
    fetchMock.mockImplementationOnce((_url, init) => {
      // simulate a hanging request that respects the abort signal
      return new Promise((_, reject) => {
        init.signal?.addEventListener("abort", () => {
          const err = new Error("aborted");
          err.name = "AbortError";
          reject(err);
        });
      });
    });
    const send = myotpSendOtp({ apiKey: "k", fetch: fetchMock, timeoutMs: 25 });
    await expect(send({ phoneNumber: "14155551234", code: "111111" })).rejects.toThrow(/timed out/);
    ctrl.abort();
  });
});
