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

const outputSchema = {
  DLR: z
    .string()
    .optional()
    .describe(
      "Delivery state: carrier status (ATES, DELIVRD, UNDELIV, EXPIRED, REJECTD) or sent/delivered/read/pending/failed.<reason>, a 'Pending: ...' hint, or a 'Not available ...' explanation. Absent when the message_id is unknown."
    ),
  is_active: z.boolean().optional().describe("Whether the OTP can still be verified (it has not expired)."),
  expires_at: z.string().optional().describe("ISO 8601 date-time the OTP expires. Absent when the message_id is unknown."),
  message: z.string().optional().describe("Present instead of DLR when the message_id is not found."),
  "DLR:": z.string().optional().describe("Deprecated alias of DLR."),
  "Message:": z.string().optional().describe("Deprecated alias of message."),
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
  outputSchema,
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
