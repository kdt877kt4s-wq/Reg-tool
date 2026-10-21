"use client";

import { useState, useTransition } from "react";
import { buttonClass } from "@/components/ui/button-styles";
import { FormMessage, inputClass } from "@/components/ui/field";
import type { Assessment, CitationInfo, TaxonomyTerm } from "@/lib/api";
import type { FormState } from "@/lib/api-core";
import { DECISION_LABELS, PRIORITY_LABELS, pageLabel } from "@/lib/labels";
import { saveAssessmentAction, type EditorPayload } from "./analysis-actions";

type ActionRow = EditorPayload["actions"][number] & { key: string; origin: "ai" | "person" };

const DECISION_HINTS = {
  applicable: "It governs something we do. Accepted actions become our work.",
  not_applicable: "It does not govern anything we do. Record why.",
  monitor: "Not now, but it could. Watch for changes or final rules.",
} as const;

export function AssessmentEditor({
  documentId,
  assessment,
  functions,
  canApprove,
  citations,
}: {
  documentId: number;
  assessment: Assessment;
  functions: TaxonomyTerm[];
  canApprove: boolean;
  citations: Record<string, CitationInfo>;
}) {
  const [decision, setDecision] = useState<Assessment["decision"]>(assessment.decision);
  const [rationale, setRationale] = useState(assessment.rationale ?? "");
  const [affected, setAffected] = useState<string[]>(assessment.affected_functions);
  const [actions, setActions] = useState<ActionRow[]>(() =>
    assessment.actions.map((a) => ({ ...a, key: `a${a.id}` })),
  );
  const [state, setState] = useState<FormState>({});
  const [pending, startTransition] = useTransition();

  const errors = state.fieldErrors ?? {};
  const undecided = actions.filter((a) => a.state === "proposed").length;

  function update(key: string, patch: Partial<ActionRow>) {
    setActions((rows) => rows.map((r) => (r.key === key ? { ...r, ...patch } : r)));
  }

  function submit(approve: boolean) {
    const payload: EditorPayload = {
      decision,
      rationale,
      affected_functions: affected,
      actions: actions.map(({ id, title, description, priority, state: s, citation_passage_ids }) => ({
        ...(id ? { id } : {}),
        title,
        description: description || null,
        priority,
        state: s,
        citation_passage_ids,
      })),
    };
    startTransition(async () => {
      const result = await saveAssessmentAction(documentId, payload, approve);
      setState(result);
      if (result.assessment) {
        // Adopt server ids for newly added actions so the next save updates them.
        setActions(result.assessment.actions.map((a) => ({ ...a, key: `a${a.id}` })));
      }
    });
  }

  return (
    <div className="mt-6 space-y-8 rounded-md border border-rule p-5">
      <div>
        <h3 className="text-base font-semibold">Your assessment</h3>
        <p className="mt-1 text-sm text-muted">
          The AI analysis below is input. This is the organization&apos;s decision, and it is recorded in the audit log.
        </p>
      </div>
      <FormMessage state={state.ok === false && Object.keys(errors).length > 0 ? { ok: false, message: state.message } : state} />

      <fieldset>
        <legend className="text-sm font-semibold">Decision</legend>
        {errors.decision && <p className="mt-1 text-xs text-caution">{errors.decision}</p>}
        <div className="mt-2 grid gap-2 sm:grid-cols-3">
          {(Object.keys(DECISION_LABELS) as (keyof typeof DECISION_LABELS)[]).map((d) => (
            <label
              key={d}
              className={`cursor-pointer rounded-md border p-3 text-sm ${decision === d ? "border-signal bg-signal/5" : "border-rule"}`}
            >
              <input type="radio" name="decision" value={d} checked={decision === d} onChange={() => setDecision(d)} className="sr-only" />
              <span className="block font-medium">{DECISION_LABELS[d]}</span>
              <span className="mt-1 block text-xs text-muted">{DECISION_HINTS[d]}</span>
            </label>
          ))}
        </div>
      </fieldset>

      <div>
        <label htmlFor="rationale" className="text-sm font-semibold">
          Rationale
        </label>
        <p id="rationale-hint" className="text-xs text-muted">
          Why this decision. Someone reading the audit trail next year should understand it.
        </p>
        <textarea
          id="rationale"
          rows={4}
          value={rationale}
          onChange={(e) => setRationale(e.target.value)}
          aria-describedby="rationale-hint"
          aria-invalid={Boolean(errors.rationale)}
          className={`${inputClass} mt-2`}
        />
        {errors.rationale && <p className="mt-1 text-xs text-caution">{errors.rationale}</p>}
      </div>

      <fieldset>
        <legend className="text-sm font-semibold">Affected functions</legend>
        <div className="mt-2 grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-2">
          {functions.map((f) => (
            <label key={f.code} className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={affected.includes(f.code)}
                onChange={(e) => setAffected((list) => (e.target.checked ? [...list, f.code] : list.filter((c) => c !== f.code)))}
                className="size-4 accent-[var(--color-signal)]"
              />
              {f.label}
            </label>
          ))}
        </div>
      </fieldset>

      <fieldset>
        <legend className="text-sm font-semibold">Actions</legend>
        <p className="text-xs text-muted">
          Accept the ones your organization will do; dismiss the rest. Accepted actions become assignable tasks.
        </p>
        {errors.actions && <p className="mt-1 text-xs text-caution">{errors.actions}</p>}
        <ul className="mt-3 space-y-4">
          {actions.map((a, i) => (
            <li key={a.key} className={`rounded-md border p-4 ${a.state === "dismissed" ? "border-rule opacity-70" : "border-rule"}`}>
              <div className="flex flex-wrap items-center gap-2 text-xs text-muted">
                <span>{a.origin === "ai" ? "Suggested by the AI analysis" : "Added by a person"}</span>
                {a.citation_passage_ids.map((pid) => {
                  const c = citations[String(pid)];
                  return (
                    <a key={pid} href={`?around=${pid}#passage-${pid}`} className="rounded-sm bg-ink/5 px-1.5 py-0.5 text-ink hover:underline">
                      {c ? [c.heading_path?.split(" > ").at(-1), pageLabel(c.page_start, c.page_end)].filter(Boolean).join(", ") : `passage ${pid}`}
                    </a>
                  );
                })}
              </div>
              <label className="sr-only" htmlFor={`title-${a.key}`}>
                Action title
              </label>
              <input
                id={`title-${a.key}`}
                value={a.title}
                onChange={(e) => update(a.key, { title: e.target.value })}
                className={`${inputClass} mt-2 font-medium`}
                aria-invalid={Boolean(errors[`actions.${i}.title`])}
              />
              {errors[`actions.${i}.title`] && <p className="mt-1 text-xs text-caution">{errors[`actions.${i}.title`]}</p>}
              <label className="sr-only" htmlFor={`desc-${a.key}`}>
                Description
              </label>
              <textarea
                id={`desc-${a.key}`}
                rows={2}
                value={a.description ?? ""}
                onChange={(e) => update(a.key, { description: e.target.value })}
                placeholder="What exactly needs to happen"
                className={`${inputClass} mt-2`}
              />
              <div className="mt-3 flex flex-wrap items-center gap-4 text-sm">
                <label className="flex items-center gap-2">
                  <span className="text-muted">Priority</span>
                  <select
                    value={a.priority}
                    onChange={(e) => update(a.key, { priority: e.target.value as ActionRow["priority"] })}
                    className={`${inputClass} w-auto py-1`}
                  >
                    {(Object.keys(PRIORITY_LABELS) as ActionRow["priority"][]).map((p) => (
                      <option key={p} value={p}>
                        {PRIORITY_LABELS[p]}
                      </option>
                    ))}
                  </select>
                </label>
                <div role="radiogroup" aria-label={`Decision on ${a.title}`} className="flex gap-1">
                  {(["accepted", "dismissed", "proposed"] as const).map((s) => (
                    <button
                      key={s}
                      type="button"
                      role="radio"
                      aria-checked={a.state === s}
                      onClick={() => update(a.key, { state: s })}
                      className={`rounded-md border px-3 py-1 text-xs ${a.state === s ? "border-ink bg-ink text-white" : "border-rule"}`}
                    >
                      {s === "accepted" ? "Accept" : s === "dismissed" ? "Dismiss" : "Undecided"}
                    </button>
                  ))}
                </div>
                {a.origin === "person" && (
                  <button type="button" onClick={() => setActions((rows) => rows.filter((r) => r.key !== a.key))} className="text-xs text-caution hover:underline">
                    Delete
                  </button>
                )}
              </div>
            </li>
          ))}
        </ul>
        <button
          type="button"
          onClick={() =>
            setActions((rows) => [
              ...rows,
              { key: `new${Date.now()}`, title: "", description: "", priority: "medium", state: "accepted", citation_passage_ids: [], origin: "person" },
            ])
          }
          className={`${buttonClass("secondary")} mt-3`}
        >
          Add an action
        </button>
      </fieldset>

      <div className="flex flex-wrap items-center gap-3 border-t border-rule pt-5">
        <button type="button" disabled={pending} onClick={() => submit(false)} className={buttonClass("secondary")}>
          {pending ? "Saving" : "Save draft"}
        </button>
        {canApprove ? (
          <button type="button" disabled={pending} onClick={() => submit(true)} className={buttonClass("primary")}>
            Save and approve
          </button>
        ) : (
          <p className="text-xs text-muted">A compliance officer approves assessments.</p>
        )}
        {canApprove && undecided > 0 && (
          <p className="text-xs text-muted">
            {undecided} {undecided === 1 ? "action needs" : "actions need"} a decision before approval.
          </p>
        )}
      </div>
    </div>
  );
}
