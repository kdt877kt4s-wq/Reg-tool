import Link from "next/link";
import { NAV_ITEMS, availabilityNote } from "@/lib/navigation";

export function AppShell({
  children,
  activeHref,
}: {
  children: React.ReactNode;
  activeHref: string;
}) {
  return (
    <div className="min-h-dvh md:grid md:grid-cols-[17rem_1fr]">
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:bg-paper focus:px-3 focus:py-2"
      >
        Skip to content
      </a>

      <aside className="bg-ink text-rail-text md:sticky md:top-0 md:h-dvh">
        <div className="flex items-baseline justify-between px-6 pb-6 pt-7">
          <Link href="/" className="text-lg font-semibold tracking-tight text-white">
            RuleRadar
          </Link>
        </div>

        <nav aria-label="Primary" className="px-3 pb-6">
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
              const active = item.href === activeHref;
              return (
                <li key={item.href}>
                  <Link
                    href={item.href}
                    aria-current={active ? "page" : undefined}
                    className={
                      active
                        ? "block rounded-md border-l-2 border-signal bg-white/8 px-3 py-2 text-sm font-medium text-white"
                        : "block rounded-md px-3 py-2 text-sm text-rail-text hover:bg-white/5 hover:text-white"
                    }
                  >
                    {item.label}
                  </Link>
                </li>
              );
            })}
          </ul>
        </nav>
      </aside>

      <main id="main" className="px-6 py-10 md:px-14 md:py-14">
        {children}
      </main>
    </div>
  );
}
