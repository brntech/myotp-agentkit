/**
 * check_otp_status — POST https://api.myotp.app/check_otp_status
 *
 * Checks whether an OTP is still active and (on Enterprise plans) what its
 * delivery status is. Does NOT verify the code — use verify_otp for that.
 */

import { z } from "zod";
import type { CheckOtpStatusResponse } from "../types.js";
import { ok, toToolError } from "./helpers.js";
import type { ToolDefinition } from "./types.js";

const inputSchema = {
  message_id: z
    .string()
    .uuid()
    .describe("The UUID returned by `generate_otp` — this identifies which OTP you want a status report on."),
};

export const checkOtpStatusTool: ToolDefinition<typeof inputSchema> = {
  name: "check_otp_status",
  title: "Check OTP delivery status",
  description:
    "Check whether a previously sent OTP is still active and (with DLR_ACCESS entitlement on Enterprise plan) get its delivery status. " +
    "Returns `is_active` (bool) and `expires_at` (ISO timestamp) on every plan. " +
    "On Enterprise plans, also returns `DLR` (one of 'delivered', 'sent', 'read', 'failed', 'pending'). " +
    "Useful when an end user reports they didn't receive the code — you can confirm whether MyOTP delivered it before deciding to resend. " +
    "Does NOT verify a code; use `verify_otp` for that.",
  inputSchema,
  annotations: {
    readOnlyHint: true,
    idempotentHint: true,
    destructiveHint: false,
    openWorldHint: true,
  },
  handler: async (args, ctx) => {
    try {
      const result = await ctx.client.post<CheckOtpStatusResponse>(
        "/check_otp_status",
        { message_id: args.message_id },
        ctx.apiKey
      );

      const summary =
        `OTP active=${result.is_active} expires_at=${result.expires_at}` +
        (result.DLR ? ` delivery=${result.DLR}` : "");
      return ok(result, summary);
    } catch (err) {
      return toToolError(err, "Failed to check OTP status");
    }
  },
};
