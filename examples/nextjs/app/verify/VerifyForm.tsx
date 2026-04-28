"use client";

import { useFormState, useFormStatus } from "react-dom";
import { verifyCode, type FormState } from "../actions";

const initialState: FormState = {};

export default function VerifyForm({
  messageId,
  phone,
}: {
  messageId: string;
  phone: string;
}) {
  const [state, action] = useFormState(verifyCode, initialState);

  return (
    <form action={action} className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <input type="hidden" name="message_id" value={messageId} />
      <input type="hidden" name="phone" value={phone} />

      <label className="block">
        <span className="mb-1 block text-sm font-medium">Verification code</span>
        <input
          name="otp"
          type="text"
          inputMode="numeric"
          autoComplete="one-time-code"
          required
          maxLength={8}
          placeholder="123456"
          className="block w-full rounded-md border border-slate-300 px-3 py-2 tracking-widest"
        />
      </label>

      {state.error && <p className="text-sm text-red-600">{state.error}</p>}
      <SubmitButton />
    </form>
  );
}

function SubmitButton() {
  const { pending } = useFormStatus();
  return (
    <button
      type="submit"
      disabled={pending}
      className="w-full rounded-md bg-slate-900 px-4 py-2 text-white disabled:opacity-50"
    >
      {pending ? "Verifying..." : "Verify"}
    </button>
  );
}
