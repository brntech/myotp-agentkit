/**
 * extend_otp — POST https://api.myotp.app/extend_otp
 *
 * Add more time to an active OTP without sending a new one. Requires the
 * EXTEND_OTP entitlement (Business plan or above).
 */

import { z } from "zod";
import type { ExtendOtpResponse } from "../types.js";
import { ok, toToolError } from "./helpers.js";
import type { ToolDefinition } from "./types.js";

const inputSchema = {
  message_id: z
    .string()
    .uuid()
    .describe("The UUID returned by `generate_otp` — identifies the OTP you want to extend."),
  duration: z
    .number()
    .int()
    .min(60)
    .max(14400)
    .describe(
      "Additional seconds to add to the OTP's expiry. Range 60-14400 (1 minute to 4 hours). The new expiry will be the current expiry + this duration."
    ),
};

export const extendOtpTool: ToolDefinition<typeof inputSchema> = {
  name: "extend_otp",
  title: "Extend OTP expiry",
  description:
    "Extend the expiry time of an active OTP without sending a new one. " +
    "Useful when the end user is taking longer than expected to enter the code (e.g., switched apps, dealing with carrier delivery delay). " +
    "Adds `duration` seconds (60-14400) to the current `expires_at`. " +
    "Requires the EXTEND_OTP entitlement (Business or Enterprise plan). Some destination countries don't allow extensions — the API will return 403 in that case. " +
    "Cheaper and less spammy than calling `generate_otp` again.",
  inputSchema,
  annotations: {
    readOnlyHint: false,
    idempotentHint: false,
    destructiveHint: false,
    openWorldHint: true,
  },
  handler: async (args, ctx) => {
    try {
      const result = await ctx.client.post<ExtendOtpResponse>(
        "/extend_otp",
        { message_id: args.message_id, duration: args.duration },
        ctx.apiKey
      );

      return ok(result, `OTP expiry extended. new expires_at=${result.expires_at}`);
    } catch (err) {
      return toToolError(err, "Failed to extend OTP");
    }
  },
};
