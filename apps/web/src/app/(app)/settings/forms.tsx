"use client";

import {
  createDepartmentAction,
  inviteMemberAction,
  removeMemberAction,
  renameOrganizationAction,
  updateMemberAction,
} from "@/app/(app)/actions";
import { SubmitButton } from "@/components/ui/button";
import { Field, FormMessage, inputClass } from "@/components/ui/field";
import type { Department, Member } from "@/lib/api";
import { useFormAction } from "@/lib/use-form-action";

type RoleOption = { value: string; label: string };

export function RenameForm({ name }: { name: string }) {
  const [state, onSubmit, pending] = useFormAction(renameOrganizationAction, {});
  return (
    <form onSubmit={onSubmit} className="mt-4 flex max-w-xl flex-wrap items-end gap-3" noValidate>
      <Field id="org-name" label="Name" error={state.fieldErrors?.name} className="min-w-64 flex-1">
        {(p) => <input {...p} name="name" defaultValue={name} required className={inputClass} />}
      </Field>
      <SubmitButton pending={pending} variant="secondary" pendingLabel="Saving">
        Save name
      </SubmitButton>
      <div className="w-full">
        <FormMessage state={state.fieldErrors?.name ? {} : state} />
      </div>
    </form>
  );
}

export function InviteForm({ roles, departments }: { roles: readonly RoleOption[]; departments: Department[] }) {
  const [state, onSubmit, pending] = useFormAction(inviteMemberAction, {});
  const e = state.fieldErrors ?? {};

  return (
    <form onSubmit={onSubmit} className="mt-8 rounded-md border border-rule p-5" noValidate>
      <h3 className="text-base font-semibold">Invite someone</h3>
      <p className="mt-1 text-sm text-muted">They get an email with a link that works for 7 days.</p>
      <div className="mt-4 grid gap-4 md:grid-cols-[2fr_1.5fr_1.2fr]">
        <Field id="invite-email" label="Email" error={e.email}>
          {(p) => <input {...p} name="email" type="email" autoComplete="off" required className={inputClass} />}
        </Field>
        <Field id="invite-role" label="Role" error={e.role}>
          {(p) => (
            <select {...p} name="role" defaultValue="employee" className={inputClass}>
              {roles.map((r) => (
                <option key={r.value} value={r.value}>
                  {r.label}
                </option>
              ))}
            </select>
          )}
        </Field>
        <Field id="invite-department" label="Department" error={e.department_id}>
          {(p) => (
            <select {...p} name="department_id" defaultValue="" className={inputClass}>
              <option value="">None</option>
              {departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
          )}
        </Field>
      </div>
      <div className="mt-4 flex flex-wrap items-center gap-4">
        <SubmitButton pending={pending} pendingLabel="Sending">
          Send invitation
        </SubmitButton>
        <FormMessage state={Object.keys(e).length ? {} : state} />
      </div>
    </form>
  );
}

export function MemberRow({
  member,
  roles,
  departments,
  editable,
  isSelf,
}: {
  member: Member;
  roles: readonly RoleOption[];
  departments: Department[];
  editable: boolean;
  isSelf: boolean;
}) {
  const [saveState, onSave, saving] = useFormAction(updateMemberAction, {});
  const [removeState, onRemove, removing] = useFormAction(removeMemberAction, {});
  const message = removeState.message ?? saveState.message;
  const failed = removeState.ok === false || saveState.ok === false;

  return (
    <tr className="align-top">
      <td className="py-3 pr-4">
        <p className="font-medium">
          {member.user.name}
          {isSelf && <span className="font-normal text-muted"> (you)</span>}
        </p>
        <p className="text-muted">{member.user.email}</p>
        {member.status === "suspended" && <p className="text-xs text-caution">Suspended</p>}
      </td>
      <td className="py-3 pr-4">
        {editable ? (
          <form onSubmit={onSave} id={`member-${member.id}`} className="flex flex-wrap gap-2">
            <input type="hidden" name="id" value={member.id} />
            <label className="sr-only" htmlFor={`role-${member.id}`}>
              Role for {member.user.name}
            </label>
            <select id={`role-${member.id}`} name="role" defaultValue={member.role} className={`${inputClass} w-auto py-1.5`}>
              {roles.map((r) => (
                <option key={r.value} value={r.value}>
                  {r.label}
                </option>
              ))}
            </select>
            <label className="sr-only" htmlFor={`dept-${member.id}`}>
              Department for {member.user.name}
            </label>
            <select
              id={`dept-${member.id}`}
              name="department_id"
              defaultValue={member.department?.id ?? ""}
              className={`${inputClass} w-auto py-1.5`}
            >
              <option value="">No department</option>
              {departments.map((d) => (
                <option key={d.id} value={d.id}>
                  {d.name}
                </option>
              ))}
            </select>
            <SubmitButton pending={saving} variant="secondary" pendingLabel="Saving" className="py-1.5">
              Save
            </SubmitButton>
          </form>
        ) : (
          <p>
            {member.role_label}
            {member.department && <span className="text-muted">, {member.department.name}</span>}
          </p>
        )}
        {message && (
          <p role={failed ? "alert" : "status"} className={failed ? "mt-1 text-xs text-caution" : "mt-1 text-xs text-muted"}>
            {message}
          </p>
        )}
      </td>
      <td className="py-3 pr-4">{member.user.mfa_enabled ? "On" : <span className="text-muted">Off</span>}</td>
      {editable && (
        <td className="py-3 text-right">
          {!isSelf && (
            <details className="inline-block text-left">
              <summary className="cursor-pointer text-sm text-caution underline-offset-4 hover:underline">Remove</summary>
              <form onSubmit={onRemove} className="mt-2 space-y-2">
                <input type="hidden" name="id" value={member.id} />
                <p className="max-w-48 text-xs text-muted">They lose access right away. Their past actions stay in the audit log.</p>
                <SubmitButton pending={removing} variant="danger" pendingLabel="Removing" className="py-1.5">
                  Remove {member.user.name.split(" ")[0]}
                </SubmitButton>
              </form>
            </details>
          )}
        </td>
      )}
    </tr>
  );
}

export function CreateDepartmentForm() {
  const [state, onSubmit, pending] = useFormAction(createDepartmentAction, {});
  return (
    <form onSubmit={onSubmit} className="mt-6 flex max-w-xl flex-wrap items-end gap-3" noValidate>
      <Field id="department-name" label="New department" error={state.fieldErrors?.name} className="min-w-64 flex-1">
        {(p) => <input {...p} name="name" placeholder="For example, Claims" required className={inputClass} />}
      </Field>
      <SubmitButton pending={pending} variant="secondary" pendingLabel="Adding">
        Add department
      </SubmitButton>
      <div className="w-full">
        <FormMessage state={state.fieldErrors?.name ? {} : state} />
      </div>
    </form>
  );
}
