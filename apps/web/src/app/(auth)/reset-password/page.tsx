import Link from "next/link";
import { ResetForm } from "./reset-form";

export const metadata = { title: "Set a new password" };

export default async function ResetPasswordPage({ searchParams }: { searchParams: Promise<{ token?: string; email?: string }> }) {
  const { token, email } = await searchParams;

  if (!token || !email) {
    return (
      <>
        <h1 className="text-2xl font-semibold tracking-tight">This link is incomplete</h1>
        <p className="mt-3 text-sm text-muted">Open the link from your email again, or request a new one.</p>
        <p className="mt-6 text-sm">
          <Link href="/forgot-password" className="text-signal underline-offset-4 hover:underline">
            Request a new reset link
          </Link>
        </p>
      </>
    );
  }

  return (
    <>
      <h1 className="text-2xl font-semibold tracking-tight">Set a new password</h1>
      <p className="mt-3 text-sm text-muted">For {email}. You will be signed out on other devices.</p>
      <ResetForm token={token} email={email} />
    </>
  );
}
