/**
 * generate_otp — POST https://api.myotp.app/generate_otp
 *
 * The headline tool. This is the one agents will call most: send a one-time
 * password to a phone number via SMS, WhatsApp, or Telegram. Returns the
 * `message_id` the agent will need later for verify_otp / check_otp_status.
 */

import { z } from "zod";
import type { GenerateOtpResponse } from "../types.js";
import { compact, ok, toToolError } from "./helpers.js";
import type { ToolDefinition } from "./types.js";

const inputSchema = {
  phone_number: z
    .string()
    .min(7)
    .max(15)
    .regex(/^[1-9]\d{6,14}$/)
    .describe(
      "Destination phone number in international format with NO leading + or 0. Must be 7-15 digits and start with a non-zero digit. Example: '14155551234' for a US number, '447911123456' for a UK number."
    ),
  channel: z
    .enum(["sms", "whatsapp", "telegram"])
    .optional()
    .describe(
      "Delivery channel. 'sms' (default) works in 190+ countries. 'whatsapp' is best for India/Brazil/Indonesia/Mexico/Nigeria/Turkey. 'telegram' is best for privacy-focused users. Same API for all three."
    ),
  otp_length: z
    .number()
    .int()
    .min(3)
    .max(8)
    .optional()
    .describe(
      "Number of digits in the auto-generated OTP. Range 3-8 (4-8 for telegram). Default 6. Requires CUSTOM_OTP_LENGTH entitlement (Business plan or above)."
    ),
  otp_code: z
    .string()
    .regex(/^\d{3,8}$/)
    .optional()
    .describe(
      "Provide your own pre-generated numeric OTP code (3-8 digits, 4-8 for telegram) instead of letting MyOTP generate one. Useful when you already have a code from another system."
    ),
  otp_validity: z
    .number()
    .int()
    .min(30)
    .max(14400)
    .optional()
    .describe(
      "How long the OTP stays valid, in seconds. Range 30-14400 (30-3600 for telegram). Default 300 (5 minutes). Requires CUSTOM_OTP_EXPIRY entitlement (Business plan or above)."
    ),
  brand: z
    .string()
    .min(3)
    .max(16)
    .regex(/^[a-zA-Z0-9.]+$/)
    .optional()
    .describe(
      "Sender brand name shown to the recipient (3-16 alphanumeric characters plus dots). Defaults to the brand registered against the API key, or 'MyOTP.App' if none."
    ),
  return_otp: z
    .boolean()
    .optional()
    .describe(
      "If true, the API response will include the generated OTP code in plain text. Useful for testing or when you want to deliver the OTP via your own channel. Defaults to false. SECURITY: never enable this in production user flows."
    ),
  force_send: z
    .boolean()
    .optional()
    .describe(
      "If true, send a new OTP even if one is already active for this phone number. By default the API returns 409 in that case. Use sparingly — repeated sends to the same number can hit carrier-level spam filters."
    ),
  template_order: z
    .number()
    .int()
    .min(1)
    .max(99)
    .optional()
    .describe(
      "Pick a specific message template by its order number (1-99). Requires ACCESS_TO_TEMPLATES entitlement. Not supported on telegram (Telegram generates its own message text)."
    ),
};

export const generateOtpTool: ToolDefinition<typeof inputSchema> = {
  name: "generate_otp",
  title: "Send OTP",
  description:
    "Send a one-time password (OTP) to a phone number via SMS, WhatsApp, or Telegram. " +
    "MyOTP.App generates the code, formats the message, picks the best carrier route, and delivers it. " +
    "Returns a `message_id` (UUID) — keep it; you'll pass it to `verify_otp`, `check_otp_status`, or `extend_otp` later. " +
    "Each call deducts credits from the account balance; the per-message cost varies by destination country and channel and is returned in the `cost` field. " +
    "Use this whenever an app needs to verify someone's phone — signup, login 2FA, password reset, transaction confirmation, etc.",
  inputSchema,
  annotations: {
    // Sending an OTP costs money and triggers a real SMS/message — definitely not idempotent.
    readOnlyHint: false,
    idempotentHint: false,
    destructiveHint: false,
    openWorldHint: true,
  },
  handler: async (args, ctx) => {
    try {
      // Convert booleans to "true"/"false" strings — the MyOTP API expects string values
      // for `force_send` and `return_otp` per the public spec.
      const body = compact({
        phone_number: args.phone_number,
        channel: args.channel,
        otp_length: args.otp_length,
        otp_code: args.otp_code,
        otp_validity: args.otp_validity,
        brand: args.brand,
        return_otp: args.return_otp === undefined ? undefined : String(args.return_otp),
        force_send: args.force_send === undefined ? undefined : String(args.force_send),
        template_order: args.template_order,
      });

      const result = await ctx.client.post<GenerateOtpResponse>(
        "/generate_otp",
        body,
        ctx.apiKey
      );

      const summary =
        `OTP queued. message_id=${result.message_id} expires_at=${result.expires_at} cost=${result.cost}` +
        (result.otp ? ` otp=${result.otp}` : "");
      return ok(result, summary);
    } catch (err) {
      return toToolError(err, "Failed to send OTP");
    }
  },
};
