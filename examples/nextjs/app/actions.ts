"use server";

import { redirect } from "next/navigation";
import { generateOtp, verifyOtp, MyOtpError, type Channel } from "@/lib/myotp";
import { sanitisePhone } from "@/lib/phone";

export type FormState = { error?: string };

const VALID_CHANNELS: Channel[] = ["sms", "whatsapp", "telegram"];

export async function sendOtp(_prev: FormState, formData: FormData): Promise<FormState> {
  const phone = sanitisePhone(String(formData.get("phone") ?? ""));
  const channelRaw = String(formData.get("channel") ?? "sms");
  const channel = VALID_CHANNELS.includes(channelRaw as Channel)
    ? (channelRaw as Channel)
    : "sms";

  if (!phone) {
    return { error: "Phone number is required." };
  }

  try {
    const res = await generateOtp({ phone_number: phone, channel });
    // The redirect carries enough state for the verify page to call verify_otp.
    // For a real app, store message_id in a server-side session instead.
    redirect(
      `/verify?mid=${encodeURIComponent(res.message_id)}` +
        `&phone=${encodeURIComponent(phone)}` +
        `&channel=${encodeURIComponent(channel)}`,
    );
  } catch (err) {
    if (err instanceof MyOtpError) return { error: err.message };
    // next/navigation's redirect throws — let it propagate.
    throw err;
  }
}

export async function verifyCode(_prev: FormState, formData: FormData): Promise<FormState> {
  const otp = String(formData.get("otp") ?? "").trim();
  const messageId = String(formData.get("message_id") ?? "").trim();
  const phone = String(formData.get("phone") ?? "").trim();

  if (!otp) return { error: "Enter the code you received." };

  try {
    const res = await verifyOtp({ otp, message_id: messageId || undefined });
    if (res.status !== "success") {
      return { error: res.message || "Verification failed." };
    }
    redirect(`/success?phone=${encodeURIComponent(phone)}`);
  } catch (err) {
    if (err instanceof MyOtpError) return { error: err.message };
    throw err;
  }
}
