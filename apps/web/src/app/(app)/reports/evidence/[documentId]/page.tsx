import Link from "next/link";
import { notFound } from "next/navigation";
import { ReportHeader } from "@/components/report-header";
import { apiRequest, type CitationInfo, type Claim, type EvidencePack } from "@/lib/api";
import { formatDate, formatDateTime } from "@/lib/format";
import { APPLICABILITY_LABELS, BASIS_LABELS, CONFIDENCE_LABELS, DECISION_LABELS, PRIORITY_LABELS, TASK_STATUS_LABELS, eventLabel, fileSize, pageLabel } from "@/lib/labels";

export const metadata = { title: "Evidence pack" };

export default async function EvidencePackPage({ params }: { params: Promise<{ documentId: string }> }) {
  const { documentId } = await params;
  if (!/^\d+$/.test(documentId)) notFound();
  const res = await apiRequest<EvidencePack>(`/reports/evidence-pack/${documentId}`);
  if (!res.ok) {
    if (res.error.status === 404) notFound();
    return (
      <p role="alert" className="border-l-2 border-caution pl-3 text-sm">
        {res.error.message}
      </p>
    );
  }
  const { meta, document: d, assessment: a, analysis, tasks, audit_trail, audit_chain } = res.data;
  return (
    <div className="max-w-4xl">
      <ReportHeader title="Evidence pack" subtitle={`${d.title} (FR ${d.document_number})`} meta={meta} />

      <section className="mt-8 break-inside-avoid">
        <h2 className="text-lg font-semibold">1. The publication</h2>
        <dl className="mt-3 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-muted">Document</dt>
            <dd>
              {d.document_number}
              {d.citation && `, ${d.citation}`} ({d.type})
            </dd>
          </div>
          <div>
            <dt className="text-muted">Published</dt>
            <dd>{formatDate(d.publication_date)}</dd>
          </div>
          <div>
            <dt className="text-muted">Effective</dt>
            <dd>{formatDate(d.effective_date) ?? "No date published"}</dd>
          </div>
          <div>
            <dt className="text-muted">Comments close</dt>
            <dd>{formatDate(d.comments_close_date) ?? "No date published"}</dd>
          </div>
          {d.html_url && (
            <div className="sm:col-span-2">
              <dt className="text-muted">Source</dt>
              <dd className="break-all">{d.html_url}</dd>
            </div>
          )}
        </dl>
      </section>

      <section className="mt-10 break-inside-avoid">
        <h2 className="text-lg font-semibold">2. Our decision</h2>
        <p className="mt-3 text-sm">
          <span className="font-semibold">{a.decision ? DECISION_LABELS[a.decision] : "No decision"}</span>
          {a.status === "approved" ? `, approved by ${a.approved_by} on ${formatDateTime(a.approved_at)}` : " (draft, not approved)"}
          {`, made on version ${a.document_version} of the publication`}
          {a.source_changed && <span className="block text-caution">The publication has changed since this decision (now version {d.current_version}).</span>}
        </p>
        {a.rationale && <p className="mt-2 max-w-[66ch] whitespace-pre-line text-sm">{a.rationale}</p>}
        <h3 className="mt-4 text-sm font-semibold">Actions</h3>
        <ul className="mt-1 space-y-1 text-sm">
          {a.actions.map((x) => (
            <li key={x.id}>
              {x.state === "accepted" ? "Accepted" : x.state === "dismissed" ? "Dismissed" : "Undecided"}: {x.title}{" "}
              <span className="text-muted">
                ({PRIORITY_LABELS[x.priority]}, {x.origin === "ai" ? "suggested by AI" : "added by a person"})
              </span>
            </li>
          ))}
        </ul>
      </section>

      <section className="mt-10">
        <h2 className="text-lg font-semibold">3. AI analysis used</h2>
        {!analysis || !analysis.output ? (
          <p className="mt-3 text-sm text-muted">The assessment was made without an AI analysis.</p>
        ) : (
          <>
            <p className="mt-3 text-sm text-muted">
              AI-generated screening, not legal advice. Model {analysis.model}, prompt {analysis.prompt_version}, completed{" "}
              {formatDateTime(analysis.completed_at)}. {APPLICABILITY_LABELS[analysis.output.applicability.level]}
              {analysis.confidence && `, ${CONFIDENCE_LABELS[analysis.confidence].toLowerCase()}`}. Statements marked
              &quot;From the regulation&quot; are tied to the cited passages; &quot;AI interpretation&quot; is the model&apos;s reading.
            </p>
            <ClaimList title="What changed" claims={analysis.output.what_changed} citations={analysis.citations} />
            <ClaimList title="Why it matters" claims={analysis.output.why_it_matters} citations={analysis.citations} />
            <ClaimList title="Applicability" claims={analysis.output.applicability.reasons} citations={analysis.citations} />
            {analysis.removed.length > 0 && (
              <p className="mt-4 text-xs text-muted">
                {analysis.removed.length} {analysis.removed.length === 1 ? "statement was" : "statements were"} removed by RuleRadar&apos;s
                citation checks before the analysis was shown.
              </p>
            )}
          </>
        )}
      </section>

      <section className="mt-10">
        <h2 className="text-lg font-semibold">4. Tasks and evidence</h2>
        {tasks.length === 0 ? (
          <p className="mt-3 text-sm text-muted">No tasks.</p>
        ) : (
          tasks.map((t) => (
            <div key={t.id} className="mt-4 break-inside-avoid rounded-md border border-rule p-4 text-sm">
              <p className="font-medium">
                <Link href={`/tasks/${t.id}`} className="hover:text-signal hover:underline">
                  {t.title}
                </Link>
              </p>
              <p className="mt-0.5 text-xs text-muted">
                {TASK_STATUS_LABELS[t.status]} · {t.owner ?? "Unassigned"}
                {t.department && `, ${t.department}`}
                {t.due_date && ` · Due ${formatDate(t.due_date)} (${t.due_date_type === "official" ? `official: ${t.due_date_basis}` : "internal target"})`}
                {t.completed_at && ` · Completed ${formatDateTime(t.completed_at)}`}
              </p>
              {t.evidence.length > 0 ? (
                <ul className="mt-2 space-y-1">
                  {t.evidence.map((e, i) => (
                    <li key={i}>
                      {e.file ? (
                        <>
                          {e.file.filename} <span className="text-xs text-muted">({fileSize(e.file.size)}, SHA-256 {e.file.sha256}, {e.file.scan_status.replace("_", " ")})</span>
                        </>
                      ) : (
                        "Note"
                      )}
                      {e.note && <span className="block text-muted">{e.note}</span>}
                      <span className="block text-xs text-muted">
                        {e.submitted_by}, {formatDateTime(e.created_at)}
                      </span>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="mt-2 text-xs text-muted">No evidence.</p>
              )}
              <p className="mt-2 text-xs text-muted">History: {t.history.map((h) => `${eventLabel(h.type)} (${h.actor ?? "system"}, ${formatDateTime(h.created_at)})`).join(" → ")}</p>
            </div>
          ))
        )}
      </section>

      <section className="mt-10">
        <h2 className="text-lg font-semibold">5. Audit trail</h2>
        <p className={audit_chain.valid ? "mt-3 text-sm" : "mt-3 text-sm font-medium text-caution"}>
          {audit_chain.valid
            ? `The organization's audit log is intact: all ${audit_chain.checked} entries match their hash chain.`
            : `The audit log hash chain breaks at entry ${audit_chain.broken_at_id}. Investigate before relying on this record.`}
        </p>
        <table className="mt-3 w-full text-left text-xs">
          <thead className="border-b border-ink/30 text-muted">
            <tr>
              <th className="py-1.5 pr-3 font-medium">When</th>
              <th className="py-1.5 pr-3 font-medium">Action</th>
              <th className="py-1.5 pr-3 font-medium">By</th>
              <th className="py-1.5 font-medium">Entry hash</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-rule">
            {audit_trail.map((e) => (
              <tr key={e.id}>
                <td className="py-1.5 pr-3 whitespace-nowrap">{formatDateTime(e.created_at)}</td>
                <td className="py-1.5 pr-3">{e.action}</td>
                <td className="py-1.5 pr-3">{e.actor ?? "System"}</td>
                <td className="py-1.5 font-mono">{e.hash.slice(0, 16)}…</td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
    </div>
  );
}

function ClaimList({ title, claims, citations }: { title: string; claims: Claim[]; citations: Record<string, CitationInfo> }) {
  if (claims.length === 0) return null;
  const cite = (ids: number[]) =>
    ids
      .map((id) => citations[String(id)])
      .filter((c): c is CitationInfo => Boolean(c))
      .map((c) => [c.heading_path, pageLabel(c.page_start, c.page_end)].filter(Boolean).join(", "))
      .join("; ");
  return (
    <div className="mt-4 break-inside-avoid">
      <h4 className="text-sm font-semibold">{title}</h4>
      <ul className="mt-1 space-y-1.5 text-sm">
        {claims.map((c, i) => (
          <li key={i}>
            <span className="mr-1.5 rounded-sm border border-rule px-1 text-[11px] text-muted">{BASIS_LABELS[c.basis]}</span>
            {c.text}
            {c.citations.length > 0 && <span className="block text-xs text-muted">Cited: {cite(c.citations)}</span>}
          </li>
        ))}
      </ul>
    </div>
  );
}
