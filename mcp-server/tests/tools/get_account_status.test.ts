import { afterEach, describe, expect, it, vi } from "vitest";
import { z } from "zod";
import { MyOtpClient } from "../../src/client.js";
import { getAccountStatusTool } from "../../src/tools/get_account_status.js";
import { allTools } from "../../src/tools/index.js";
import { installMockFetch } from "../helpers/mock-fetch.js";

const inputSchema = z.object(getAccountStatusTool.inputSchema);
const API_KEY = "configured-agent-api-key";

const account = {
  account_id: "a1b2c3d4e5f60",
  email: "dev@example.com",
  email_verified: false,
  balance: 0,
  plan_id: 6452,
  status: "active",
  topup: {
    quote: "https://api.myotp.app/v1/topup/quote?credits=100",
    endpoint: "https://api.myotp.app/v1/topup",
    note: "Balance is zero until you top up.",
  },
  docs: "https://myotp.app/developer-api/",
  verification_email_sent: true,
};

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("get_account_status - input and registration", () => {
  it("accepts an optional boolean resend_verification flag", () => {
    expect(inputSchema.safeParse({}).success).toBe(true);
    expect(inputSchema.safeParse({ resend_verification: false }).success).toBe(true);
    expect(inputSchema.safeParse({ resend_verification: true }).success).toBe(true);
    expect(inputSchema.safeParse({ resend_verification: "true" }).success).toBe(false);
  });

  it("is registered in the MCP tool list", () => {
    expect(allTools.map((tool) => tool.name)).toContain("get_account_status");
  });
});

describe("get_account_status - handler", () => {
  it("gets account status without resending and returns both applicable hints", async () => {
    const { calls } = installMockFetch({ body: account });
    const client = new MyOtpClient({
      baseUrl: "https://api.example.com",
      userAgent: "status-test/1.0",
    });

    const result = await getAccountStatusTool.handler({}, { client, apiKey: API_KEY });

    expect(calls()).toEqual([
      {
        url: "https://api.example.com/v1/agent/account",
        method: "GET",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
          "User-Agent": "status-test/1.0",
          "X-API-Key": API_KEY,
        },
        body: undefined,
      },
    ]);
    expect(result.structuredContent).toEqual({
      email_verified: false,
      balance: 0,
      plan_id: 6452,
      status: "active",
      hint: "cards locked, USDC open; top up",
    });
  });

  it("resends verification before getting the latest account status", async () => {
    const verifiedAccount = { ...account, email_verified: true, balance: 25 };
    const { calls } = installMockFetch([
      {
        body: { email_verified: false, verification_email_sent: true },
      },
      { body: verifiedAccount },
    ]);
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    const result = await getAccountStatusTool.handler(
      { resend_verification: true },
      { client, apiKey: API_KEY }
    );

    expect(calls()).toHaveLength(2);
    expect(calls()[0]).toMatchObject({
      url: "https://api.example.com/v1/agent/resend-verification",
      method: "POST",
      body: JSON.stringify({}),
    });
    expect(calls()[0]?.headers["X-API-Key"]).toBe(API_KEY);
    expect(calls()[1]).toMatchObject({
      url: "https://api.example.com/v1/agent/account",
      method: "GET",
      body: undefined,
    });
    expect(calls()[1]?.headers["X-API-Key"]).toBe(API_KEY);
    expect(result.structuredContent).toEqual({
      email_verified: true,
      balance: 25,
      plan_id: 6452,
      status: "active",
      hint: "ready",
    });
    expect(result.content[0]?.text).toContain("Verification email resend requested");
  });

  it("maps a 401 response through toToolError without exposing the key", async () => {
    const body = {
      error: { http_code: 401, message: "invalid or missing X-API-Key" },
    };
    installMockFetch({ status: 401, statusText: "Unauthorized", body });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    const result = await getAccountStatusTool.handler({}, { client, apiKey: API_KEY });

    expect(result.isError).toBe(true);
    expect(result.structuredContent).toEqual({
      error: "MyOTP API error (401): invalid or missing X-API-Key",
      status: 401,
      endpoint: "/v1/agent/account",
      body,
    });
    expect(result.content[0]?.text).toContain("invalid or missing X-API-Key");
    expect(JSON.stringify(result)).not.toContain(API_KEY);
  });

  it("does not fetch account status when the resend request fails", async () => {
    const body = {
      error: { http_code: 401, message: "invalid or missing X-API-Key" },
    };
    const { calls } = installMockFetch({ status: 401, statusText: "Unauthorized", body });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    const result = await getAccountStatusTool.handler(
      { resend_verification: true },
      { client, apiKey: API_KEY }
    );

    expect(calls()).toHaveLength(1);
    expect(result.isError).toBe(true);
    expect(result.structuredContent?.endpoint).toBe("/v1/agent/resend-verification");
  });
});
