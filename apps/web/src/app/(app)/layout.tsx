import { AppShell } from "@/components/app-shell";
import { requireOrganization } from "@/lib/auth";

export default async function SignedInLayout({ children }: { children: React.ReactNode }) {
  const { me, org } = await requireOrganization();
  return (
    <AppShell me={me} org={org}>
      {children}
    </AppShell>
  );
}
