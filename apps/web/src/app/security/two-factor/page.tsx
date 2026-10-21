import Link from "next/link";
import AuthLayout from "@/app/(auth)/layout";
import { buttonClass } from "@/components/ui/button-styles";
import { requireAccount } from "@/lib/auth";
import { TwoFactorSetup } from "./setup";

export const metadata = { title: "Two-factor login" };

export default async function TwoFactorPage({ searchParams }: { searchParams: Promise<{ onboarding?: string }> }) {
  const me = await requireAccount();
  const { onboarding } = await searchParams;
  const nextHref = onboarding ? "/settings/profile?welcome=1" : "/";

  return (
    <AuthLayout>
      <h1 className="text-2xl font-semibold tracking-tight">Two-factor login</h1>
      {me.user.mfa_enabled ? (
        <>
          <p role="status" className="mt-4 border-l-2 border-signal pl-3 text-sm">
            Two-factor login is on for {me.user.email}.
          </p>
          <p className="mt-3 text-sm text-muted">{me.user.recovery_codes_remaining ?? 0} unused recovery codes remain.</p>
          <Link href="/" className={`${buttonClass("primary")} mt-6`}>
            Go to RuleRadar
          </Link>
        </>
      ) : (
        <>
          <p className="mt-3 text-sm text-muted">
            {me.mfa_enrollment_required
              ? "Your role approves compliance decisions, so RuleRadar asks for a code from your phone each time you sign in."
              : "Add a code from your phone to each sign-in."}
          </p>
          <TwoFactorSetup nextHref={nextHref} />
        </>
      )}
    </AuthLayout>
  );
}
