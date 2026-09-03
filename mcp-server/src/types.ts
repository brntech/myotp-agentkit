/**
 * Shared TypeScript types for the MyOTP API surface.
 * These mirror response shapes documented in the public API reference.
 */

export type Channel = "sms" | "whatsapp" | "telegram";

/** POST /generate_otp success response. */
export interface GenerateOtpResponse {
  message_id: string;
  status: string;
  message: string;
  date_sent: string;
  expires_at: string;
  cost: number;
  /** Present only when `return_otp=true` was passed. */
  otp?: string;
  [key: string]: unknown;
}

/** POST /verify_otp response (always 200 — check `status` field). */
export interface VerifyOtpResponse {
  status: "success" | "failed";
  message: string;
  reason?: "invalid" | "expired" | "not found" | string;
  [key: string]: unknown;
}

/** POST /check_otp_status response. */
export interface CheckOtpStatusResponse {
  /** Only present when DLR_ACCESS entitlement is enabled. */
  DLR?: "delivered" | "sent" | "read" | "failed" | "pending" | string;
  is_active: boolean;
  expires_at: string;
  [key: string]: unknown;
}

/** POST /extend_otp response. */
export interface ExtendOtpResponse {
  status: string;
  message: string;
  expires_at: string;
  [key: string]: unknown;
}

/** GET /me response. */
export interface AccountInfoResponse {
  email: string;
  [key: string]: unknown;
}

/** GET /v1/topup/quote response. */
export interface TopUpQuoteResponse {
  credits: number;
  amount_usd: string;
  price_per_credit_usd: number;
  min_credits: number;
  max_credits: number;
  currency: string;
  methods: string[];
  [key: string]: unknown;
}

/** Payment details returned after a successful top-up. */
export interface TopUpPayment {
  method: string;
  reference: string;
  [key: string]: unknown;
}

/** POST /v1/topup success response. */
export interface TopUpCreditsResponse {
  status: "credited" | "already_credited" | string;
  credits: number;
  amount_usd: string;
  currency: string;
  payment: TopUpPayment;
  balance: number;
  plan_id: number;
  [key: string]: unknown;
}

/** RFC 9457 body returned with a 402 top-up challenge. */
export interface TopUpPaymentRequiredResponse {
  challengeId: string;
  [key: string]: unknown;
}

/** A decoded Payment offer from the WWW-Authenticate challenge. */
export interface TopUpPaymentOffer {
  method: string;
  intent: string;
  id: string;
  expires: string;
  amount: string | number;
  currency: string;
}

/** Raw result of asking the top-up endpoint for a payment challenge. */
export type TopUpFetchResponse =
  | {
      status: 200;
      url: string;
      body: TopUpCreditsResponse;
    }
  | {
      status: 402;
      url: string;
      body: TopUpPaymentRequiredResponse;
      wwwAuthenticate: string;
    };

/** Single transaction row inside a /report response. */
export interface ReportTransaction {
  message_id: string;
  message_timestamp: string;
  message_type: number;
  phone_number: string;
  channel: string;
  country: string;
  force_send: boolean;
  application: string | null;
  cost: number;
  status: string;
  description: string | null;
  client_ip: string | null;
}

/** POST /report response. The endpoint may also return a no-data shape. */
export interface ReportResponse {
  total_count?: number;
  total_pages?: number;
  current_page?: number;
  per_page?: number;
  transactions: ReportTransaction[];
  message?: string;
}

/** Provisional shape for the not-yet-shipped agent registration endpoint. */
export interface CreateAccountResponse {
  account_id?: string;
  status?: string;
  message?: string;
  [key: string]: unknown;
}

/**
 * Structured error returned by the MyOTP client. Caught by tool handlers and
 * surfaced to MCP clients as `isError: true` content.
 */
export class MyOtpApiError extends Error {
  public readonly status: number;
  public readonly endpoint: string;
  public readonly body: unknown;

  constructor(message: string, status: number, endpoint: string, body: unknown) {
    super(message);
    this.name = "MyOtpApiError";
    this.status = status;
    this.endpoint = endpoint;
    this.body = body;
  }
}
