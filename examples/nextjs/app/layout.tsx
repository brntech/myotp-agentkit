import "./globals.css";
import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "MyOTP.App + Next.js example",
  description: "Phone verification with MyOTP.App and Next.js Server Actions",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body>
        <main className="mx-auto max-w-md px-6 py-16">
          <h1 className="mb-8 text-2xl font-semibold">Phone verification</h1>
          {children}
        </main>
      </body>
    </html>
  );
}
