/**
 * Public types for @myotp/better-auth.
 *
 * BetterAuthPhoneSendArgs mirrors the shape Better Auth's phoneNumber() plugin
 * passes to `sendOTP`. We don't import from better-auth directly to keep this
 * package usable even when better-auth isn't installed (the peer dep is optional).
 */

export type MyotpChannel = "sms" | "whatsapp" | "telegram";

export interface BetterAuthPhoneSendArgs {
  phoneNumber: string;
  code: string;
}

export type MyotpSendOtp = (
  args: BetterAuthPhoneSendArgs,
  request?: unknown
) => Promise<void>;

export interface MyotpAdapterOptions {
  /** API key issued in the MyOTP.App dashboard (or via /v1/agent/register). Required. */
  apiKey: string;

  /** Override the MyOTP API base URL. Defaults to https://api.myotp.app. */
  baseUrl?: string;

  /** Delivery channel. Defaults to "sms". */
  channel?: MyotpChannel;

  /** Sender brand shown in the OTP message. Defaults to the API key's configured brand. */
  brand?: string;

  /** OTP validity in seconds. Defaults to 300 (5 minutes). */
  validitySeconds?: number;

  /** Per-request timeout in ms. Defaults to 15000. */
  timeoutMs?: number;

  /** Override User-Agent header. */
  userAgent?: string;

  /** Inject a fetch implementation (testing, edge runtimes). Defaults to globalThis.fetch. */
  fetch?: typeof globalThis.fetch;
}
