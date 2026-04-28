/**
 * get_account_info — GET https://api.myotp.app/me
 *
 * Returns basic account information for the authenticated API key. Currently
 * the public endpoint returns just `email`, but we type it as open-ended in
 * case the platform extends it (balance/credits/plan are good candidates).
 */

import type { AccountInfoResponse } from "../types.js";
import { ok, toToolError } from "./helpers.js";
import type { ToolDefinition } from "./types.js";

const inputSchema = {} as const;

export const getAccountInfoTool: ToolDefinition<typeof inputSchema> = {
  name: "get_account_info",
  title: "Get account info",
  description:
    "Return account details for the API key in use. Always returns at least the account `email`; depending on plan and platform version may also return balance/credit/plan info. " +
    "Use this as a sanity check when wiring up MyOTP for the first time — if this call succeeds, your API key and IP whitelist are configured correctly.",
  inputSchema,
  annotations: {
    readOnlyHint: true,
    idempotentHint: true,
    destructiveHint: false,
    openWorldHint: true,
  },
  handler: async (_args, ctx) => {
    try {
      const result = await ctx.client.get<AccountInfoResponse>("/me", ctx.apiKey);
      return ok(result, `Account email: ${result.email}`);
    } catch (err) {
      return toToolError(err, "Failed to fetch account info");
    }
  },
};
