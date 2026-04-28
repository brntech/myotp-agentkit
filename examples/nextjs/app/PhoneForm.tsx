"use client";

import { useFormState, useFormStatus } from "react-dom";
import { sendOtp, type FormState } from "./actions";

const initialState: FormState = {};

export default function PhoneForm() {
  const [state, action] = useFormState(sendOtp, initialState);

  return (
    <form action={action} className="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <label className="block">
        <span className="mb-1 block text-sm font-medium">Phone number</span>
        <input
          name="phone"
          type="tel"
          inputMode="numeric"
          required
          placeholder="14155551234"
          className="block w-full rounded-md border border-slate-300 px-3 py-2"
        />
      </label>

      <fieldset>
        <legend className="mb-1 text-sm font-medium">Channel</legend>
        <div className="flex gap-4 text-sm">
          <label className="flex items-center gap-2">
            <input type="radio" name="channel" value="sms" defaultChecked />
            SMS
          </label>
          <label className="flex items-center gap-2">
            <input type="radio" name="channel" value="whatsapp" />
            WhatsApp
          </label>
          <label className="flex items-center gap-2">
            <input type="radio" name="channel" value="telegram" />
            Telegram
          </label>
        </div>
      </fieldset>

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
      {pending ? "Sending..." : "Send code"}
    </button>
  );
}
