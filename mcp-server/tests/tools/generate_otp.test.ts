import { describe, expect, it } from "vitest";
import { z } from "zod";
import { generateOtpTool } from "../../src/tools/generate_otp.js";
import { MyOtpApiError } from "../../src/types.js";
import { asMyOtpClient, makeFakeClient } from "../helpers/fake-client.js";

const inputSchema = z.object(generateOtpTool.inputSchema);

const validResponse = {
  message_id: "11111111-1111-1111-1111-111111111111",
  status: "queued",
  message: "OTP queued for delivery",
  date_sent: "2026-04-28T10:00:00Z",
  expires_at: "2026-04-28T10:05:00Z",
  cost: 0.01,
};

describe("generate_otp — input validation", () => {
  it("rejects a phone number with a leading + sign", () => {
    const result = inputSchema.safeParse({ phone_number: "+14155551234" });
    expect(result.success).toBe(false);
  });

  it("rejects a phone number that starts with 0", () => {
    const result = inputSchema.safeParse({ phone_number: "07911123456" });
    expect(result.success).toBe(false);
  });

  it("rejects a phone number that is too short", () => {
    const result = inputSchema.safeParse({ phone_number: "12345" });
    expect(result.success).toBe(false);
  });

  it("rejects a phone number that is too long", () => {
    const result = inputSchema.safeParse({ phone_number: "1234567890123456" });
    expect(result.success).toBe(false);
  });

  it("rejects a phone number with non-digit characters", () => {
    const result = inputSchema.safeParse({ phone_number: "1-415-555-1234" });
    expect(result.success).toBe(false);
  });

  it("rejects when phone_number is missing", () => {
    const result = inputSchema.safeParse({});
    expect(result.success).toBe(false);
  });

  it("accepts a valid international phone number with no plus", () => {
    const result = inputSchema.safeParse({ phone_number: "14155551234" });
    expect(result.success).toBe(true);
  });

  it("rejects unsupported channel values", () => {
    const result = inputSchema.safeParse({ phone_number: "14155551234", channel: "voice" });
    expect(result.success).toBe(false);
  });

  it("rejects otp_length outside the 3-8 range", () => {
    expect(inputSchema.safeParse({ phone_number: "14155551234", otp_length: 2 }).success).toBe(false);
    expect(inputSchema.safeParse({ phone_number: "14155551234", otp_length: 9 }).success).toBe(false);
  });
});

describe("generate_otp — request shape", () => {
  it("calls POST /generate_otp with the API key from the context", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce(validResponse);

    await generateOtpTool.handler(
      { phone_number: "14155551234" },
      { client: asMyOtpClient(client), apiKey: "k_secret" }
    );

    expect(client.post).toHaveBeenCalledTimes(1);
    expect(client.post.mock.calls[0]?.[0]).toBe("/generate_otp");
    expect(client.post.mock.calls[0]?.[2]).toBe("k_secret");
  });

  it("coerces force_send and return_otp booleans to strings before sending", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce(validResponse);

    await generateOtpTool.handler(
      { phone_number: "14155551234", force_send: true, return_otp: false },
      { client: asMyOtpClient(client), apiKey: "k" }
    );

    const body = client.post.mock.calls[0]?.[1] as Record<string, unknown>;
    expect(body.force_send).toBe("true");
    expect(body.return_otp).toBe("false");
  });

  it("omits boolean keys entirely when they are not provided", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce(validResponse);

    await generateOtpTool.handler(
      { phone_number: "14155551234" },
      { client: asMyOtpClient(client), apiKey: "k" }
    );

    const body = client.post.mock.calls[0]?.[1] as Record<string, unknown>;
    expect("force_send" in body).toBe(false);
    expect("return_otp" in body).toBe(false);
  });

  it("forwards optional fields when provided", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce(validResponse);

    await generateOtpTool.handler(
      {
        phone_number: "14155551234",
        channel: "whatsapp",
        otp_length: 6,
        otp_validity: 600,
        brand: "Acme",
        template_order: 2,
      },
      { client: asMyOtpClient(client), apiKey: "k" }
    );

    const body = client.post.mock.calls[0]?.[1] as Record<string, unknown>;
    expect(body).toMatchObject({
      phone_number: "14155551234",
      channel: "whatsapp",
      otp_length: 6,
      otp_validity: 600,
      brand: "Acme",
      template_order: 2,
    });
  });
});

describe("generate_otp — response mapping", () => {
  it("maps a successful response to structuredContent and a summary line", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce(validResponse);

    const result = await generateOtpTool.handler(
      { phone_number: "14155551234" },
      { client: asMyOtpClient(client), apiKey: "k" }
    );

    expect(result.isError).toBeFalsy();
    expect(result.structuredContent).toEqual(validResponse);
    expect(result.content[0]?.text).toContain(validResponse.message_id);
    expect(result.content[0]?.text).toContain(`expires_at=${validResponse.expires_at}`);
  });

  it("includes the OTP in the summary when return_otp came back", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce({ ...validResponse, otp: "123456" });

    const result = await generateOtpTool.handler(
      { phone_number: "14155551234", return_otp: true },
      { client: asMyOtpClient(client), apiKey: "k" }
    );

    expect(result.content[0]?.text).toContain("otp=123456");
  });

  it("maps API errors into a tool error result with isError=true", async () => {
    const client = makeFakeClient();
    client.post.mockRejectedValueOnce(
      new MyOtpApiError("API error (402): Insufficient balance", 402, "/generate_otp", { code: "no_balance" })
    );

    const result = await generateOtpTool.handler(
      { phone_number: "14155551234" },
      { client: asMyOtpClient(client), apiKey: "k_secret_value" }
    );

    expect(result.isError).toBe(true);
    expect(result.content[0]?.text).toContain("Insufficient balance");
    expect(result.structuredContent?.status).toBe(402);
    // Defense in depth: the API key must never leak into error text.
    expect(JSON.stringify(result)).not.toContain("k_secret_value");
  });
});
