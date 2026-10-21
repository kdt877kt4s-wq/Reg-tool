import Link from "next/link";
import { buttonClass } from "@/components/ui/button-styles";
import { SourceStatusLine } from "@/components/source-status";
import { WeeklyChart } from "@/components/weekly-chart";
import { api, apiRequest, type DashboardSummary, type DocumentList, type Health, type Profile, type QueueRow, type SourceStatus } from "@/lib/api";
import { formatDate, isoDateDaysAgo } from "@/lib/format";
import { DEADLINE_KIND_LABELS } from "@/lib/labels";
import { DocumentRow } from "./regulations/document-row";
import { can, requireOrganization } from "@/lib/auth";

export const dynamic = "force-dynamic";

export default async function OverviewPage({ searchParams }: { searchParams: Promise<{ profile?: string }> }) {
  const since = isoDateDaysAgo(30);
  const [{ me, org }, health, profile, params, sources, recent, queue, summary] = await Promise.all([
    requireOrganization(),
    api.health(),
    apiRequest<{ profile: Profile }>("/organization/profile"),
    searchParams,
    apiRequest<{ data: SourceStatus[] }>("/sources"),
    apiRequest<DocumentList>(`/regulatory-documents?relevance=profile&from=${since}&limit=5`),
    apiRequest<{ data: QueueRow[] }>("/assessments"),
    apiRequest<{ data: DashboardSummary }>("/dashboard/summary"),
  ]);
  const d = summary.ok ? summary.data.data : null;
  const awaiting = queue.ok ? queue.data.data.filter((r) => r.group === "awaiting_review").length : 0;
  const changed = queue.ok ? queue.data.data.filter((r) => r.assessment?.source_changed).length : 0;

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

      {d && (
        <section aria-labelledby="work-heading" className="mt-8">
          <h2 id="work-heading" className="sr-only">
            Your work
          </h2>
          <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-md border border-rule bg-rule sm:grid-cols-4">
            <Stat label="Assigned to you" value={d.my_tasks.active} href="/tasks?view=mine" />
            <Stat label="Yours overdue" value={d.my_tasks.overdue} href="/tasks?view=mine&filter=overdue" tone={d.my_tasks.overdue > 0 ? "caution" : undefined} />
            <Stat label="Waiting for your review" value={d.to_review} href="/tasks?view=visible&filter=submitted" />
            <Stat
              label={d.tasks.scope === "organization" ? "Organization tasks overdue" : "Overdue you can see"}
              value={d.tasks.overdue}
              href="/tasks?view=visible&filter=overdue"
              tone={d.tasks.overdue > 0 ? "caution" : undefined}
            />
          </dl>
          {d.my_tasks.returned > 0 && (
            <p className="mt-3 border-l-2 border-caution pl-3 text-sm">
              {d.my_tasks.returned} of your tasks {d.my_tasks.returned === 1 ? "was" : "were"} returned with a reason.{" "}
              <Link href="/tasks?view=mine" className="text-signal underline-offset-4 hover:underline">
                Open
              </Link>
            </p>
          )}
          {d.tasks.scope === "organization" && d.tasks.unassigned > 0 && (
            <p className="mt-3 text-sm">
              {d.tasks.unassigned} active {d.tasks.unassigned === 1 ? "task has" : "tasks have"} no owner.{" "}
              <Link href="/tasks?view=visible&filter=unassigned" className="text-signal underline-offset-4 hover:underline">
                Assign
              </Link>
            </p>
          )}
        </section>
      )}

      {d && (
        <section aria-labelledby="deadlines-heading" className="mt-10">
          <h2 id="deadlines-heading" className="text-lg font-semibold">
            Next 30 days
          </h2>
          {d.deadlines.length === 0 ? (
            <p className="mt-3 text-sm text-muted">No published dates or task due dates in the next 30 days.</p>
          ) : (
            <ol className="mt-3 divide-y divide-rule border-y border-rule">
              {d.deadlines.map((e) => (
                <li key={e.id} className="grid gap-1 py-3 text-sm sm:grid-cols-[7rem_9rem_1fr] sm:gap-4">
                  <span className="font-medium tabular-nums">{formatDate(e.date)}</span>
                  <span>
                    <span className={e.deadline_type === "official" ? "rounded-sm bg-ink px-1.5 py-0.5 text-[11px] font-medium text-white" : "rounded-sm border border-ink/40 px-1.5 py-0.5 text-[11px] font-medium"}>
                      {DEADLINE_KIND_LABELS[e.kind]}
                    </span>
                  </span>
                  <Link href={e.task ? `/tasks/${e.task.id}` : `/regulations/${e.document!.id}`} className="min-w-0 truncate hover:text-signal hover:underline hover:underline-offset-4">
                    {e.title}
                  </Link>
                </li>
              ))}
            </ol>
          )}
          <p className="mt-3 text-sm">
            <Link href="/calendar" className="text-signal underline-offset-4 hover:underline">
              Open the regulatory calendar
            </Link>
          </p>
        </section>
      )}

      {(awaiting > 0 || changed > 0) && (
        <section aria-labelledby="review-heading" className="mt-8 rounded-md border border-rule p-5">
          <h2 id="review-heading" className="text-base font-semibold">
            Waiting for review
          </h2>
          <ul className="mt-2 space-y-1 text-sm">
            {awaiting > 0 && (
              <li>
                {awaiting} {awaiting === 1 ? "publication has" : "publications have"} an analysis or draft waiting for a decision.
              </li>
            )}
            {changed > 0 && (
              <li className="text-caution">
                {changed} approved {changed === 1 ? "assessment needs" : "assessments need"} another look because the source changed.
              </li>
            )}
          </ul>
          <Link href="/assessments" className="mt-3 inline-block text-sm text-signal underline-offset-4 hover:underline">
            Open impact assessments
          </Link>
        </section>
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
        {d && d.weekly_publications.some((w) => w.total > 0) && (
          <>
            <h3 className="mt-6 text-sm font-semibold">Federal Register publications retrieved per week, last 12 weeks</h3>
            <WeeklyChart weeks={d.weekly_publications} showMatches={d.profile_configured} />
          </>
        )}
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

function Stat({ label, value, href, tone }: { label: string; value: number; href: string; tone?: "caution" }) {
  return (
    <div className="bg-paper p-4">
      <dt className="text-xs text-muted">{label}</dt>
      <dd className={tone === "caution" ? "mt-1 text-2xl font-semibold tabular-nums text-caution" : "mt-1 text-2xl font-semibold tabular-nums"}>
        <Link href={href} className="hover:underline hover:underline-offset-4">
          {value}
        </Link>
      </dd>
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
