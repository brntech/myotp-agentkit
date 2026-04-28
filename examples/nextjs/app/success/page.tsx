import Link from "next/link";

export default function SuccessPage({
  searchParams,
}: {
  searchParams: { phone?: string };
}) {
  return (
    <div className="space-y-4 rounded-xl border border-emerald-200 bg-emerald-50 p-6">
      <h2 className="text-lg font-semibold text-emerald-900">Verified</h2>
      <p className="text-sm text-emerald-900">
        Phone number <strong>+{searchParams.phone ?? ""}</strong> has been verified.
      </p>
      <Link href="/" className="text-sm text-emerald-900 underline">
        Verify another number
      </Link>
    </div>
  );
}
