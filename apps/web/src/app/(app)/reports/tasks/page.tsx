import Link from "next/link";
import { ReportHeader, SummaryStats } from "@/components/report-header";
import { apiRequest, type TaskReport } from "@/lib/api";
import { formatDate } from "@/lib/format";
import { TASK_STATUS_LABELS } from "@/lib/labels";

export const metadata = { title: "Task status report" };

const STATUSES = { all: "All tasks", active: "Active tasks", overdue: "Overdue tasks", done: "Completed tasks" } as const;

export default async function TaskReportPage({ searchParams }: { searchParams: Promise<{ status?: string }> }) {
  const { status: raw } = await searchParams;
  const status = raw && raw in STATUSES ? (raw as keyof typeof STATUSES) : "all";
  const res = await apiRequest<TaskReport>(`/reports/tasks?status=${status}`);
  if (!res.ok) {
    return (
      <p role="alert" className="border-l-2 border-caution pl-3 text-sm">
        {res.error.message}
      </p>
    );
  }
  const { meta, summary, data } = res.data;

  return (
    <div className="max-w-6xl">
      <ReportHeader title="Task status" subtitle={STATUSES[status]} meta={meta} csvHref={`/api/reports/tasks?status=${status}`} />
      <SummaryStats
        items={[
          ["Tasks", summary.tasks],
          ["Active", summary.active],
          ["Overdue", summary.overdue, true],
          ["Unassigned", summary.unassigned, true],
          ["Done", summary.done],
          ["Done without evidence", summary.done_without_evidence, true],
        ]}
      />
      {data.length === 0 ? (
        <p className="mt-8 text-sm text-muted">No tasks.</p>
      ) : (
        <div className="mt-8 overflow-x-auto">
          <table className="w-full min-w-[56rem] text-left text-sm">
            <thead className="border-b border-ink/30 text-xs text-muted">
              <tr>
                <th className="py-2 pr-4 font-medium">Task</th>
                <th className="py-2 pr-4 font-medium">Owner</th>
                <th className="py-2 pr-4 font-medium">Due</th>
                <th className="py-2 pr-4 font-medium">Status</th>
                <th className="py-2 pr-4 font-medium">Evidence</th>
                <th className="py-2 font-medium">Source</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-rule align-top">
              {data.map((t) => (
                <tr key={t.task_id} className="break-inside-avoid">
                  <td className="py-3 pr-4">
                    <Link href={`/tasks/${t.task_id}`} className="font-medium hover:text-signal hover:underline">
                      {t.title}
                    </Link>
                    <span className="block text-xs text-muted">{t.priority} priority</span>
                  </td>
                  <td className="py-3 pr-4 text-xs">
                    {t.owner ?? <span className="text-caution">Unassigned</span>}
                    {t.department && <span className="block text-muted">{t.department}</span>}
                  </td>
                  <td className="py-3 pr-4 text-xs">
                    {t.due_date ? (
                      <>
                        <span className={t.overdue ? "font-medium text-caution" : "tabular-nums"}>{formatDate(t.due_date)}</span>
                        <span className="block text-muted">{t.deadline_type === "official" ? `Official: ${t.deadline_source ?? ""}` : "Internal target"}</span>
                      </>
                    ) : (
                      <span className="text-muted">None</span>
                    )}
                  </td>
                  <td className="py-3 pr-4 text-xs">
                    {TASK_STATUS_LABELS[t.status]}
                    {t.status === "done" && t.accepted_by && <span className="block text-muted">Accepted by {t.accepted_by}, {formatDate(t.completed_at)}</span>}
                    {t.times_returned > 0 && <span className="block text-muted">Returned {t.times_returned}×</span>}
                  </td>
                  <td className={t.status === "done" && t.evidence_items === 0 ? "py-3 pr-4 text-xs text-caution" : "py-3 pr-4 text-xs tabular-nums"}>
                    {t.evidence_items}
                  </td>
                  <td className="py-3 text-xs">{t.source_document ? `FR ${t.source_document}` : <span className="text-muted">Added by hand</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
