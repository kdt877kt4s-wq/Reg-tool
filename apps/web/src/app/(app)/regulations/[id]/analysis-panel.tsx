import Link from "next/link";
import type { Analysis, AnalysisResponse, Assessment, CitationInfo, Claim, TaxonomyTerm } from "@/lib/api";
import { formatDate, formatDateTime } from "@/lib/format";
import { APPLICABILITY_LABELS, BASIS_LABELS, CONFIDENCE_LABELS, DECISION_LABELS, PRIORITY_LABELS, pageLabel } from "@/lib/labels";
import { AssessmentEditor } from "./assessment-editor";
import { AutoRefresh, ReopenForm, RunAnalysisForm, StartAssessmentForm } from "./analysis-forms";

/**
 * AI analysis (draft, labelled, cited) and the human assessment built on it.
 * The two are kept visually separate: the analysis is input, the assessment
 * is the organization's decision.
 */
export function AnalysisPanel({
  documentId,
  textAvailable,
  analysis,
  assessment,
  canEdit,
  canApprove,
  functions,
}: {
  documentId: number;
  textAvailable: boolean;
  analysis: AnalysisResponse;
  assessment: Assessment | null;
  canEdit: boolean;
  canApprove: boolean;
  functions: TaxonomyTerm[];
}) {
  const a = analysis.data;
  const running = a?.status === "queued" || a?.status === "running";

  return (
    <section aria-labelledby="impact-heading" className="mt-12 border-t border-rule pt-10">
      <h2 id="impact-heading" className="text-lg font-semibold">
        Impact for your organization
      </h2>

      {assessment && <AssessmentSummary assessment={assessment} documentId={documentId} canApprove={canApprove} />}

      {canEdit && assessment?.status === "draft" && (
        <AssessmentEditor documentId={documentId} assessment={assessment} functions={functions} canApprove={canApprove} citations={a?.citations ?? {}} />
      )}

      <div className="mt-8 rounded-md border border-dashed border-ink/25 p-5">
        <div className="flex flex-wrap items-baseline justify-between gap-3">
          <h3 className="text-base font-semibold">AI analysis</h3>
          <p className="text-xs text-muted">Not legal advice. A draft for your review.</p>
        </div>

        {!a && (
          <div className="mt-3 text-sm">
            {!analysis.configured ? (
              <p className="text-muted">AI analysis is not set up for this installation yet. You can still assess this document yourself.</p>
            ) : !textAvailable ? (
              <p className="text-muted">The full text is not available, so this document cannot be analyzed automatically.</p>
            ) : analysis.can_run ? (
              <RunAnalysisForm documentId={documentId} />
            ) : (
              <p className="text-muted">No analysis yet. Your compliance officer can request one.</p>
            )}
          </div>
        )}

        {running && (
          <>
            <p role="status" className="mt-3 text-sm">
              Analysis in progress, started {formatDateTime(a.created_at)}. This page updates when it finishes.
            </p>
            <AutoRefresh seconds={5} />
          </>
        )}

        {a?.status === "failed" && (
          <div className="mt-3 space-y-3 text-sm">
            <p role="alert" className="border-l-2 border-caution pl-3">{a.error ?? "The analysis did not finish."}</p>
            {analysis.can_run && analysis.configured && <RunAnalysisForm documentId={documentId} rerun label="Try the analysis again" />}
          </div>
        )}

        {a?.status === "completed" && a.output && <AnalysisBody analysis={a} documentId={documentId} />}

        {a?.status === "completed" && analysis.can_run && analysis.configured && (
          <div className="mt-6 border-t border-rule pt-4">
            <RunAnalysisForm documentId={documentId} rerun label="Run a fresh analysis" variant="quiet" />
          </div>
        )}
      </div>

      {canEdit && !assessment && (
        <div className="mt-6">
          <StartAssessmentForm documentId={documentId} fromAnalysis={a?.status === "completed"} />
        </div>
      )}
    </section>
  );
}

function AnalysisBody({ analysis, documentId }: { analysis: Analysis; documentId: number }) {
  const o = analysis.output!;
  const cite = analysis.citations;

  return (
    <div className="mt-4 space-y-7 text-sm">
      <div className="flex flex-wrap gap-2 text-xs">
        <span className="rounded-sm border border-ink/20 px-2 py-0.5 font-medium">{APPLICABILITY_LABELS[o.applicability.level]}</span>
        {analysis.confidence && (
          <span className={`rounded-sm border px-2 py-0.5 ${analysis.confidence === "low" ? "border-caution/50 text-caution" : "border-ink/20"}`}>
            {CONFIDENCE_LABELS[analysis.confidence]}
          </span>
        )}
        <span className="px-1 py-0.5 text-muted">
          {analysis.passages_sent} passages reviewed, {formatDateTime(analysis.completed_at)}
          {analysis.model && `, model ${analysis.model}`}
        </span>
        {analysis.model === "e2e-scripted" && (
          <span className="rounded-sm border border-caution/50 px-2 py-0.5 font-medium text-caution">Test output, not a real analysis</span>
        )}
      </div>
      {o.confidence_reason && <p className="text-muted">{o.confidence_reason}</p>}

      {analysis.removed.length > 0 && (
        <details className="border-l-2 border-caution pl-3">
          <summary className="cursor-pointer">
            {analysis.removed.length} {analysis.removed.length === 1 ? "statement was" : "statements were"} removed because
            RuleRadar could not verify them against the document.
          </summary>
          <ul className="mt-2 space-y-1.5 text-muted">
            {analysis.removed.map((r, i) => (
              <li key={i}>
                <span className="text-ink">{r.text || "(empty)"}</span> {r.reason}
              </li>
            ))}
          </ul>
        </details>
      )}

      <ClaimList title="Why it may apply to you" claims={o.applicability.reasons} cite={cite} documentId={documentId} />
      {o.applicability.missing_facts.length > 0 && (
        <div>
          <h4 className="font-semibold">What your profile does not say</h4>
          <ul className="mt-2 list-disc space-y-1 pl-5">
            {o.applicability.missing_facts.map((f) => (
              <li key={f}>{f}</li>
            ))}
          </ul>
        </div>
      )}
      <ClaimList title="What changed" claims={o.what_changed} cite={cite} documentId={documentId} />
      <ClaimList title="Why it matters" claims={o.why_it_matters} cite={cite} documentId={documentId} />
      <ClaimList title="Operational impact" claims={o.operational_impacts} cite={cite} documentId={documentId} />
      <ClaimList title="Policies and procedures to review" claims={o.policies_to_review} cite={cite} documentId={documentId} />

      {o.deadlines.length > 0 && (
        <div>
          <h4 className="font-semibold">Dates stated in the document</h4>
          <ul className="mt-2 space-y-1.5">
            {o.deadlines.map((d) => (
              <li key={`${d.date}-${d.label}`}>
                <span className="font-medium">{formatDate(d.date)}</span> {d.label}{" "}
                <Citations ids={d.citations} cite={cite} documentId={documentId} />
              </li>
            ))}
          </ul>
        </div>
      )}

      {o.suggested_actions.length > 0 && (
        <div>
          <h4 className="font-semibold">Suggested actions</h4>
          <p className="text-xs text-muted">Proposals only. They become tasks after a compliance officer accepts them.</p>
          <ul className="mt-2 space-y-3">
            {o.suggested_actions.map((s) => (
              <li key={s.title}>
                <p className="font-medium">
                  {s.title} <span className="font-normal text-muted">({PRIORITY_LABELS[s.priority]} priority)</span>
                </p>
                {s.description && <p className="text-muted">{s.description}</p>}
                <Citations ids={s.citations} cite={cite} documentId={documentId} />
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

function ClaimList({ title, claims, cite, documentId }: { title: string; claims: Claim[]; cite: Record<string, CitationInfo>; documentId: number }) {
  if (claims.length === 0) return null;
  return (
    <div>
      <h4 className="font-semibold">{title}</h4>
      <ul className="mt-2 space-y-3">
        {claims.map((c, i) => (
          <li key={i} className={c.basis === "regulatory_text" ? "border-l-2 border-ink/40 pl-3" : "border-l-2 border-signal/40 pl-3"}>
            <p className="text-xs text-muted">{BASIS_LABELS[c.basis]}</p>
            <p>{c.text}</p>
            {c.quote && <blockquote className="mt-1 font-serif text-[0.95rem] italic">&ldquo;{c.quote}&rdquo;</blockquote>}
            <Citations ids={c.citations} cite={cite} documentId={documentId} />
          </li>
        ))}
      </ul>
    </div>
  );
}

export function Citations({ ids, cite, documentId }: { ids: number[]; cite: Record<string, CitationInfo>; documentId: number }) {
  if (ids.length === 0) return null;
  return (
    <span className="mt-1 flex flex-wrap gap-1.5">
      {ids.map((id) => {
        const c = cite[String(id)];
        const label = c ? [c.heading_path?.split(" > ").at(-1), pageLabel(c.page_start, c.page_end)].filter(Boolean).join(", ") : `passage ${id}`;
        return (
          <Link
            key={id}
            href={`/regulations/${documentId}?around=${id}#passage-${id}`}
            title={c?.excerpt}
            className="rounded-sm bg-ink/5 px-1.5 py-0.5 text-xs text-ink underline-offset-2 hover:bg-signal/10 hover:underline"
          >
            {label || `passage ${id}`}
          </Link>
        );
      })}
    </span>
  );
}

function AssessmentSummary({ assessment, documentId, canApprove }: { assessment: Assessment; documentId: number; canApprove: boolean }) {
  if (assessment.status !== "approved") {
    return (
      <p className="mt-3 text-sm text-muted">
        Assessment in draft{assessment.updated_by ? `, last edited by ${assessment.updated_by}` : ""}. Not yet approved.
      </p>
    );
  }
  const accepted = assessment.actions.filter((x) => x.state === "accepted");

  return (
    <div className="mt-4 rounded-md border border-rule p-5 text-sm">
      {assessment.source_changed && (
        <p role="alert" className="mb-4 border-l-2 border-caution pl-3">
          The Federal Register record changed after this was approved. Review the changes above and reopen the assessment
          if the decision needs to change.
        </p>
      )}
      <p className="text-xs text-muted">
        Approved by {assessment.approved_by} {formatDateTime(assessment.approved_at)}
      </p>
      <p className="mt-1 text-lg font-semibold">{assessment.decision ? DECISION_LABELS[assessment.decision] : "No decision"}</p>
      {assessment.rationale && <p className="mt-2 whitespace-pre-line">{assessment.rationale}</p>}
      {accepted.length > 0 && (
        <>
          <h3 className="mt-4 font-semibold">Accepted actions</h3>
          <ul className="mt-1 list-disc space-y-1 pl-5">
            {accepted.map((x) => (
              <li key={x.id}>
                {x.title} <span className="text-muted">({PRIORITY_LABELS[x.priority]})</span>
              </li>
            ))}
          </ul>
          <p className="mt-2 text-sm">
            <Link href={`/tasks?view=visible&document=${documentId}`} className="text-signal underline-offset-4 hover:underline">
              Open the tasks created from these actions
            </Link>
          </p>
        </>
      )}
      {canApprove && (
        <div className="mt-4">
          <ReopenForm documentId={documentId} />
        </div>
      )}
      <p className="mt-4 text-xs">
        <Link href="/assessments" className="text-signal underline-offset-4 hover:underline">
          All assessments
        </Link>
      </p>
    </div>
  );
}
