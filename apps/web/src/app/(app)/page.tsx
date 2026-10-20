import Link from "next/link";
import { buttonClass } from "@/components/ui/button";
import { SourceStatusLine } from "@/components/source-status";
import { api, apiRequest, type DocumentList, type Health, type Profile, type SourceStatus } from "@/lib/api";
import { isoDateDaysAgo } from "@/lib/format";
import { DocumentRow } from "./regulations/document-row";
import { can, requireOrganization } from "@/lib/auth";

export const dynamic = "force-dynamic";

export default async function OverviewPage({ searchParams }: { searchParams: Promise<{ profile?: string }> }) {
  const since = isoDateDaysAgo(30);
  const [{ me, org }, health, profile, params, sources, recent] = await Promise.all([
    requireOrganization(),
    api.health(),
    apiRequest<{ profile: Profile }>("/organization/profile"),
    searchParams,
    apiRequest<{ data: SourceStatus[] }>("/sources"),
    apiRequest<DocumentList>(`/regulatory-documents?relevance=profile&from=${since}&limit=5`),
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

      <section aria-labelledby="monitoring-heading" className="mt-12">
        <h2 id="monitoring-heading" className="text-lg font-semibold">
          New for your profile in the last 30 days
        </h2>
        <div className="mt-2">{sources.ok && <SourceStatusLine sources={sources.data.data} />}</div>
        {recent.ok && recent.data.meta.profile_filter_unavailable ? (
          <p className="mt-4 max-w-[62ch] text-sm text-muted">
            Select federal programs in the regulatory profile and RuleRadar will list publications that amend the
            regulations linked to them.
          </p>
        ) : recent.ok && recent.data.data.length > 0 ? (
          <>
            <ol className="mt-4 divide-y divide-rule border-y border-rule">
              {recent.data.data.map((doc) => (
                <DocumentRow key={doc.id} doc={doc} />
              ))}
            </ol>
            <p className="mt-3 text-sm">
              <Link href={`/regulations?relevance=profile&from=${since}`} className="text-signal underline-offset-4 hover:underline">
                {recent.data.meta.total > 5 ? `See all ${recent.data.meta.total}` : "Open in Regulatory intelligence"}
              </Link>
            </p>
          </>
        ) : recent.ok ? (
          <p className="mt-4 max-w-[62ch] text-sm text-muted">
            No publications in the last 30 days amend a CFR part linked to your profile.{" "}
            <Link href="/regulations" className="text-signal underline-offset-4 hover:underline">
              Browse everything retrieved
            </Link>
          </p>
        ) : (
          <p role="alert" className="mt-4 text-sm">{recent.error.message}</p>
        )}
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
