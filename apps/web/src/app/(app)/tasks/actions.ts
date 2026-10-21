"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { apiRequest, type TaskDetail } from "@/lib/api";
import { formError, type FormState } from "@/lib/api-core";

function optionalNumber(value: FormDataEntryValue | null): number | null {
  return value && /^\d+$/.test(String(value)) ? Number(value) : null;
}

function taskFields(form: FormData) {
  const due = String(form.get("due_date") ?? "") || null;
  return {
    title: String(form.get("title") ?? ""),
    description: String(form.get("description") ?? "") || null,
    priority: String(form.get("priority") ?? "medium"),
    owner_id: optionalNumber(form.get("owner_id")),
    department_id: optionalNumber(form.get("department_id")),
    due_date: due,
    due_date_type: due ? String(form.get("due_date_type") ?? "internal") : null,
    due_date_basis: due ? String(form.get("due_date_basis") ?? "") || null : null,
  };
}

export async function createTaskAction(_: FormState, form: FormData): Promise<FormState> {
  const document = optionalNumber(form.get("document_id"));
  const res = await apiRequest<{ data: TaskDetail }>("/tasks", {
    method: "POST",
    body: { ...taskFields(form), ...(document ? { document_id: document } : {}) },
  });
  if (!res.ok) return formError(res.error);
  revalidatePath("/tasks");
  redirect(`/tasks/${res.data.data.id}?created=1`);
}

export async function updateTaskAction(_: FormState, form: FormData): Promise<FormState> {
  const id = Number(form.get("id"));
  const res = await apiRequest(`/tasks/${id}`, { method: "PATCH", body: taskFields(form) });
  if (!res.ok) return formError(res.error);
  revalidatePath(`/tasks/${id}`);
  revalidatePath("/tasks");
  return { ok: true, message: "Task saved." };
}

const MESSAGES: Record<string, string> = {
  start: "Marked as started.",
  submit: "Submitted for review.",
  accept: "Accepted as done.",
  return: "Returned to the owner.",
  cancel: "Task cancelled.",
};

export async function transitionTaskAction(_: FormState, form: FormData): Promise<FormState> {
  const id = Number(form.get("id"));
  const action = String(form.get("action"));
  if (!(action in MESSAGES)) return { ok: false, message: "Unknown action." };
  const reason = form.get("reason");
  const res = await apiRequest(`/tasks/${id}/${action}`, {
    method: "POST",
    body: reason === null ? undefined : { reason: String(reason) },
  });
  if (!res.ok) return formError(res.error);
  revalidatePath(`/tasks/${id}`);
  revalidatePath("/tasks");
  revalidatePath("/", "layout");
  return { ok: true, message: MESSAGES[action] };
}
