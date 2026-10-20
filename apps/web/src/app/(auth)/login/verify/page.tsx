import { redirect } from "next/navigation";
import { getMfaChallenge } from "@/lib/session";
import { VerifyForm } from "./verify-form";

export const metadata = { title: "Two-factor check" };

export default async function VerifyPage({ searchParams }: { searchParams: Promise<{ next?: string }> }) {
  if (!(await getMfaChallenge())) redirect("/login?expired=1");
  const { next } = await searchParams;

  return (
    <>
      <h1 className="text-2xl font-semibold tracking-tight">Enter your sign-in code</h1>
      <p className="mt-3 text-sm text-muted">Open your authenticator app and enter the 6-digit code for RuleRadar.</p>
      <VerifyForm next={next ?? "/"} />
    </>
  );
}
