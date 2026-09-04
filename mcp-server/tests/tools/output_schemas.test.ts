/**
 * Every tool declares an outputSchema. These tests run each handler against
 * the same mocked API responses the other tool tests use and assert that the
 * real `structuredContent` parses under the tool's declared schema, exactly
 * as the SDK will validate it on every call. The error shape built by
 * `toToolError` is checked against `toolErrorSchema`; the SDK skips output
 * validation on `isError` results, and the server test proves that end to end.
 */

import { afterEach, describe, expect, it, vi } from "vitest";
import { z } from "zod";
import { MyOtpClient } from "../../src/client.js";
import { toolErrorSchema, toToolError } from "../../src/tools/helpers.js";
import { allTools } from "../../src/tools/index.js";
import type { ToolDefinition, ToolResult } from "../../src/tools/types.js";
import { MyOtpApiError } from "../../src/types.js";
import { asMyOtpClient, makeFakeClient } from "../helpers/fake-client.js";
import { installMockFetch } from "../helpers/mock-fetch.js";

const API_KEY = "k_secret";
const VALID_UUID = "11111111-1111-1111-1111-111111111111";

function schemaOf(tool: ToolDefinition<z.ZodRawShape>) {
  if (!tool.outputSchema) throw new Error(`${tool.name} has no outputSchema`);
  return z.object(tool.outputSchema).passthrough();
}

function tool(name: string): ToolDefinition<z.ZodRawShape> {
  const found = allTools.find((t) => t.name === name);
  if (!found) throw new Error(`unknown tool ${name}`);
  return found;
}

function expectSuccess(t: ToolDefinition<z.ZodRawShape>, result: ToolResult) {
  expect(result.isError).toBeUndefined();
  expect(result.structuredContent).toBeDefined();
  const parsed = schemaOf(t).safeParse(result.structuredContent);
  if (!parsed.success) throw new Error(`${t.name}: ${parsed.error.message}`);
  // passthrough keeps every field the API returned
  expect(parsed.data).toEqual(result.structuredContent);
}

function expectErrorShape(t: ToolDefinition<z.ZodRawShape>, result: ToolResult) {
  expect(result.isError).toBe(true);
  expect(t.outputSchema).toBeDefined();
  const parsed = toolErrorSchema.safeParse(result.structuredContent);
  if (!parsed.success) throw new Error(`${t.name}: ${parsed.error.message}`);
}

const apiError = () => new MyOtpApiError("Invalid API key", 401, "/x", { error: { http_code: 401, message: "Invalid API key" } });

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("toolErrorSchema", () => {
  it("accepts both shapes toToolError builds", () => {
    expect(toolErrorSchema.safeParse(toToolError(apiError(), "p").structuredContent).success).toBe(true);
    expect(toolErrorSchema.safeParse(toToolError(new Error("boom"), "p").structuredContent).success).toBe(true);
    // body is null when the API answered with an empty or non-JSON body
    const empty = new MyOtpApiError("HTTP 502", 502, "/generate_otp", undefined);
    expect(toolErrorSchema.safeParse(toToolError(empty, "p").structuredContent).success).toBe(true);
  });
});

describe("generate_otp outputSchema", () => {
  const t = tool("generate_otp");
  it("parses the real success output", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce({
      message_id: VALID_UUID,
      status: "accepted",
      message: "OTP queued for delivery",
      date_sent: "2026-04-28T10:00:00Z",
      expires_at: "2026-04-28T10:05:00Z",
      cost: 1,
      otp: "123456",
    });
    expectSuccess(t, await t.handler({ phone_number: "14155551234", return_otp: true }, { client: asMyOtpClient(client), apiKey: API_KEY }));
  });
  it("returns the helper error shape on an API error", async () => {
    const client = makeFakeClient();
    client.post.mockRejectedValueOnce(apiError());
    expectErrorShape(t, await t.handler({ phone_number: "14155551234" }, { client: asMyOtpClient(client), apiKey: API_KEY }));
  });
});

describe("verify_otp outputSchema", () => {
  const t = tool("verify_otp");
  it("parses success and failed outputs", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce({ status: "success", message: "OTP verified" });
    expectSuccess(t, await t.handler({ otp: "123456", message_id: VALID_UUID }, { client: asMyOtpClient(client), apiKey: API_KEY }));
    client.post.mockResolvedValueOnce({ status: "failed", reason: "expired", message: "OTP expired" });
    expectSuccess(t, await t.handler({ otp: "123456", message_id: VALID_UUID }, { client: asMyOtpClient(client), apiKey: API_KEY }));
  });
  it("returns the helper error shape on an API error", async () => {
    const client = makeFakeClient();
    client.post.mockRejectedValueOnce(apiError());
    expectErrorShape(t, await t.handler({ otp: "123456", message_id: VALID_UUID }, { client: asMyOtpClient(client), apiKey: API_KEY }));
  });
});

describe("check_otp_status outputSchema", () => {
  const t = tool("check_otp_status");
  it("parses the DLR output and the unknown-id output", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce({ DLR: "DELIVRD", is_active: true, expires_at: "2026-04-28T10:05:00Z" });
    expectSuccess(t, await t.handler({ message_id: VALID_UUID }, { client: asMyOtpClient(client), apiKey: API_KEY }));
    client.post.mockResolvedValueOnce({ message: "Message not found", "Message:": "Message not found", is_active: false });
    expectSuccess(t, await t.handler({ message_id: VALID_UUID }, { client: asMyOtpClient(client), apiKey: API_KEY }));
  });
  it("returns the helper error shape on an API error", async () => {
    const client = makeFakeClient();
    client.post.mockRejectedValueOnce(apiError());
    expectErrorShape(t, await t.handler({ message_id: VALID_UUID }, { client: asMyOtpClient(client), apiKey: API_KEY }));
  });
});

describe("extend_otp outputSchema", () => {
  const t = tool("extend_otp");
  it("parses the real success output", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce({ status: "success", message: "OTP extended", expires_at: "2026-04-28T10:15:00Z" });
    expectSuccess(t, await t.handler({ message_id: VALID_UUID, duration: 600 }, { client: asMyOtpClient(client), apiKey: API_KEY }));
  });
  it("returns the helper error shape on an API error", async () => {
    const client = makeFakeClient();
    client.post.mockRejectedValueOnce(apiError());
    expectErrorShape(t, await t.handler({ message_id: VALID_UUID, duration: 600 }, { client: asMyOtpClient(client), apiKey: API_KEY }));
  });
});

describe("get_account_info outputSchema", () => {
  const t = tool("get_account_info");
  it("parses the real success output", async () => {
    const client = makeFakeClient();
    client.get.mockResolvedValueOnce({ email: "dev@example.com" });
    expectSuccess(t, await t.handler({}, { client: asMyOtpClient(client), apiKey: API_KEY }));
  });
  it("returns the helper error shape on an API error", async () => {
    const client = makeFakeClient();
    client.get.mockRejectedValueOnce(apiError());
    expectErrorShape(t, await t.handler({}, { client: asMyOtpClient(client), apiKey: API_KEY }));
  });
});

describe("get_usage_report outputSchema", () => {
  const t = tool("get_usage_report");
  const args = { start_date: "2026-04-01", end_date: "2026-04-28" };
  it("parses a page of transactions and the no-data shape", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce({
      total_count: 1,
      total_pages: 1,
      current_page: 1,
      per_page: 100,
      transactions: [
        {
          message_id: VALID_UUID,
          message_timestamp: "2026-04-28T10:00:00Z",
          message_type: 1,
          phone_number: "14155551234",
          channel: "sms",
          country: "USA",
          force_send: false,
          application: null,
          cost: 1,
          status: "delivered",
          description: null,
          client_ip: "203.0.113.5",
        },
      ],
    });
    expectSuccess(t, await t.handler(args, { client: asMyOtpClient(client), apiKey: API_KEY }));
    client.post.mockResolvedValueOnce({ message: "No transactions found", transactions: [] });
    expectSuccess(t, await t.handler(args, { client: asMyOtpClient(client), apiKey: API_KEY }));
  });
  it("returns the helper error shape on an API error", async () => {
    const client = makeFakeClient();
    client.post.mockRejectedValueOnce(apiError());
    expectErrorShape(t, await t.handler(args, { client: asMyOtpClient(client), apiKey: API_KEY }));
  });
});

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

describe("create_account outputSchema", () => {
  const t = tool("create_account");
  it("parses the real 201 output", async () => {
    installMockFetch({
      status: 201,
      body: { ...account, api_key: "0123456789abcdef0123456789abcdef", api_key_note: "Shown once." },
    });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    expectSuccess(t, await t.handler({ email: "dev@example.com" }, { client, apiKey: "" }));
  });
  it("returns the helper error shape on a 409", async () => {
    installMockFetch({ status: 409, body: { detail: { http_code: 409, message: "exists" } } });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    expectErrorShape(t, await t.handler({ email: "dev@example.com" }, { client, apiKey: "" }));
  });
});

describe("get_account_status outputSchema", () => {
  const t = tool("get_account_status");
  it("parses the real success output", async () => {
    installMockFetch({ body: account });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    expectSuccess(t, await t.handler({}, { client, apiKey: API_KEY }));
  });
  it("returns the helper error shape on an API error", async () => {
    installMockFetch({ status: 401, body: { detail: { http_code: 401, message: "Invalid API key" } } });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    expectErrorShape(t, await t.handler({}, { client, apiKey: API_KEY }));
  });
});

const quote = {
  credits: 100,
  amount_usd: "2.00",
  price_per_credit_usd: 0.02,
  min_credits: 25,
  max_credits: 50_000,
  currency: "usd",
  methods: ["card via Stripe shared payment token", "usdc on tempo"],
};

describe("get_topup_quote outputSchema", () => {
  const t = tool("get_topup_quote");
  it("parses the real success output", async () => {
    installMockFetch({ body: quote });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    expectSuccess(t, await t.handler({ credits: 100 }, { client, apiKey: API_KEY }));
  });
  it("returns the helper error shape on an API error", async () => {
    installMockFetch({ status: 400, body: { error: { http_code: 400, message: "credits out of range" } } });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    expectErrorShape(t, await t.handler({ credits: 100 }, { client, apiKey: API_KEY }));
  });
});

describe("top_up_credits outputSchema", () => {
  const t = tool("top_up_credits");
  const tempoRequest = Buffer.from(
    JSON.stringify({ amount: "2000000", currency: "0x20c0", recipient: "0xabc", methodDetails: { chainId: 42431 } })
  ).toString("base64url");
  const wwwAuthenticate =
    `Payment id="tempo_offer", realm="api.myotp.app", method="tempo", intent="charge", ` +
    `request="${tempoRequest}", description="MyOTP.App 100 credits", ` +
    `expires="2026-09-03T18:00:00Z", opaque="dGVtcG8"`;

  it("parses the dry-run output", async () => {
    installMockFetch({ body: quote });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    expectSuccess(t, await t.handler({ credits: 100, dry_run: true }, { client, apiKey: API_KEY }));
  });
  it("parses the 402 challenge output", async () => {
    installMockFetch([
      { body: quote },
      {
        status: 402,
        body: { type: "about:blank", title: "Payment Required", status: 402, challengeId: "ch_1" },
        headers: { "WWW-Authenticate": wwwAuthenticate },
      },
    ]);
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    expectSuccess(t, await t.handler({ credits: 100 }, { client, apiKey: API_KEY }));
  });
  it("parses the credited output", async () => {
    installMockFetch([
      { body: quote },
      {
        body: {
          status: "credited",
          credits: 100,
          amount_usd: "2.00",
          currency: "usd",
          payment: { method: "tempo", reference: "0x01" },
          balance: 115,
          plan_id: 6452,
        },
      },
    ]);
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    expectSuccess(t, await t.handler({ credits: 100 }, { client, apiKey: API_KEY }));
  });
  it("returns the helper error shape on an API error", async () => {
    installMockFetch({ status: 400, body: { error: { http_code: 400, message: "credits out of range" } } });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });
    expectErrorShape(t, await t.handler({ credits: 100 }, { client, apiKey: API_KEY }));
  });
});
