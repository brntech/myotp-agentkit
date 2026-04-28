/**
 * create_account — POST https://api.myotp.app/v1/agent/register
 *
 * PLACEHOLDER. The agent-registration endpoint is part of the planned
 * "programmatic onboarding API" that's not yet shipped (see strategy doc §4.1).
 * Until it's live, this tool calls the endpoint anyway and surfaces whatever
 * response (or 404) it gets back — agents can show the user a helpful message
 * and direct them to https://myotp.app/sign-up/ as a fallback.
 *
 * When the onboarding API ships, we'll add a paired `verify_account` tool
 * that takes the email/phone code and returns the freshly-issued API key.
 */

import { z } from "zod";
import type { CreateAccountResponse } from "../types.js";
import { compact, ok, toToolError } from "./helpers.js";
import type { ToolDefinition } from "./types.js";

const inputSchema = {
  email: z
    .string()
    .email()
    .describe("Email address for the new MyOTP.App account. Will receive a verification code."),
  phone: z
    .string()
    .regex(/^[1-9]\d{6,14}$/)
    .describe(
      "Phone number for the account holder (international format, no + or leading 0). May be used for phone verification during onboarding."
    ),
  company_name: z
    .string()
    .min(1)
    .max(120)
    .describe("The company or product name registering for an account."),
};

export const createAccountTool: ToolDefinition<typeof inputSchema> = {
  name: "create_account",
  title: "Create MyOTP account (programmatic onboarding)",
  description:
    "Register a new MyOTP.App account programmatically. " +
    "INTENDED USE: an AI agent helping a user set up phone verification for the first time can call this to bootstrap the account, then call a follow-up `verify_account` tool (coming soon) to confirm the email/phone and receive an API key. " +
    "STATUS: the agent-registration endpoint (POST /v1/agent/register) is not yet live as of this MCP release. Until it ships, this tool will return a 404 — when that happens, ask the user to visit https://myotp.app/sign-up/ to create an account and generate an API key in the dashboard. " +
    "Note: this tool does NOT require an API key — it's the one tool you can call before having one.",
  inputSchema,
  annotations: {
    readOnlyHint: false,
    idempotentHint: false,
    destructiveHint: false,
    openWorldHint: true,
  },
  handler: async (args, ctx) => {
    try {
      const body = compact({
        email: args.email,
        phone: args.phone,
        company_name: args.company_name,
        source: "mcp",
      });

      // This endpoint accepts requests without an API key, but the client
      // requires one. Use a sentinel value so the request still goes out;
      // if the API later requires real auth here we'll surface the 401.
      const apiKeyForRegistration = ctx.apiKey || "registration";

      const result = await ctx.client.post<CreateAccountResponse>(
        "/v1/agent/register",
        body,
        apiKeyForRegistration
      );

      return ok(
        result as unknown as Record<string, unknown>,
        `Account registration submitted. ${result.message ?? "Check your email for a verification code."}`
      );
    } catch (err) {
      // Graceful fallback: 404 means the endpoint isn't live yet.
      const isNotShipped =
        err && typeof err === "object" && "status" in err && (err as { status: number }).status === 404;
      if (isNotShipped) {
        return {
          content: [
            {
              type: "text",
              text:
                "The programmatic onboarding API (POST /v1/agent/register) is not yet available. " +
                "Please direct the user to sign up at https://myotp.app/sign-up/ — they'll receive 15 free trial credits and can generate an API key in the dashboard. " +
                "Once they have an API key, set MYOTP_API_KEY in this MCP server's environment (or send X-API-Key on each HTTP request) and call generate_otp.",
            },
          ],
          structuredContent: {
            error: "endpoint_not_available",
            signup_url: "https://myotp.app/sign-up/",
            dashboard_url: "https://myotp.app/login/",
          },
          isError: true,
        };
      }
      return toToolError(err, "Failed to create account");
    }
  },
};
