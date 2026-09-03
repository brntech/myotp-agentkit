/**
 * create_account - POST https://api.myotp.app/v1/agent/register
 *
 * Creates an agent account without authentication. The returned API key is
 * shown once by the API and must be saved before making authenticated calls.
 */

import { z } from "zod";
import type { CreateAccountRequest, CreateAccountResponse } from "../types.js";
import { MyOtpApiError } from "../types.js";
import { compact, toToolError } from "./helpers.js";
import type { ToolDefinition } from "./types.js";

const inputSchema = {
  email: z
    .string()
    .email()
    .describe("Email address for the new MyOTP.App account."),
  name: z
    .string()
    .max(64)
    .optional()
    .describe("Optional account, company, or product name (maximum 64 characters)."),
};

export const createAccountTool: ToolDefinition<typeof inputSchema> = {
  name: "create_account",
  title: "Create a MyOTP agent account",
  description:
    "Create a MyOTP.App agent account and return its one-time API key. " +
    "No API key is required for this tool. The new account starts with zero balance; " +
    "USDC top-ups work immediately, while card top-ups unlock after a human confirms the email address.",
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
        name: args.name,
      }) as CreateAccountRequest;

      const result = await ctx.client.postUnauthenticated<CreateAccountResponse>(
        "/v1/agent/register",
        body
      );

      const summary = [
        `Account id: ${result.account_id}; balance is 0.`,
        "The API key below is shown once; set MYOTP_API_KEY / configure the server with it:",
        result.api_key,
        `A confirmation email went to ${result.email}; a human must click it to unlock card top-ups. USDC top-ups work now.`,
        "Next, call `get_topup_quote` or `top_up_credits`.",
      ].join("\n");

      return {
        content: [{ type: "text", text: summary }],
        structuredContent: result,
      };
    } catch (err) {
      if (err instanceof MyOtpApiError && err.status === 409) {
        return toToolError(
          err,
          "Account already exists; the human should use the key from the dashboard instead"
        );
      }
      return toToolError(err, "Failed to create account");
    }
  },
};
