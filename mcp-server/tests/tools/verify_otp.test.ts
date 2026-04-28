import { describe, expect, it } from "vitest";
import { z } from "zod";
import { verifyOtpTool } from "../../src/tools/verify_otp.js";
import { MyOtpApiError } from "../../src/types.js";
import { asMyOtpClient, makeFakeClient } from "../helpers/fake-client.js";

const inputSchema = z.object(verifyOtpTool.inputSchema);
const VALID_UUID = "11111111-1111-1111-1111-111111111111";

describe("verify_otp — input validation", () => {
  it("rejects an empty otp", () => {
    expect(inputSchema.safeParse({ otp: "" }).success).toBe(false);
  });

  it("rejects an otp with non-digits", () => {
    expect(inputSchema.safeParse({ otp: "12a45" }).success).toBe(false);
  });

  it("rejects an otp shorter than 3 digits", () => {
    expect(inputSchema.safeParse({ otp: "12" }).success).toBe(false);
  });

  it("rejects an otp longer than 8 digits", () => {
    expect(inputSchema.safeParse({ otp: "123456789" }).success).toBe(false);
  });

  it("rejects a malformed message_id", () => {
    expect(
      inputSchema.safeParse({ otp: "123456", message_id: "not-a-uuid" }).success
    ).toBe(false);
  });

  it("accepts a valid otp + message_id", () => {
    expect(
      inputSchema.safeParse({ otp: "123456", message_id: VALID_UUID }).success
    ).toBe(true);
  });
});

describe("verify_otp — handler behavior", () => {
  it("returns an error when neither phone_number nor message_id is supplied", async () => {
    const client = makeFakeClient();
    const result = await verifyOtpTool.handler(
      { otp: "123456" },
      { client: asMyOtpClient(client), apiKey: "k" }
    );
    expect(result.isError).toBe(true);
    expect(result.content[0]?.text).toContain("phone_number");
    expect(result.content[0]?.text).toContain("message_id");
    expect(client.post).not.toHaveBeenCalled();
  });

  it("calls POST /verify_otp with body containing message_id", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce({ status: "success", message: "OTP matched" });

    await verifyOtpTool.handler(
      { otp: "123456", message_id: VALID_UUID },
      { client: asMyOtpClient(client), apiKey: "k" }
    );

    expect(client.post).toHaveBeenCalledTimes(1);
    expect(client.post.mock.calls[0]?.[0]).toBe("/verify_otp");
    expect(client.post.mock.calls[0]?.[1]).toMatchObject({ otp: "123456", message_id: VALID_UUID });
  });

  it("maps a successful match to a positive summary", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce({ status: "success", message: "OTP matched" });

    const result = await verifyOtpTool.handler(
      { otp: "123456", phone_number: "14155551234" },
      { client: asMyOtpClient(client), apiKey: "k" }
    );

    expect(result.isError).toBeFalsy();
    expect(result.content[0]?.text).toContain("OTP verified successfully");
    expect(result.structuredContent).toMatchObject({ status: "success" });
  });

  it("maps a failed match (with reason) to a descriptive summary", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce({
      status: "failed",
      reason: "expired",
      message: "OTP expired 30 seconds ago",
    });

    const result = await verifyOtpTool.handler(
      { otp: "123456", phone_number: "14155551234" },
      { client: asMyOtpClient(client), apiKey: "k" }
    );

    expect(result.isError).toBeFalsy();
    expect(result.content[0]?.text).toContain("expired");
    expect(result.structuredContent?.reason).toBe("expired");
  });

  it("maps API errors to isError=true without leaking the API key", async () => {
    const client = makeFakeClient();
    client.post.mockRejectedValueOnce(
      new MyOtpApiError("MyOTP API error (404): Not found", 404, "/verify_otp", null)
    );

    const result = await verifyOtpTool.handler(
      { otp: "123456", phone_number: "14155551234" },
      { client: asMyOtpClient(client), apiKey: "k_top_secret_42" }
    );

    expect(result.isError).toBe(true);
    expect(JSON.stringify(result)).not.toContain("k_top_secret_42");
    expect(result.content[0]?.text).toContain("Not found");
  });
});
