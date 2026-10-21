"use client";

import { useState } from "react";
import { SubmitButton } from "@/components/ui/button";
import { buttonClass } from "@/components/ui/button-styles";
import { Field, FormMessage, inputClass } from "@/components/ui/field";
import type { TaskAction } from "@/lib/api";
import type { FormState } from "@/lib/api-core";
import { useFormAction } from "@/lib/use-form-action";
import { transitionTaskAction } from "../actions";

const NEEDS_REASON = { return: "Why is it going back?", cancel: "Why is it being cancelled?" } as const;

/** The workflow buttons the API says this person may use right now. */
export function TaskControls({ taskId, allowed, hasEvidence }: { taskId: number; allowed: TaskAction[]; hasEvidence: boolean }) {
  const [state, onSubmit, pending] = useFormAction<FormState>(transitionTaskAction, {});
  const [asking, setAsking] = useState<keyof typeof NEEDS_REASON | null>(null);

  const simple = (["start", "submit", "accept"] as const).filter((a) => allowed.includes(a));
  const withReason = (["return", "cancel"] as const).filter((a) => allowed.includes(a));
  if (simple.length === 0 && withReason.length === 0) return null;

  return (
    <div className="space-y-3">
      {simple.length > 0 && (
        <form onSubmit={onSubmit} className="flex flex-wrap items-center gap-3">
          <input type="hidden" name="id" value={taskId} />
          {simple.includes("accept") && (
            <SubmitButton name="action" value="accept" pending={pending}>
              Accept as done
            </SubmitButton>
          )}
          {simple.includes("submit") && (
            <SubmitButton name="action" value="submit" pending={pending} variant={simple.includes("accept") ? "secondary" : "primary"}>
              Submit for review
            </SubmitButton>
          )}
          {simple.includes("start") && (
            <SubmitButton name="action" value="start" pending={pending} variant="secondary">
              Mark as started
            </SubmitButton>
          )}
          {simple.includes("submit") && !hasEvidence && (
            <span className="text-xs text-muted">Add evidence below before submitting.</span>
          )}
        </form>
      )}

      {withReason.length > 0 && !asking && (
        <div className="flex flex-wrap gap-3">
          {withReason.map((a) => (
            <button key={a} type="button" onClick={() => setAsking(a)} className={buttonClass(a === "cancel" ? "danger" : "secondary")}>
              {a === "return" ? "Return to owner" : "Cancel task"}
            </button>
          ))}
        </div>
      )}

      {asking && (
        <form onSubmit={onSubmit} className="max-w-xl space-y-3 rounded-md border border-rule p-4">
          <input type="hidden" name="id" value={taskId} />
          <input type="hidden" name="action" value={asking} />
          <Field id="reason" label={NEEDS_REASON[asking]} error={state.fieldErrors?.reason}>
            {(p) => <textarea {...p} name="reason" rows={3} required minLength={5} maxLength={2000} className={inputClass} />}
          </Field>
          <div className="flex gap-3">
            <SubmitButton pending={pending} variant={asking === "cancel" ? "danger" : "primary"}>
              {asking === "return" ? "Return to owner" : "Cancel task"}
            </SubmitButton>
            <button type="button" onClick={() => setAsking(null)} className={buttonClass("quiet")}>
              Keep it as is
            </button>
          </div>
        </form>
      )}

      <FormMessage state={state} />
    </div>
  );
}
