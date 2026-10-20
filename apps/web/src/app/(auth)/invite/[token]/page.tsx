import Link from "next/link";
import { apiRequest } from "@/lib/api";
import { AcceptForm } from "./accept-form";

export const metadata = { title: "Join an organization" };

type InvitationView = {
  organization_name: string;
  email: string;
  role_label: string;
  account_exists: boolean;
  expires_at: string;
};

export default async function InvitePage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const res = await apiRequest<InvitationView>(`/invitations/${encodeURIComponent(token)}`, { auth: false });

  if (!res.ok) {
    return (
      <>
        <h1 className="text-2xl font-semibold tracking-tight">This invitation is no longer valid</h1>
        <p className="mt-3 text-sm text-muted">{res.error.message}</p>
        <p className="mt-6 text-sm">
          <Link href="/login" className="text-signal underline-offset-4 hover:underline">
            Go to sign in
          </Link>
        </p>
      </>
    );
  }

  const invite = res.data;
  return (
    <>
      <h1 className="text-2xl font-semibold tracking-tight">Join {invite.organization_name}</h1>
      <p className="mt-3 text-sm text-muted">
        You are invited as {invite.role_label}, using {invite.email}.{" "}
        {invite.account_exists ? "Confirm with your RuleRadar password." : "Create your account to accept."}
      </p>
      <AcceptForm token={token} accountExists={invite.account_exists} />
    </>
  );
}
