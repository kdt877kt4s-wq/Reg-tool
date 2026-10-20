import Link from "next/link";

export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-dvh md:grid md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
      <aside className="bg-ink px-8 py-10 text-rail-text md:px-12 md:py-14">
        <Link href="/" className="text-lg font-semibold tracking-tight text-white">
          RuleRadar
        </Link>
        <p className="mt-6 max-w-[30ch] font-serif text-xl leading-snug text-white md:mt-24 md:text-2xl">
          Know what changed. Understand what matters. Stay ahead of compliance.
        </p>
        <p className="mt-4 max-w-[38ch] text-sm text-rail-text/80">
          Regulatory intelligence for health plans, health systems and healthcare technology teams.
        </p>
      </aside>
      <main id="main" className="flex items-start px-6 py-10 md:items-center md:px-16">
        <div className="w-full max-w-md">{children}</div>
      </main>
    </div>
  );
}
