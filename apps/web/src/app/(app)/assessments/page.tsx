import Link from "next/link";
import { apiRequest, type QueueRow } from "@/lib/api";
import { formatDate, formatDateTime } from "@/lib/format";
import { APPLICABILITY_LABELS, CONFIDENCE_LABELS, DECISION_LABELS } from "@/lib/labels";

export const metadata = { title: "Impact assessments" };

const GROUPS: { key: QueueRow["group"]; title: string; empty: string }[] = [
  { key: "awaiting_review", title: "Awaiting review", empty: "Nothing is waiting for a decision." },
  { key: "in_progress", title: "Analysis in progress", empty: "No analyses are running." },
  { key: "failed", title: "Analysis did not finish", empty: "" },
  { key: "approved", title: "Approved", empty: "No assessments have been approved yet." },
];

export default async function AssessmentsPage() {
  const res = await apiRequest<{ data: QueueRow[] }>("/assessments");

  return (
    <div className="max-w-5xl">
      <h1 className="text-2xl font-semibold tracking-tight">Impact assessments</h1>
      <p className="mt-2 max-w-[66ch] text-muted">
        Publications your organization has analyzed or assessed. Start one from any publication in{" "}
        <Link href="/regulations" className="text-signal underline-offset-4 hover:underline">
          Regulatory intelligence
        </Link>
        .
      </p>

      {!res.ok ? (
        <p role="alert" className="mt-8 border-l-2 border-caution pl-3 text-sm">
          {res.error.message}
        </p>
      ) : (
        GROUPS.map((g) => {
          const rows = res.data.data.filter((r) => r.group === g.key);
          if (rows.length === 0 && !g.empty) return null;
          return (
            <section key={g.key} aria-labelledby={`group-${g.key}`} className="mt-10">
              <h2 id={`group-${g.key}`} className="text-lg font-semibold">
                {g.title} <span className="font-normal text-muted">({rows.length})</span>
              </h2>
              {rows.length === 0 ? (
                <p className="mt-3 text-sm text-muted">{g.empty}</p>
              ) : (
                <ol className="mt-3 divide-y divide-rule border-y border-rule">
                  {rows.map((r) => (
                    <li key={r.document.id} className="py-4">
                      <p className="text-xs text-muted">
                        {r.document.type_label}, published {formatDate(r.document.publication_date)}, document {r.document.document_number}
                      </p>
                      <h3 className="mt-1 font-serif text-base leading-snug">
                        <Link href={`/regulations/${r.document.id}#impact-heading`} className="hover:text-signal hover:underline hover:underline-offset-4">
                          {r.document.title}
                        </Link>
                      </h3>
                      <ul className="mt-2 flex flex-wrap gap-2 text-xs">
                        {r.assessment?.status === "approved" && r.assessment.decision && (
                          <li className="rounded-sm border border-ink/30 px-2 py-0.5 font-medium">
                            {DECISION_LABELS[r.assessment.decision]}, approved {formatDateTime(r.assessment.approved_at)}
                          </li>
                        )}
                        {r.assessment?.source_changed && (
                          <li className="rounded-sm border border-caution/50 px-2 py-0.5 text-caution">Source changed since approval</li>
                        )}
                        {r.assessment?.status === "draft" && <li className="rounded-sm border border-rule px-2 py-0.5">Draft assessment</li>}
                        {r.analysis?.applicability && (
                          <li className="rounded-sm border border-rule px-2 py-0.5">
                            AI: {APPLICABILITY_LABELS[r.analysis.applicability as keyof typeof APPLICABILITY_LABELS] ?? r.analysis.applicability}
                            {r.analysis.confidence && `, ${CONFIDENCE_LABELS[r.analysis.confidence].toLowerCase()}`}
                          </li>
                        )}
                        {r.document.effective_date && (
                          <li className="px-1 py-0.5 text-muted">Effective {formatDate(r.document.effective_date)}</li>
                        )}
                      </ul>
                    </li>
                  ))}
                </ol>
              )}
            </section>
          );
        })
      )}
    </div>
  );
}
