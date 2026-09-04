/**
 * get_account_status - GET https://api.myotp.app/v1/agent/account
 *
 * Optionally requests another verification email before fetching the current
 * agent-account state.
 */

import { z } from "zod";
import type {
  AgentAccountResponse,
  ResendVerificationResponse,
} from "../types.js";
import { ok, toToolError } from "./helpers.js";
import type { ToolDefinition } from "./types.js";

const inputSchema = {
  resend_verification: z
    .boolean()
    .optional()
    .describe("Send another confirmation email before returning account status."),
};

const outputSchema = {
  email_verified: z.boolean().describe("True once the human has confirmed the emailed link. Unlocks card top-ups."),
  balance: z.number().describe("Credits on the balance."),
  plan_id: z.number().int(),
  status: z.string().describe("Account status, 'active' when the key can be used."),
  hint: z.string().describe("What to do next: verify the email, top up, or start sending."),
};

export const getAccountStatusTool: ToolDefinition<typeof inputSchema> = {
  name: "get_account_status",
  title: "Get agent account status",
  description:
    "Return email verification, balance, plan, and status for the configured MyOTP agent account. " +
    "Set resend_verification to request another confirmation email first. Unverified accounts can top up with USDC, but cards stay locked.",
  inputSchema,
  outputSchema,
  annotations: {
    readOnlyHint: false,
    idempotentHint: false,
    destructiveHint: false,
    openWorldHint: true,
  },
  handler: async (args, ctx) => {
    try {
      const resent = args.resend_verification === true;
      if (resent) {
        await ctx.client.post<ResendVerificationResponse>(
          "/v1/agent/resend-verification",
          {},
          ctx.apiKey
        );
      }

      const account = await ctx.client.get<AgentAccountResponse>(
        "/v1/agent/account",
        ctx.apiKey
      );
      const hint = buildAccountHint(account);
      const result: Record<string, unknown> = {
        email_verified: account.email_verified,
        balance: account.balance,
        plan_id: account.plan_id,
        status: account.status,
        hint,
      };
      const resendSummary = resent ? "Verification email resend requested. " : "";

      return ok(
        result,
        `${resendSummary}Account status: ${account.status}; balance=${account.balance}. Hint: ${hint}.`
      );
    } catch (err) {
      return toToolError(err, "Failed to fetch account status");
    }
  },
};

function buildAccountHint(account: AgentAccountResponse): string {
  const hints: string[] = [];
  if (!account.email_verified) hints.push("cards locked, USDC open");
  if (account.balance === 0) hints.push("top up");
  return hints.length > 0 ? hints.join("; ") : "ready";
}
