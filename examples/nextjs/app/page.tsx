import PhoneForm from "./PhoneForm";

export default function HomePage() {
  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600">
        Enter your phone number in international format (no leading +). MyOTP will send a 6-digit code.
      </p>
      <PhoneForm />
    </div>
  );
}
