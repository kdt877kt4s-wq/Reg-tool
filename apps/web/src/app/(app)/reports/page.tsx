import Link from "next/link";
import { buttonClass } from "@/components/ui/button";
import { inputClass } from "@/components/ui/field";
import { apiRequest, type QueueRow } from "@/lib/api";
import { can, requireOrganization } from "@/lib/auth";
import { isoDateDaysAgo, todayIso } from "@/lib/format";

export const metadata = { title: "Reports" };

export default async function ReportsPage() {
  const { org } = await requireOrganization();
  if (!can(org, "reports.view")) {
    return (
      <div className="max-w-3xl">
        <h1 className="text-2xl font-semibold tracking-tight">Reports</h1>
        <p className="mt-4 text-sm text-muted">Reports are available to administrators, compliance officers and auditors.</p>
      </div>
    );
  }
  const queue = await apiRequest<{ data: QueueRow[] }>("/assessments");
  const assessed = queue.ok ? queue.data.data.filter((r) => r.assessment) : [];

  return (
    <div className="max-w-4xl">
      <h1 className="text-2xl font-semibold tracking-tight">Reports</h1>
      <p className="mt-2 max-w-[66ch] text-muted">
        Built from the records stored in RuleRadar at the moment you open them. Print any report or save it as a PDF;
        the activity and task reports also download as CSV. Every report you generate is recorded in the audit log.
      </p>

      <section aria-labelledby="activity-heading" className="mt-10 rounded-md border border-rule p-5">
        <h2 id="activity-heading" className="text-lg font-semibold">
          Regulatory activity
        </h2>
        <p className="mt-1 text-sm text-muted">
          Federal Register publications in a date range, with the AI screening result and your organization&apos;s decisions.
        </p>
        <form action="/reports/activity" className="mt-4 flex flex-wrap items-end gap-4">
          <label className="space-y-1 text-sm">
            <span className="block font-medium">From</span>
            <input type="date" name="from" defaultValue={isoDateDaysAgo(90)} required className={inputClass} />
          </label>
          <label className="space-y-1 text-sm">
            <span className="block font-medium">To</span>
            <input type="date" name="to" defaultValue={todayIso()} required className={inputClass} />
          </label>
          <label className="space-y-1 text-sm">
            <span className="block font-medium">Publications</span>
            <select name="scope" defaultValue="profile" className={inputClass}>
              <option value="profile">Matching our profile</option>
              <option value="all">Everything retrieved</option>
            </select>
          </label>
          <button type="submit" className={buttonClass("primary")}>
            Open report
          </button>
        </form>
      </section>

      <section aria-labelledby="tasks-heading" className="mt-6 rounded-md border border-rule p-5">
        <h2 id="tasks-heading" className="text-lg font-semibold">
          Task status
        </h2>
        <p className="mt-1 text-sm text-muted">Every compliance task with its owner, deadline type, evidence and review outcome.</p>
        <form action="/reports/tasks" className="mt-4 flex flex-wrap items-end gap-4">
          <label className="space-y-1 text-sm">
            <span className="block font-medium">Tasks</span>
            <select name="status" defaultValue="all" className={inputClass}>
              <option value="all">All</option>
              <option value="active">Active</option>
              <option value="overdue">Overdue</option>
              <option value="done">Done</option>
            </select>
          </label>
          <button type="submit" className={buttonClass("primary")}>
            Open report
          </button>
        </form>
      </section>

      <section aria-labelledby="pack-heading" className="mt-6 rounded-md border border-rule p-5">
        <h2 id="pack-heading" className="text-lg font-semibold">
          Evidence pack
        </h2>
        <p className="mt-1 text-sm text-muted">
          For one publication: your decision and who approved it, the cited AI analysis, the resulting tasks with their
          evidence, the related audit trail and a check that the audit log is intact.
        </p>
        {assessed.length === 0 ? (
          <p className="mt-4 text-sm text-muted">No publication has an assessment yet.</p>
        ) : (
          <ul className="mt-4 divide-y divide-rule border-y border-rule text-sm">
            {assessed.map((r) => (
              <li key={r.document.id} className="flex flex-wrap items-baseline justify-between gap-3 py-3">
                <span className="min-w-0 font-serif">{r.document.title}</span>
                <Link href={`/reports/evidence/${r.document.id}`} className="shrink-0 text-signal underline-offset-4 hover:underline">
                  Open evidence pack
                </Link>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
