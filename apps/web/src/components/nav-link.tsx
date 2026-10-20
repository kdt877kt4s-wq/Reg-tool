"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";

export function NavLink({ href, children }: { href: string; children: React.ReactNode }) {
  const pathname = usePathname();
  const active = href === "/" ? pathname === "/" : pathname === href || pathname.startsWith(`${href}/`);

  return (
    <Link
      href={href}
      aria-current={active ? "page" : undefined}
      className="block rounded-md px-3 py-2 text-sm text-rail-text hover:bg-white/5 hover:text-white aria-[current=page]:border-l-2 aria-[current=page]:border-signal aria-[current=page]:bg-white/8 aria-[current=page]:font-medium aria-[current=page]:text-white"
    >
      {children}
    </Link>
  );
}
