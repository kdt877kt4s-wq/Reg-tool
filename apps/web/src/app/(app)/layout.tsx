import { AppShell } from "@/components/app-shell";
import { apiRequest } from "@/lib/api";
import { requireOrganization } from "@/lib/auth";

export default async function SignedInLayout({ children }: { children: React.ReactNode }) {
  const { me, org } = await requireOrganization();
  const unread = await apiRequest<{ unread_count: number }>("/notifications?unread=1&limit=1");
  return (
    <AppShell me={me} org={org} unread={unread.ok ? unread.data.unread_count : null}>
      {children}
    </AppShell>
  );
}
