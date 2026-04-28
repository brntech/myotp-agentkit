import { describe, expect, it } from "vitest";
import { z } from "zod";
import { createAccountTool } from "../../src/tools/create_account.js";
import { MyOtpApiError } from "../../src/types.js";
import { asMyOtpClient, makeFakeClient } from "../helpers/fake-client.js";

const inputSchema = z.object(createAccountTool.inputSchema);

describe("create_account — input validation", () => {
  it("rejects a malformed email", () => {
    expect(
      inputSchema.safeParse({ email: "not-an-email", phone: "14155551234", company_name: "Acme" }).success
    ).toBe(false);
  });

  it("rejects a phone with a leading +", () => {
    expect(
      inputSchema.safeParse({ email: "a@b.com", phone: "+14155551234", company_name: "Acme" }).success
    ).toBe(false);
  });

  it("rejects an empty company_name", () => {
    expect(
      inputSchema.safeParse({ email: "a@b.com", phone: "14155551234", company_name: "" }).success
    ).toBe(false);
  });

  it("accepts well-formed input", () => {
    expect(
      inputSchema.safeParse({ email: "a@b.com", phone: "14155551234", company_name: "Acme" }).success
    ).toBe(true);
  });
});

describe("create_account — handler", () => {
  it("posts to /v1/agent/register with a `source: 'mcp'` body", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce({ account_id: "acct_1", status: "pending", message: "verify your email" });

    await createAccountTool.handler(
      { email: "user@example.com", phone: "14155551234", company_name: "Acme Corp" },
      { client: asMyOtpClient(client), apiKey: "" }
    );

    const [path, body, key] = client.post.mock.calls[0] ?? [];
    expect(path).toBe("/v1/agent/register");
    expect(body).toMatchObject({
      email: "user@example.com",
      phone: "14155551234",
      company_name: "Acme Corp",
      source: "mcp",
    });
    // API key may be empty; client falls back to a sentinel.
    expect(typeof key).toBe("string");
  });

  it("returns a graceful 'endpoint_not_available' result when API responds with 404", async () => {
    const client = makeFakeClient();
    client.post.mockRejectedValueOnce(
      new MyOtpApiError("Not Found", 404, "/v1/agent/register", null)
    );

    const result = await createAccountTool.handler(
      { email: "user@example.com", phone: "14155551234", company_name: "Acme" },
      { client: asMyOtpClient(client), apiKey: "" }
    );

    expect(result.isError).toBe(true);
    expect(result.content[0]?.text).toContain("not yet available");
    expect(result.content[0]?.text).toContain("https://myotp.app/sign-up/");
    expect(result.structuredContent).toMatchObject({
      error: "endpoint_not_available",
      signup_url: "https://myotp.app/sign-up/",
    });
  });

  it("does NOT throw on 404 — surfaces the fallback as a normal tool result", async () => {
    const client = makeFakeClient();
    client.post.mockRejectedValueOnce(
      new MyOtpApiError("Not Found", 404, "/v1/agent/register", null)
    );

    await expect(
      createAccountTool.handler(
        { email: "user@example.com", phone: "14155551234", company_name: "Acme" },
        { client: asMyOtpClient(client), apiKey: "" }
      )
    ).resolves.toBeDefined();
  });

  it("maps non-404 errors to a normal tool error", async () => {
    const client = makeFakeClient();
    client.post.mockRejectedValueOnce(
      new MyOtpApiError("Bad Request", 400, "/v1/agent/register", null)
    );

    const result = await createAccountTool.handler(
      { email: "user@example.com", phone: "14155551234", company_name: "Acme" },
      { client: asMyOtpClient(client), apiKey: "" }
    );

    expect(result.isError).toBe(true);
    expect(result.content[0]?.text).toContain("Bad Request");
    expect(result.structuredContent?.status).toBe(400);
  });

  it("maps a successful registration response", async () => {
    const client = makeFakeClient();
    client.post.mockResolvedValueOnce({ account_id: "acct_42", status: "pending", message: "Check your inbox" });

    const result = await createAccountTool.handler(
      { email: "user@example.com", phone: "14155551234", company_name: "Acme" },
      { client: asMyOtpClient(client), apiKey: "" }
    );

    expect(result.isError).toBeFalsy();
    expect(result.structuredContent).toMatchObject({ account_id: "acct_42" });
    expect(result.content[0]?.text).toContain("Check your inbox");
  });
});
