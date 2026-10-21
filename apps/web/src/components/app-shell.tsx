import Link from "next/link";
import { logoutAction } from "@/app/(auth)/actions";
import { switchOrganizationAction } from "@/app/(app)/actions";
import { NavLink } from "@/components/nav-link";
import type { Me, OrganizationInfo } from "@/lib/api";
import { NAV_ITEMS, availabilityNote } from "@/lib/navigation";

export function AppShell({
  children,
  me,
  org,
  unread,
}: {
  children: React.ReactNode;
  me: Me;
  org: OrganizationInfo;
  /** Unread notifications; null when the count could not be loaded. */
  unread: number | null;
}) {
  return (
    <div className="min-h-dvh md:grid md:grid-cols-[17rem_1fr] print:block">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:bg-paper focus:px-3 focus:py-2"
      >
        Skip to content
      </a>

      <aside className="flex flex-col bg-ink text-rail-text md:sticky md:top-0 md:h-dvh print:hidden">
        <div className="px-6 pb-5 pt-7">
          <Link href="/" className="text-lg font-semibold tracking-tight text-white">
            RuleRadar
          </Link>
          <OrganizationPicker me={me} currentId={org.organization.id} currentName={org.organization.name} />
          <Link
            href="/notifications"
            className="mt-4 flex items-center justify-between rounded-md border border-white/15 px-3 py-1.5 text-sm text-white hover:bg-white/10"
          >
            <span>Notifications</span>
            {unread !== null && unread > 0 ? (
              <span className="rounded-full bg-white px-2 text-xs font-semibold tabular-nums text-ink">
                {unread > 99 ? "99+" : unread}
                <span className="sr-only"> unread</span>
              </span>
            ) : (
              <span className="text-xs text-rail-text/60">None new</span>
            )}
          </Link>
        </div>

        <nav aria-label="Primary" className="flex-1 overflow-y-auto px-3 pb-6">
          <NavList />
        </nav>

        <div className="border-t border-white/10 px-6 py-4 text-sm">
          <p className="truncate text-white">{me.user.name}</p>
          <p className="truncate text-xs text-rail-text/70">{org.membership.role_label}</p>
          <form action={logoutAction} className="mt-2">
            <button type="submit" className="text-xs text-rail-text/80 underline-offset-4 hover:text-white hover:underline">
              Sign out
            </button>
          </form>
        </div>
      </aside>

      <main id="main" className="px-6 py-10 md:px-14 md:py-14 print:p-0">
        {children}
      </main>
    </div>
  );
}

function OrganizationPicker({ me, currentId, currentName }: { me: Me; currentId: number; currentName: string }) {
  if (me.memberships.length < 2) {
    return <p className="mt-2 truncate text-sm text-rail-text/80">{currentName}</p>;
  }
  return (
    <form action={switchOrganizationAction} className="mt-3">
      <label htmlFor="organization-picker" className="sr-only">
        Organization
      </label>
      <div className="flex gap-2">
        <select
          id="organization-picker"
          name="organization_id"
          defaultValue={currentId}
          className="min-w-0 flex-1 rounded-md border border-white/15 bg-white/5 px-2 py-1.5 text-sm text-white"
        >
          {me.memberships.map((m) => (
            <option key={m.organization_id} value={m.organization_id} className="text-ink">
              {m.organization_name}
            </option>
          ))}
        </select>
        <button type="submit" className="rounded-md border border-white/15 px-2 text-xs text-white hover:bg-white/10">
          Switch
        </button>
      </div>
    </form>
  );
}

/** Built sections are links; sections not built yet are plain text with when they arrive. */
function NavList() {
  return (
    <ul className="space-y-0.5">
      {NAV_ITEMS.map((item) => {
        const note = availabilityNote(item);
        if (note) {
          return (
            <li key={item.href} className="px-3 py-2">
              <span className="block text-sm text-rail-text/60">{item.label}</span>
              <span className="block text-xs text-rail-text/45">{note}</span>
            </li>
          );
        }
        return (
          <li key={item.href}>
            <NavLink href={item.href}>{item.label}</NavLink>
          </li>
        );
      })}
    </ul>
  );
}
