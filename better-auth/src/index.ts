/**
 * @myotp/better-auth
 *
 * Drop-in MyOTP.App adapter for Better Auth's phoneNumber() plugin.
 *
 * Better Auth generates the OTP code itself, then calls our adapter to deliver
 * it. We use MyOTP's `otp_code` parameter so the platform delivers Better Auth's
 * exact code rather than generating its own. Verification stays inside Better
 * Auth — MyOTP's /verify_otp endpoint is NOT called.
 *
 * Usage:
 *   import { betterAuth } from "better-auth";
 *   import { phoneNumber } from "better-auth/plugins";
 *   import { myotpSendOtp } from "@myotp/better-auth";
 *
 *   export const auth = betterAuth({
 *     plugins: [
 *       phoneNumber({
 *         sendOTP: myotpSendOtp({ apiKey: process.env.MYOTP_API_KEY! }),
 *       }),
 *     ],
 *   });
 */

import type { MyotpAdapterOptions, MyotpSendOtp, BetterAuthPhoneSendArgs } from "./types.js";

export type {
  MyotpAdapterOptions,
  MyotpSendOtp,
  BetterAuthPhoneSendArgs,
  MyotpChannel,
} from "./types.js";

const DEFAULT_BASE_URL = "https://api.myotp.app";
const DEFAULT_TIMEOUT_MS = 15_000;

export class MyotpDeliveryError extends Error {
  public readonly status: number;
  public readonly body: unknown;
  constructor(message: string, status: number, body: unknown) {
    super(message);
    this.name = "MyotpDeliveryError";
    this.status = status;
    this.body = body;
  }
}

/**
 * Build a Better-Auth-compatible sendOTP callback that delivers OTPs via
 * MyOTP.App. The callback signature matches what `phoneNumber()` expects:
 *
 *   sendOTP({ phoneNumber, code }, request?) => Promise<void>
 *
 * - `phoneNumber` is whatever the user typed (we sanitize to digits-only)
 * - `code` is the OTP Better Auth generated (we pass it through, MyOTP just delivers)
 */
export function myotpSendOtp(options: MyotpAdapterOptions): MyotpSendOtp {
  if (!options.apiKey || options.apiKey.trim().length === 0) {
    throw new Error("@myotp/better-auth: apiKey is required");
  }

  const baseUrl = (options.baseUrl ?? DEFAULT_BASE_URL).replace(/\/+$/, "");
  const channel = options.channel ?? "sms";
  const brand = options.brand;
  const timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS;
  const userAgent = options.userAgent ?? "myotp-better-auth/0.1.0";
  const fetchImpl = options.fetch ?? globalThis.fetch;
  const validitySeconds = options.validitySeconds ?? 300;

  if (!fetchImpl) {
    throw new Error("@myotp/better-auth: global fetch is unavailable; pass options.fetch.");
  }

  return async (args: BetterAuthPhoneSendArgs): Promise<void> => {
    const phoneDigits = sanitizePhone(args.phoneNumber);
    if (!phoneDigits) {
      throw new Error(`@myotp/better-auth: invalid phone number "${args.phoneNumber}"`);
    }

    const url = `${baseUrl}/generate_otp`;
    const body: Record<string, unknown> = {
      phone_number: phoneDigits,
      channel,
      otp_code: args.code,
      otp_validity: validitySeconds,
      force_send: "true",
    };
    if (brand) body.brand = brand;

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);

    let response: Response;
    try {
      response = await fetchImpl(url, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          "X-API-Key": options.apiKey,
          "User-Agent": userAgent,
        },
        body: JSON.stringify(body),
        signal: controller.signal,
      });
    } catch (err) {
      if (err instanceof Error && err.name === "AbortError") {
        throw new MyotpDeliveryError(
          `MyOTP delivery timed out after ${timeoutMs}ms`,
          408,
          null
        );
      }
      throw new MyotpDeliveryError(
        `MyOTP delivery failed: ${err instanceof Error ? err.message : String(err)}`,
        0,
        null
      );
    } finally {
      clearTimeout(timer);
    }

    const text = await response.text();
    let parsed: unknown = null;
    if (text.length > 0) {
      try { parsed = JSON.parse(text); } catch { parsed = text; }
    }

    if (!response.ok) {
      const detail = extractMessage(parsed) ?? response.statusText ?? "unknown error";
      throw new MyotpDeliveryError(
        `MyOTP responded ${response.status}: ${detail}`,
        response.status,
        parsed
      );
    }
  };
}

/**
 * Strip non-digits, drop leading zeros and any leading + sign.
 * Returns null if the result isn't a 7-15 digit number starting with 1-9.
 */
export function sanitizePhone(input: string): string | null {
  if (!input) return null;
  const digits = input.replace(/\D+/g, "").replace(/^0+/, "");
  if (digits.length < 7 || digits.length > 15) return null;
  if (digits[0] === "0") return null;
  return digits;
}

function extractMessage(body: unknown): string | null {
  if (body && typeof body === "object") {
    const obj = body as Record<string, unknown>;
    for (const key of ["message", "error", "description", "detail"]) {
      const v = obj[key];
      if (typeof v === "string" && v.trim().length > 0) return v;
    }
  }
  if (typeof body === "string" && body.trim().length > 0) return body;
  return null;
}
