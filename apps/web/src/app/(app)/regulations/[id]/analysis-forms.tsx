"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { SubmitButton } from "@/components/ui/button";
import { FormMessage } from "@/components/ui/field";
import { useFormAction } from "@/lib/use-form-action";
import { reopenAssessmentAction, runAnalysisAction, startAssessmentAction } from "./analysis-actions";

export function RunAnalysisForm({
  documentId,
  rerun = false,
  label = "Analyze impact for my organization",
  variant = "primary",
}: {
  documentId: number;
  rerun?: boolean;
  label?: string;
  variant?: "primary" | "quiet";
}) {
  const [state, onSubmit, pending] = useFormAction(runAnalysisAction, {});
  return (
    <form onSubmit={onSubmit} className="space-y-2">
      <input type="hidden" name="document_id" value={documentId} />
      {rerun && <input type="hidden" name="rerun" value="1" />}
      {!rerun && (
        <p className="text-muted">
          Claude reads the passages most relevant to your regulatory profile and drafts an impact analysis. Every statement
          cites the document, and anything RuleRadar cannot verify is removed.
        </p>
      )}
      <SubmitButton pending={pending} pendingLabel="Starting" variant={variant}>
        {label}
      </SubmitButton>
      <FormMessage state={state} />
    </form>
  );
}

export function StartAssessmentForm({ documentId, fromAnalysis }: { documentId: number; fromAnalysis: boolean }) {
  const [state, onSubmit, pending] = useFormAction(startAssessmentAction, {});
  return (
    <form onSubmit={onSubmit} className="space-y-2">
      <input type="hidden" name="document_id" value={documentId} />
      <SubmitButton pending={pending} pendingLabel="Starting" variant={fromAnalysis ? "primary" : "secondary"}>
        {fromAnalysis ? "Start an assessment from this analysis" : "Assess this document yourself"}
      </SubmitButton>
      <FormMessage state={state} />
    </form>
  );
}

export function ReopenForm({ documentId }: { documentId: number }) {
  const [state, onSubmit, pending] = useFormAction(reopenAssessmentAction, {});
  return (
    <form onSubmit={onSubmit}>
      <input type="hidden" name="document_id" value={documentId} />
      <SubmitButton pending={pending} pendingLabel="Reopening" variant="secondary">
        Reopen to change the decision
      </SubmitButton>
      <FormMessage state={state} />
    </form>
  );
}

/** Refreshes server data while an analysis runs. Stops once the page shows a result. */
export function AutoRefresh({ seconds }: { seconds: number }) {
  const router = useRouter();
  useEffect(() => {
    const id = window.setInterval(() => router.refresh(), seconds * 1000);
    return () => window.clearInterval(id);
  }, [router, seconds]);
  return null;
}
