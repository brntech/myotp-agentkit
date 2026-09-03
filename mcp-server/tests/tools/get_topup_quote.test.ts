import { afterEach, describe, expect, it, vi } from "vitest";
import { z } from "zod";
import { MyOtpClient } from "../../src/client.js";
import { getTopUpQuoteTool } from "../../src/tools/get_topup_quote.js";
import { installMockFetch } from "../helpers/mock-fetch.js";

const inputSchema = z.object(getTopUpQuoteTool.inputSchema);

const quote = {
  credits: 100,
  amount_usd: "2.00",
  price_per_credit_usd: 0.02,
  min_credits: 25,
  max_credits: 50_000,
  currency: "usd",
  methods: ["card via Stripe shared payment token", "usdc on tempo"],
};

afterEach(() => {
  vi.unstubAllGlobals();
});

describe("get_topup_quote — input validation", () => {
  it("defaults to 100 credits", () => {
    const result = inputSchema.parse({});
    expect(result.credits).toBe(100);
  });

  it("accepts the inclusive credit limits", () => {
    expect(inputSchema.safeParse({ credits: 25 }).success).toBe(true);
    expect(inputSchema.safeParse({ credits: 50_000 }).success).toBe(true);
  });

  it("rejects fractional and out-of-range credit amounts", () => {
    expect(inputSchema.safeParse({ credits: 25.5 }).success).toBe(false);
    expect(inputSchema.safeParse({ credits: 24 }).success).toBe(false);
    expect(inputSchema.safeParse({ credits: 50_001 }).success).toBe(false);
  });
});

describe("get_topup_quote — handler", () => {
  it("fetches the default quote without sending the caller's API key", async () => {
    const { calls } = installMockFetch({ body: quote });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    const result = await getTopUpQuoteTool.handler(
      {},
      { client, apiKey: "k_secret_must_not_leak" }
    );

    expect(calls()).toHaveLength(1);
    expect(calls()[0]).toMatchObject({
      url: "https://api.example.com/v1/topup/quote?credits=100",
      method: "GET",
    });
    expect(calls()[0]?.headers["X-API-Key"]).toBeUndefined();
    expect(calls()[0]?.body).toBeUndefined();
    expect(result.structuredContent).toMatchObject({
      ...quote,
      rules: expect.stringContaining("$100"),
      how_to_pay: {
        usdc: expect.stringContaining("mppx@0.9.2"),
        card: expect.stringContaining("@stripe/link-cli"),
      },
    });
    expect(result.structuredContent?.rules).toEqual(expect.stringContaining("Starter"));
    expect(JSON.stringify(result)).toContain("your MyOTP API key");
    expect(JSON.stringify(result)).not.toContain("k_secret_must_not_leak");
  });

  it("puts the requested credit amount in the quote request and both client commands", async () => {
    const { calls } = installMockFetch({ body: { ...quote, credits: 250, amount_usd: "5.00" } });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    const result = await getTopUpQuoteTool.handler(
      { credits: 250 },
      { client, apiKey: "unused" }
    );

    expect(calls()[0]?.url).toBe("https://api.example.com/v1/topup/quote?credits=250");
    const commands = result.structuredContent?.how_to_pay as Record<string, string>;
    expect(commands.usdc).toContain('\\"credits\\":250');
    expect(commands.card).toContain('\\"credits\\":250');
  });

  it("maps a quote API error through toToolError", async () => {
    installMockFetch({
      status: 400,
      statusText: "Bad Request",
      body: { error: { http_code: 400, message: "credits must be an integer" } },
    });
    const client = new MyOtpClient({ baseUrl: "https://api.example.com" });

    const result = await getTopUpQuoteTool.handler(
      { credits: 100 },
      { client, apiKey: "unused" }
    );

    expect(result.isError).toBe(true);
    expect(result.structuredContent).toMatchObject({ status: 400 });
    expect(result.content[0]?.text).toContain("credits must be an integer");
  });
});
