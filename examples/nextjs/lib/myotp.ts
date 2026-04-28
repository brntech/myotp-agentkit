/**
 * Thin server-side client for the MyOTP.App REST API.
 *
 * This file must only be imported from server code (Server Actions, route
 * handlers, server components). The MYOTP_API_KEY env var is read at request
 * time so it never reaches the browser bundle.
 *
 * API reference: https://api.myotp.app
 */

const BASE_URL = process.env.MYOTP_BASE_URL ?? "https://api.myotp.app";

export type Channel = "sms" | "whatsapp" | "telegram";

export interface GenerateOtpResponse {
  message_id: string;
  status: string;
  message: string;
  date_sent: string;
  expires_at: string;
  cost: number;
  otp?: string;
}

export interface VerifyOtpResponse {
  status: "success" | "failed";
  message: string;
  reason?: "invalid" | "expired" | "not found";
}

export class MyOtpError extends Error {
  constructor(public status: number, message: string) {
    super(message);
    this.name = "MyOtpError";
  }
}

function apiKey(): string {
  const key = process.env.MYOTP_API_KEY;
  if (!key) {
    throw new MyOtpError(500, "MYOTP_API_KEY is not configured");
  }
  return key;
}

async function post<T>(path: string, body: unknown): Promise<T> {
  const res = await fetch(`${BASE_URL}${path}`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-API-Key": apiKey(),
    },
    body: JSON.stringify(body),
    cache: "no-store",
  });

  const data = (await res.json().catch(() => ({}))) as Record<string, unknown>;

  if (!res.ok) {
    const message =
      (data.message as string | undefined) ??
      (data.error as string | undefined) ??
      `MyOTP request failed (${res.status})`;
    throw new MyOtpError(res.status, message);
  }

  return data as T;
}

export function generateOtp(params: {
  phone_number: string;
  channel?: Channel;
}): Promise<GenerateOtpResponse> {
  return post<GenerateOtpResponse>("/generate_otp", params);
}

export function verifyOtp(params: {
  otp: string;
  message_id?: string;
  phone_number?: string;
}): Promise<VerifyOtpResponse> {
  return post<VerifyOtpResponse>("/verify_otp", params);
}
