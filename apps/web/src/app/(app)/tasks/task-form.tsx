"use client";

import { useState } from "react";
import { SubmitButton } from "@/components/ui/button";
import { Field, FormMessage, inputClass } from "@/components/ui/field";
import type { Assignees, TaskDetail } from "@/lib/api";
import type { FormState } from "@/lib/api-core";
import { useFormAction } from "@/lib/use-form-action";
import { createTaskAction, updateTaskAction } from "./actions";

export function TaskForm({
  assignees,
  task,
  documentId,
}: {
  assignees: Assignees;
  task?: TaskDetail;
  documentId?: number;
}) {
  const [state, onSubmit, pending] = useFormAction<FormState>(task ? updateTaskAction : createTaskAction, {});
  const [due, setDue] = useState(task?.due_date ?? "");
  const [type, setType] = useState<"internal" | "official">(task?.due_date_type ?? "internal");
  const errors = state.fieldErrors ?? {};

  return (
    <form onSubmit={onSubmit} className="max-w-2xl space-y-6" noValidate>
      {task && <input type="hidden" name="id" value={task.id} />}
      {documentId && <input type="hidden" name="document_id" value={documentId} />}

      <Field id="title" label="Title" error={errors.title}>
        {(p) => <input {...p} name="title" required maxLength={200} defaultValue={task?.title} className={inputClass} />}
      </Field>

      <Field id="description" label="What needs to be done" hint="Optional. Say what finished looks like." error={errors.description}>
        {(p) => <textarea {...p} name="description" rows={4} maxLength={5000} defaultValue={task?.description ?? ""} className={inputClass} />}
      </Field>

      <div className="grid gap-6 sm:grid-cols-2">
        <Field id="owner_id" label="Owner" error={errors.owner_id}>
          {(p) => (
            <select {...p} name="owner_id" defaultValue={task?.owner?.id ?? ""} className={inputClass}>
              <option value="">Unassigned</option>
              {assignees.data.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.name} ({a.role_label}{a.department ? `, ${a.department.name}` : ""})
                </option>
              ))}
            </select>
          )}
        </Field>
        <Field id="department_id" label="Department" error={errors.department_id}>
          {(p) => (
            <select {...p} name="department_id" defaultValue={task?.department?.id ?? (assignees.departments.length === 1 ? assignees.departments[0]!.id : "")} className={inputClass}>
              {assignees.departments.length !== 1 && <option value="">None</option>}
              {assignees.departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          )}
        </Field>
      </div>

      <Field id="priority" label="Priority" error={errors.priority}>
        {(p) => (
          <select {...p} name="priority" defaultValue={task?.priority ?? "medium"} className={`${inputClass} sm:w-48`}>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
          </select>
        )}
      </Field>

      <fieldset className="space-y-4 rounded-md border border-rule p-4">
        <legend className="px-1 text-sm font-medium">Due date</legend>
        <Field id="due_date" label="Date" hint="Optional." error={errors.due_date}>
          {(p) => <input {...p} type="date" name="due_date" value={due} onChange={(e) => setDue(e.target.value)} className={`${inputClass} sm:w-56`} />}
        </Field>
        {due && (
          <>
            <div role="radiogroup" aria-label="Kind of deadline" className="space-y-2 text-sm">
              <label className="flex items-start gap-2">
                <input type="radio" name="due_date_type" value="internal" checked={type === "internal"} onChange={() => setType("internal")} className="mt-1" />
                <span>
                  <span className="font-medium">Internal target</span>
                  <span className="block text-xs text-muted">A date your organization chose.</span>
                </span>
              </label>
              <label className="flex items-start gap-2">
                <input type="radio" name="due_date_type" value="official" checked={type === "official"} onChange={() => setType("official")} className="mt-1" />
                <span>
                  <span className="font-medium">Official deadline</span>
                  <span className="block text-xs text-muted">Set by a regulation or regulator. Say where it comes from.</span>
                </span>
              </label>
            </div>
            {type === "official" && (
              <Field id="due_date_basis" label="Source of the deadline" hint='For example "Effective date of FR document 2026-20134".' error={errors.due_date_basis}>
                {(p) => <input {...p} name="due_date_basis" maxLength={200} defaultValue={task?.due_date_basis ?? ""} className={inputClass} />}
              </Field>
            )}
          </>
        )}
      </fieldset>

      <FormMessage state={state} />
      <SubmitButton pending={pending} pendingLabel="Saving…">
        {task ? "Save changes" : "Create task"}
      </SubmitButton>
    </form>
  );
}
