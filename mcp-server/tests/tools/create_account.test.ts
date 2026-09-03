import { afterEach, describe, expect, it, vi } from "vitest";
import { z } from "zod";
import { MyOtpClient } from "../../src/client.js";
import { createAccountTool } from "../../src/tools/create_account.js";
import { installMockFetch } from "../helpers/mock-fetch.js";

const inputSchema = z.object(createAccountTool.inputSchema);
const RETURNED_API_KEY = "0123456789abcdef0123456789abcdef";
const CONFIGURED_API_KEY = "configured-key-must-not-be-sent";

const registration = {
  account_id: "a1b2c3d4e5f60",
  api_key: RETURNED_API_KEY,
  api_key_note: "Shown once. Send it as the X-API-Key header.",
  email: "dev@example.com",
  email_verified: false,
  balance: 0,
  plan_id: 6452,
  status: "active",
  topup: {
    quote: "https://api.myotp.app/v1/topup/quote?credits=100",
    endpoint: "https://api.myotp.app/v1/topup",
    note: "Balance is zero until you top up. USDC works now; card top-ups unlock once the email is confirmed.",
  },
  docs: "https://myotp.app/developer-api/",
  verification_email_sent: true,
};

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("create_account - input validation", () => {
  it("requires a valid email and makes name optional", () => {
    expect(inputSchema.safeParse({}).success).toBe(false);
    expect(inputSchema.safeParse({ email: "not-an-email" }).success).toBe(false);
    expect(inputSchema.safeParse({ email: "dev@example.com" }).success).toBe(true);
    expect(inputSchema.safeParse({ email: "dev@example.com", name: "Acme" }).success).toBe(true);
  });

  it("accepts names through 64 characters and rejects longer names", () => {
    expect(inputSchema.safeParse({ email: "dev@example.com", name: "a".repeat(64) }).success).toBe(true);
    expect(inputSchema.safeParse({ email: "dev@example.com", name: "" }).success).toBe(true);
    expect(inputSchema.safeParse({ email: "dev@example.com", name: "a".repeat(65) }).success).toBe(false);
  });
});

describe("create_account - handler", () => {
  it("registers without authentication and returns the full 201 response", async () => {
    const { calls } = installMockFetch({ status: 201, statusText: "Created", body: registration });
    const client = new MyOtpClient({
      baseUrl: "https://api.example.com",
      userAgent: "signup-test/1.0",
    });

    const result = await createAccountTool.handler(
      { email: "dev@example.com", name: "Acme" },
      { client, apiKey: CONFIGURED_API_KEY }
    );

    expect(calls()).toEqual([
      {
        url: "https://api.example.com/v1/agent/register",
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "User-Agent": "signup-test/1.0",
        },
        body: JSON.stringify({ email: "dev@example.com", name: "Acme" }),
      },
    ]);
    expect(calls()[0]?.headers["X-API-Key"]).toBeUndefined();
    expect(result.isError).toBeFalsy();
    expect(result.structuredContent).toEqual(registration);

    const text = result.content[0]?.text ?? "";
    expect(text).toContain(registration.account_id);
    expect(text).toContain("balance is 0");
    expect(text).toContain("MYOTP_API_KEY");
    expect(text).toContain("shown once");
    expect(text).toContain("dev@example.com");
    expect(text).toContain("human must click");
    expect(text).toContain("unlock card top-ups");
    expect(text).toContain("USDC top-ups work now");
    expect(text).toContain("get_topup_quote");
    expect(text).toContain("top_up_credits");
    expect(text.split(RETURNED_API_KEY).length - 1).toBe(1);
    expect(JSON.stringify(calls())).not.toContain(CONFIGURED_API_KEY);
  });

  it("omits name from the request body when it is not supplied", async () => {
    const { calls } = installMockFetch({ status: 201, body: registration });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    await createAccountTool.handler(
      { email: "dev@example.com" },
      { client, apiKey: "" }
    );

    expect(calls()[0]?.body).toBe(JSON.stringify({ email: "dev@example.com" }));
    expect(calls()[0]?.headers["X-API-Key"]).toBeUndefined();
  });

  it.each([
    [400, "Bad Request", "invalid email"],
    [409, "Conflict", "an account with this email already exists"],
    [429, "Too Many Requests", "too many registrations from this address"],
    [503, "Service Unavailable", "account creation temporarily unavailable"],
  ])("maps HTTP %i with the API message", async (status, statusText, message) => {
    const body = { detail: { http_code: status, message } };
    installMockFetch({ status, statusText, body });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    const result = await createAccountTool.handler(
      { email: "dev@example.com" },
      { client, apiKey: CONFIGURED_API_KEY }
    );

    expect(result.isError).toBe(true);
    expect(result.structuredContent).toEqual({
      error: `MyOTP API error (${status}): ${message}`,
      status,
      endpoint: "/v1/agent/register",
      body,
    });
    expect(result.content[0]?.text).toContain(message);
    expect(JSON.stringify(result)).not.toContain(CONFIGURED_API_KEY);
    if (status === 409) {
      expect(result.content[0]?.text).toContain(
        "the human should use the key from the dashboard instead"
      );
    }
  });
});
