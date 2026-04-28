import VerifyForm from "./VerifyForm";

export default function VerifyPage({
  searchParams,
}: {
  searchParams: { mid?: string; phone?: string; channel?: string };
}) {
  const messageId = searchParams.mid ?? "";
  const phone = searchParams.phone ?? "";
  const channel = searchParams.channel ?? "sms";

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600">
        We sent a code via <strong>{channel}</strong> to <strong>+{phone}</strong>. Enter it below.
      </p>
      <VerifyForm messageId={messageId} phone={phone} />
    </div>
  );
}
