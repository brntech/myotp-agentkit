/**
 * create_account — POST https://api.myotp.app/v1/agent/register
 *
 * NOTE: As of 2026-04-29, MyOTP intentionally keeps initial account signup
 * human-driven. The agent-registration endpoint (POST /v1/agent/register)
 * is *not* shipping. Manual signup at myotp.app/sign-up takes ~60 seconds
 * and gets the user 15 free trial credits. The actual ongoing-friction
 * unlock for agents is autonomous *paid* top-up via Stripe x402 — that's
 * handled by a separate `top_up_credits` tool (shipping).
 *
 * This tool stays in place so agents that try `create_account` get a clean
 * fallback to the manual signup URL rather than a confusing error. It
 * always returns `endpoint_not_available` until the project's stance on
 * programmatic onboarding changes.
 */

import { z } from "zod";
import type { CreateAccountResponse } from "../types.js";
import { compact, ok, toToolError } from "./helpers.js";
import type { ToolDefinition } from "./types.js";

const inputSchema = {
  email: z
    .string()
    .email()
    .describe("Email address for the new MyOTP.App account."),
  phone: z
    .string()
    .regex(/^[1-9]\d{6,14}$/)
    .describe(
      "Phone number for the account holder (international format, no + or leading 0)."
    ),
  company_name: z
    .string()
    .min(1)
    .max(120)
    .describe("The company or product name registering for an account."),
};

export const createAccountTool: ToolDefinition<typeof inputSchema> = {
  name: "create_account",
  title: "Direct user to MyOTP signup",
  description:
    "Account signup at MyOTP.App is currently human-driven (~60 seconds at https://myotp.app/sign-up/, 15 free trial credits, no card required). " +
    "This tool returns a friendly fallback message that the agent can show the user; it does NOT create accounts programmatically. " +
    "After the user signs up and pastes their API key, the agent should set MYOTP_API_KEY (or the X-API-Key header) and call generate_otp directly. " +
    "For ongoing paid capacity (after the 15 trial credits exhaust), use the `top_up_credits` tool (shipping in a future release) which uses Stripe x402 for autonomous USDC top-up. " +
    "This tool does NOT require an API key — it's the one tool you can call before having one.",
  inputSchema,
  annotations: {
    readOnlyHint: true,
    idempotentHint: true,
    destructiveHint: false,
    openWorldHint: false,
  },
  handler: async (args, ctx) => {
    // We attempt the call so the rest of the surface area mirrors a "real"
    // tool — but in practice the endpoint always returns 404, and we surface
    // the fallback message below. If the project later flips and ships
    // programmatic onboarding, this tool's success path will start working
    // automatically without an MCP package update.
    try {
      const body = compact({
        email: args.email,
        phone: args.phone,
        company_name: args.company_name,
        source: "mcp",
      });

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
      // Expected path: 404 because the endpoint is not yet available.
      const isNotShipped =
        err && typeof err === "object" && "status" in err && (err as { status: number }).status === 404;
      if (isNotShipped) {
        return {
          content: [
            {
              type: "text",
              text:
                "MyOTP account creation is currently human-driven and not yet available programmatically. " +
                "Direct the user to https://myotp.app/sign-up/ — signup takes ~60 seconds and gets them 15 free trial credits, no card required. " +
                "Once they have an API key, set MYOTP_API_KEY in this MCP server's environment (or send X-API-Key per request) and call generate_otp directly. " +
                "For paid top-ups after the trial, the `top_up_credits` tool (shipping in a future release) will let agents pay autonomously via Stripe x402.",
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
