import AuthLayout from "@/app/(auth)/layout";
import { logoutAction } from "@/app/(auth)/actions";
import { buttonClass } from "@/components/ui/button";
import { requireAccount } from "@/lib/auth";

export const metadata = { title: "No organization" };

export default async function NoOrganizationPage() {
  const me = await requireAccount();

  return (
    <AuthLayout>
      <h1 className="text-2xl font-semibold tracking-tight">You are not part of an organization</h1>
      <p className="mt-3 text-sm text-muted">
        {me.user.email} is not an active member of any organization on RuleRadar. Ask your administrator to invite
        you, then open the link in the invitation email.
      </p>
      <form action={logoutAction} className="mt-6">
        <button type="submit" className={buttonClass("secondary")}>
          Sign out
        </button>
      </form>
    </AuthLayout>
  );
}
