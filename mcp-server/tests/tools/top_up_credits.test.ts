import { afterEach, describe, expect, it, vi } from "vitest";
import { z } from "zod";
import { MyOtpClient } from "../../src/client.js";
import { topUpCreditsTool } from "../../src/tools/top_up_credits.js";
import { installMockFetch } from "../helpers/mock-fetch.js";

const inputSchema = z.object(topUpCreditsTool.inputSchema);
const API_KEY = "k_secret_must_not_leak";

const quote = {
  credits: 100,
  amount_usd: "2.00",
  price_per_credit_usd: 0.02,
  min_credits: 25,
  max_credits: 50_000,
  currency: "usd",
  methods: ["card via Stripe shared payment token", "usdc on tempo"],
};

const tempoRequest = Buffer.from(
  JSON.stringify({
    amount: "2000000",
    currency: "0x20c0000000000000000000000000000000000000",
    recipient: "0xabc",
    methodDetails: { chainId: 42431 },
  })
).toString("base64url");

const stripeRequest = Buffer.from(
  JSON.stringify({
    amount: "200",
    currency: "usd",
    methodDetails: {
      networkId: "profile_test",
      paymentMethodTypes: ["card", "link"],
    },
  })
).toString("base64url");

const wwwAuthenticate =
  `Payment id="tempo_offer", realm="api.myotp.app", method="tempo", intent="charge", ` +
  `request="${tempoRequest}", description="MyOTP.App 100 credits, paid in USDC", ` +
  `expires="2026-09-03T18:00:00Z", opaque="dGVtcG8", ` +
  `Payment id="stripe_offer", realm="api.myotp.app", method="stripe", intent="charge", ` +
  `request="${stripeRequest}", description="MyOTP.App 100 credits", ` +
  `expires="2026-09-03T18:00:00Z", opaque="c3RyaXBl"`;

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("top_up_credits — input validation", () => {
  it("requires an integer credit amount within the contract limits", () => {
    expect(inputSchema.safeParse({}).success).toBe(false);
    expect(inputSchema.safeParse({ credits: 25 }).success).toBe(true);
    expect(inputSchema.safeParse({ credits: 50_000 }).success).toBe(true);
    expect(inputSchema.safeParse({ credits: 24 }).success).toBe(false);
    expect(inputSchema.safeParse({ credits: 50_001 }).success).toBe(false);
    expect(inputSchema.safeParse({ credits: 100.5 }).success).toBe(false);
  });

  it("accepts an optional boolean dry_run flag", () => {
    expect(inputSchema.safeParse({ credits: 100, dry_run: true }).success).toBe(true);
    expect(inputSchema.safeParse({ credits: 100, dry_run: "true" }).success).toBe(false);
  });
});

describe("top_up_credits — handler", () => {
  it("returns only the quote and explanation on a dry run without posting", async () => {
    const { calls } = installMockFetch({ body: quote });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    const result = await topUpCreditsTool.handler(
      { credits: 100, dry_run: true },
      { client, apiKey: API_KEY }
    );

    expect(calls()).toHaveLength(1);
    expect(calls()[0]?.url).toBe("https://api.example.com/v1/topup/quote?credits=100");
    expect(result.structuredContent).toEqual({
      quote,
      explanation:
        "Dry run only: the quote was fetched, but no top-up request or payment challenge was created. " +
        "This MCP server cannot hold your wallet; call again without dry_run and use your own MPP client " +
        "to pay the challenge and retry with its credential, which credits the account.",
    });
  });

  it("parses two realistic Payment offers from a 402 challenge", async () => {
    const { calls } = installMockFetch([
      { body: quote },
      {
        status: 402,
        statusText: "Payment Required",
        body: {
          type: "https://paymentauth.org/problems/payment-required",
          title: "Payment Required",
          status: 402,
          challengeId: "challenge_123",
        },
        headers: { "WWW-Authenticate": wwwAuthenticate },
      },
    ]);
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    const result = await topUpCreditsTool.handler(
      { credits: 100 },
      { client, apiKey: API_KEY }
    );

    expect(result.isError).toBeFalsy();
    expect(calls()).toHaveLength(2);
    expect(calls()[0]?.method).toBe("GET");
    expect(calls()[0]?.headers["X-API-Key"]).toBeUndefined();
    expect(calls()[1]).toMatchObject({
      url: "https://api.example.com/v1/topup",
      method: "POST",
      body: JSON.stringify({ credits: 100 }),
    });
    expect(calls()[1]?.headers["X-API-Key"]).toBe(API_KEY);
    expect(calls()[1]?.headers.Authorization).toBeUndefined();
    expect(result.structuredContent?.challengeId).toBe("challenge_123");
    expect(result.structuredContent?.offers).toEqual([
      {
        method: "tempo",
        intent: "charge",
        id: "tempo_offer",
        expires: "2026-09-03T18:00:00Z",
        amount: "2000000",
        currency: "0x20c0000000000000000000000000000000000000",
      },
      {
        method: "stripe",
        intent: "charge",
        id: "stripe_offer",
        expires: "2026-09-03T18:00:00Z",
        amount: "200",
        currency: "usd",
      },
    ]);
    expect(result.structuredContent?.retry).toEqual({
      url: "https://api.example.com/v1/topup",
      method: "POST",
      headers: {
        "X-API-Key": "your MyOTP API key",
        "Content-Type": "application/json",
        "Authorization": "Payment <credential from your MPP client>",
      },
      body: { credits: 100 },
    });
    const serialized = JSON.stringify(result);
    expect(serialized).toContain("mppx@0.9.2");
    expect(serialized).toContain("@stripe/link-cli");
    expect(serialized).not.toContain(API_KEY);
  });

  it("returns a 200 credited body as-is after fetching the quote", async () => {
    const credited = {
      status: "credited",
      credits: 100,
      amount_usd: "2.00",
      currency: "usd",
      payment: { method: "tempo", reference: "0xtransaction" },
      balance: 115,
      plan_id: 6452,
    };
    const { calls } = installMockFetch([
      { body: quote },
      { status: 200, body: credited },
    ]);
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    const result = await topUpCreditsTool.handler(
      { credits: 100 },
      { client, apiKey: API_KEY }
    );

    expect(calls()).toHaveLength(2);
    expect(result.isError).toBeFalsy();
    expect(result.structuredContent).toEqual(credited);
  });

  it("maps a 401 response through toToolError after fetching the quote", async () => {
    const { calls } = installMockFetch([
      { body: quote },
      {
        status: 401,
        statusText: "Unauthorized",
        body: { error: { http_code: 401, message: "invalid or missing X-API-Key" } },
      },
    ]);
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    const result = await topUpCreditsTool.handler(
      { credits: 100 },
      { client, apiKey: API_KEY }
    );

    expect(calls()).toHaveLength(2);
    expect(result.isError).toBe(true);
    expect(result.structuredContent).toMatchObject({
      status: 401,
      endpoint: "/v1/topup",
      body: { error: { http_code: 401, message: "invalid or missing X-API-Key" } },
    });
    expect(result.content[0]?.text).toContain("invalid or missing X-API-Key");
    expect(JSON.stringify(result)).not.toContain(API_KEY);
  });

  it.each([
    [400, "Bad Request", "credits must be an integer"],
    [403, "Forbidden", "account not active"],
  ])("maps a %i response through toToolError", async (status, statusText, message) => {
    installMockFetch([
      { body: quote },
      {
        status,
        statusText,
        body: { error: { http_code: status, message } },
      },
    ]);
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    const result = await topUpCreditsTool.handler(
      { credits: 100 },
      { client, apiKey: API_KEY }
    );

    expect(result.isError).toBe(true);
    expect(result.structuredContent).toMatchObject({ status, endpoint: "/v1/topup" });
    expect(result.content[0]?.text).toContain(message);
  });

  it("turns a malformed 402 challenge into a clean tool error", async () => {
    installMockFetch([
      { body: quote },
      {
        status: 402,
        statusText: "Payment Required",
        body: { challengeId: "challenge_bad" },
      },
    ]);
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    const result = await topUpCreditsTool.handler(
      { credits: 100 },
      { client, apiKey: API_KEY }
    );

    expect(result.isError).toBe(true);
    expect(result.content[0]?.text).toContain("WWW-Authenticate");
  });
});
