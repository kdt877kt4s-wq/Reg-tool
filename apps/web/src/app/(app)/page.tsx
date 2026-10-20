import Link from "next/link";
import { buttonClass } from "@/components/ui/button";
import { api, apiRequest, type Health, type Profile } from "@/lib/api";
import { can, requireOrganization } from "@/lib/auth";

export const dynamic = "force-dynamic";

export default async function OverviewPage({ searchParams }: { searchParams: Promise<{ profile?: string }> }) {
  const [{ me, org }, health, profile, params] = await Promise.all([
    requireOrganization(),
    api.health(),
    apiRequest<{ profile: Profile }>("/organization/profile"),
    searchParams,
  ]);

  const firstName = me.user.name.split(" ")[0];
  const p = profile.ok ? profile.data.profile : null;

  return (
    <div className="max-w-3xl">
      <h1 className="text-2xl font-semibold tracking-tight">Welcome, {firstName}</h1>
      <p className="mt-2 text-muted">{org.organization.name}</p>

      {params.profile === "saved" && (
        <p role="status" className="mt-6 border-l-2 border-signal pl-3 text-sm">
          Regulatory profile saved. RuleRadar will use it to screen new regulations once monitoring starts.
        </p>
      )}

      <section aria-labelledby="profile-heading" className="mt-10">
        <h2 id="profile-heading" className="text-lg font-semibold">
          Regulatory profile
        </h2>
        {p?.completed ? (
          <>
            <dl className="mt-4 divide-y divide-rule border-y border-rule text-sm">
              <ProfileRow label="Organization types" values={p.org_types.length} />
              <ProfileRow label="States" values={p.states.length} detail={p.states.join(", ")} />
              <ProfileRow label="Federal programs" values={p.programs.length} />
              <ProfileRow label="Operational functions" values={p.functions.length} />
            </dl>
            <p className="mt-3 text-sm">
              <Link href="/settings/profile" className="text-signal underline-offset-4 hover:underline">
                {can(org, "profile.edit") ? "Review or change the profile" : "View the profile"}
              </Link>
            </p>
          </>
        ) : can(org, "profile.edit") ? (
          <div className="mt-4 max-w-[62ch]">
            <p className="text-sm text-muted">
              Describe what your organization does and where. RuleRadar uses this to judge which regulations may
              apply; it never assumes every rule applies to everyone.
            </p>
            <Link href="/settings/profile?welcome=1" className={`${buttonClass("primary")} mt-4`}>
              Complete the regulatory profile
            </Link>
          </div>
        ) : (
          <p className="mt-4 text-sm text-muted">
            Your compliance officer has not completed the regulatory profile yet.
          </p>
        )}
      </section>

      <section aria-labelledby="next-heading" className="mt-12">
        <h2 id="next-heading" className="text-lg font-semibold">
          Regulatory monitoring
        </h2>
        <p className="mt-2 max-w-[62ch] text-sm text-muted">
          Monitoring of the Federal Register for CMS, HHS, OCR, OIG and ONC publications arrives in the next
          release. Until then, no regulatory updates are shown here.
        </p>
      </section>

      <section aria-labelledby="system-heading" className="mt-12">
        <h2 id="system-heading" className="text-lg font-semibold">
          System status
        </h2>
        {health.ok ? (
          <HealthRows health={health.data} />
        ) : (
          <p role="alert" className="mt-4 border-l-2 border-caution pl-4 text-sm">
            {health.error.message}
          </p>
        )}
      </section>
    </div>
  );
}

function ProfileRow({ label, values, detail }: { label: string; values: number; detail?: string }) {
  return (
    <div className="flex justify-between gap-6 py-3">
      <dt className="text-muted">{label}</dt>
      <dd className="text-right font-medium">{detail && values <= 6 ? detail : values === 0 ? "None" : `${values} selected`}</dd>
    </div>
  );
}

function HealthRows({ health }: { health: Health }) {
  const rows: [string, string, boolean][] = [
    ["API", `${health.status === "ok" ? "Running" : "Degraded"}, version ${health.version}`, health.status === "ok"],
    ...Object.entries(health.checks).map(([name, check]): [string, string, boolean] => [
      name.charAt(0).toUpperCase() + name.slice(1),
      check.status === "ok" ? "Connected" : (check.detail ?? "Failed"),
      check.status === "ok",
    ]),
  ];

  return (
    <dl className="mt-4 divide-y divide-rule border-y border-rule">
      {rows.map(([label, value, good]) => (
        <div key={label} className="flex justify-between gap-6 py-3 text-sm">
          <dt className="text-muted">{label}</dt>
          <dd className={good ? "font-medium" : "font-medium text-caution"}>{value}</dd>
        </div>
      ))}
    </dl>
  );
}
