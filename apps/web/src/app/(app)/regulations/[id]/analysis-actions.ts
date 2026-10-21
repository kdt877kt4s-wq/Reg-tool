"use server";

import { revalidatePath } from "next/cache";
import { apiRequest, type Assessment } from "@/lib/api";
import { formError, type FormState } from "@/lib/api-core";

export async function runAnalysisAction(_: FormState, form: FormData): Promise<FormState> {
  const id = Number(form.get("document_id"));
  const rerun = form.get("rerun") === "1" ? "?rerun=1" : "";
  const res = await apiRequest(`/regulatory-documents/${id}/analysis${rerun}`, { method: "POST" });
  if (!res.ok) return formError(res.error);
  revalidatePath(`/regulations/${id}`);
  return { ok: true, message: "Analysis started. This usually takes under a minute." };
}

export async function startAssessmentAction(_: FormState, form: FormData): Promise<FormState> {
  const id = Number(form.get("document_id"));
  const res = await apiRequest(`/regulatory-documents/${id}/assessment`, { method: "POST" });
  if (!res.ok) return formError(res.error);
  revalidatePath(`/regulations/${id}`);
  revalidatePath("/assessments");
  return { ok: true };
}

export type EditorPayload = {
  decision: Assessment["decision"];
  rationale: string;
  affected_functions: string[];
  actions: {
    id?: number;
    title: string;
    description: string | null;
    priority: "high" | "medium" | "low";
    state: "proposed" | "accepted" | "dismissed";
    citation_passage_ids: number[];
  }[];
};

/** Save the draft, and approve it when asked. */
export async function saveAssessmentAction(
  documentId: number,
  payload: EditorPayload,
  approve: boolean,
): Promise<FormState & { assessment?: Assessment }> {
  const saved = await apiRequest<{ data: Assessment }>(`/regulatory-documents/${documentId}/assessment`, {
    method: "PUT",
    body: payload,
  });
  if (!saved.ok) return formError(saved.error);

  let assessment = saved.data.data;
  if (approve) {
    const approved = await apiRequest<{ data: Assessment }>(`/regulatory-documents/${documentId}/assessment/approve`, { method: "POST" });
    if (!approved.ok) return { ...formError(approved.error), assessment };
    assessment = approved.data.data;
  }

  revalidatePath(`/regulations/${documentId}`);
  revalidatePath("/assessments");
  return { ok: true, message: approve ? "Assessment approved." : "Draft saved.", assessment };
}

export async function reopenAssessmentAction(_: FormState, form: FormData): Promise<FormState> {
  const id = Number(form.get("document_id"));
  const res = await apiRequest(`/regulatory-documents/${id}/assessment/reopen`, { method: "POST" });
  if (!res.ok) return formError(res.error);
  revalidatePath(`/regulations/${id}`);
  revalidatePath("/assessments");
  return { ok: true };
}
