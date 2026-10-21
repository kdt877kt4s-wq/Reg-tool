import Link from "next/link";
import { ReportHeader, SummaryStats } from "@/components/report-header";
import { apiRequest, type ActivityReport } from "@/lib/api";
import { formatDate } from "@/lib/format";
import { APPLICABILITY_LABELS, DECISION_LABELS } from "@/lib/labels";

export const metadata = { title: "Regulatory activity report" };

type Params = { from?: string; to?: string; scope?: string };
const isDate = (v?: string) => Boolean(v && /^\d{4}-\d{2}-\d{2}$/.test(v));

export default async function ActivityReportPage({ searchParams }: { searchParams: Promise<Params> }) {
  const p = await searchParams;
  if (!isDate(p.from) || !isDate(p.to)) {
    return (
      <p role="alert" className="text-sm">
        Choose a date range on the{" "}
        <Link href="/reports" className="text-signal underline-offset-4 hover:underline">
          Reports
        </Link>{" "}
        page.
      </p>
    );
  }
  const query = new URLSearchParams({ from: p.from!, to: p.to!, scope: p.scope === "all" ? "all" : "profile" });
  const res = await apiRequest<ActivityReport>(`/reports/regulatory-activity?${query.toString()}`);
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
      <ReportHeader
        title="Regulatory activity"
        subtitle={`Federal Register publications ${formatDate(meta.from)} to ${formatDate(meta.to)}, ${meta.scope === "profile" ? "matching the regulatory profile" : "everything retrieved"}`}
        meta={meta}
        csvHref={`/api/reports/regulatory-activity?${query.toString()}`}
      />
      {meta.scope === "profile" && meta.profile_cfr_parts.length === 0 && (
        <p className="mt-6 border-l-2 border-caution pl-3 text-sm">The profile has no federal programs selected, so nothing can match it.</p>
      )}
      <SummaryStats
        items={[
          ["Publications", summary.publications],
          ["Match profile", summary.profile_matches],
          ["Analyzed", summary.analyzed],
          ["Approved", summary.approved],
          ["Apply to us", summary.applies],
          ["Changed since approval", summary.source_changed, true],
        ]}
      />
      {meta.truncated && <p className="mt-3 text-sm text-caution">Showing the first 2,000 publications. Narrow the range for the rest.</p>}

      {data.length === 0 ? (
        <p className="mt-8 text-sm text-muted">No publications in this range.</p>
      ) : (
        <div className="mt-8 overflow-x-auto">
          <table className="w-full min-w-[56rem] text-left text-sm">
            <thead className="border-b border-ink/30 text-xs text-muted">
              <tr>
                <th className="py-2 pr-4 font-medium">Published</th>
                <th className="py-2 pr-4 font-medium">Publication</th>
                <th className="py-2 pr-4 font-medium">Dates</th>
                <th className="py-2 pr-4 font-medium">AI screening</th>
                <th className="py-2 pr-4 font-medium">Our decision</th>
                <th className="py-2 font-medium">Tasks</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-rule align-top">
              {data.map((r) => (
                <tr key={r.document_id} className="break-inside-avoid">
                  <td className="py-3 pr-4 tabular-nums">{formatDate(r.publication_date)}</td>
                  <td className="py-3 pr-4">
                    <Link href={`/regulations/${r.document_id}`} className="font-serif hover:text-signal hover:underline">
                      {r.title}
                    </Link>
                    <span className="mt-0.5 block text-xs text-muted">
                      {r.document_number}
                      {r.citation && `, ${r.citation}`} · {r.agencies}
                      {r.profile_match && " · Matches profile"}
                    </span>
                  </td>
                  <td className="py-3 pr-4 text-xs">
                    {r.effective_date && <span className="block">Effective {formatDate(r.effective_date)}</span>}
                    {r.comments_close_date && <span className="block">Comments close {formatDate(r.comments_close_date)}</span>}
                    {!r.effective_date && !r.comments_close_date && <span className="text-muted">None published</span>}
                  </td>
                  <td className="py-3 pr-4 text-xs">
                    {r.ai_applicability ? (
                      <>
                        AI: {APPLICABILITY_LABELS[r.ai_applicability]}
                        {r.ai_confidence && <span className="block text-muted">{r.ai_confidence} confidence</span>}
                      </>
                    ) : (
                      <span className="text-muted">Not analyzed</span>
                    )}
                  </td>
                  <td className="py-3 pr-4 text-xs">
                    {r.assessment_status === "approved" && r.decision ? (
                      <>
                        <span className="font-medium">{DECISION_LABELS[r.decision]}</span>
                        <span className="block text-muted">
                          Approved by {r.approved_by}, {formatDate(r.approved_at)}
                        </span>
                        {r.source_changed_since_approval && <span className="block text-caution">Source changed since approval</span>}
                      </>
                    ) : r.assessment_status === "draft" ? (
                      "Draft"
                    ) : (
                      <span className="text-muted">No assessment</span>
                    )}
                  </td>
                  <td className="py-3 text-xs tabular-nums">{r.tasks === 0 ? "None" : `${r.tasks_done} of ${r.tasks} done`}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
