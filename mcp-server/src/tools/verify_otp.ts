/**
 * verify_otp — POST https://api.myotp.app/verify_otp
 *
 * Confirms whether a code submitted by an end user matches the active OTP.
 * Either `phone_number` OR `message_id` is required (alongside `otp`).
 */

import { z } from "zod";
import type { VerifyOtpResponse } from "../types.js";
import { compact, ok, toToolError } from "./helpers.js";
import type { ToolDefinition } from "./types.js";

const inputSchema = {
  otp: z
    .string()
    .regex(/^\d{3,8}$/)
    .describe(
      "The OTP code the end user typed in (3-8 numeric digits). This is the code you're trying to verify against what was sent."
    ),
  phone_number: z
    .string()
    .regex(/^[1-9]\d{6,14}$/)
    .optional()
    .describe(
      "Phone number the OTP was originally sent to, in international format without + or leading 0. Provide either this OR `message_id` — `message_id` is more precise."
    ),
  message_id: z
    .string()
    .uuid()
    .optional()
    .describe(
      "The UUID returned by `generate_otp`. Provide either this OR `phone_number`. Prefer this when you have it — it disambiguates if the same number got multiple OTPs."
    ),
};

export const verifyOtpTool: ToolDefinition<typeof inputSchema> = {
  name: "verify_otp",
  title: "Verify OTP",
  description:
    "Verify a code submitted by an end user against the OTP MyOTP delivered. " +
    "Returns `{status: 'success'}` if the code matches and the OTP hasn't expired — at that point the OTP is consumed and cannot be reused. " +
    "Returns `{status: 'failed', reason: 'invalid' | 'expired' | 'not found'}` otherwise. " +
    "You MUST pass either `phone_number` or `message_id` to identify which OTP you're verifying against. " +
    "Call this after collecting the code from the user (login form, signup screen, etc.).",
  inputSchema,
  annotations: {
    // Verification consumes the OTP on success — not idempotent.
    readOnlyHint: false,
    idempotentHint: false,
    destructiveHint: false,
    openWorldHint: true,
  },
  handler: async (args, ctx) => {
    if (!args.phone_number && !args.message_id) {
      return {
        content: [
          {
            type: "text",
            text:
              "verify_otp requires either `phone_number` or `message_id`. " +
              "Pass the message_id you got back from generate_otp, or the phone number the OTP was sent to.",
          },
        ],
        isError: true,
      };
    }

    try {
      const body = compact({
        otp: args.otp,
        phone_number: args.phone_number,
        message_id: args.message_id,
      });

      const result = await ctx.client.post<VerifyOtpResponse>(
        "/verify_otp",
        body,
        ctx.apiKey
      );

      const summary =
        result.status === "success"
          ? "OTP verified successfully."
          : `OTP verification failed: ${result.reason ?? "unknown"} — ${result.message}`;
      return ok(result, summary);
    } catch (err) {
      return toToolError(err, "Failed to verify OTP");
    }
  },
};
