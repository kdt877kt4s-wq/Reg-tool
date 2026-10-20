"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { apiRequest, getMe } from "@/lib/api";
import { formError, lines, listField, type FormState } from "@/lib/api-core";
import { setOrgCookie } from "@/lib/session";

export async function switchOrganizationAction(form: FormData): Promise<void> {
  const id = Number(form.get("organization_id"));
  const me = await getMe();
  if (me?.memberships.some((m) => m.organization_id === id)) {
    await setOrgCookie(id);
  }
  redirect("/");
}

export async function saveProfileAction(_: FormState, form: FormData): Promise<FormState> {
  const res = await apiRequest("/organization/profile", {
    method: "PUT",
    body: {
      org_types: listField(form, "org_types"),
      states: listField(form, "states"),
      programs: listField(form, "programs"),
      functions: listField(form, "functions"),
      products: lines(form.get("products")),
      obligations: lines(form.get("obligations")),
    },
  });
  if (!res.ok) return formError(res.error);

  revalidatePath("/", "layout");
  if (form.get("welcome")) redirect("/?profile=saved");
  return { ok: true, message: "Regulatory profile saved." };
}

export async function renameOrganizationAction(_: FormState, form: FormData): Promise<FormState> {
  const res = await apiRequest("/organization", { method: "PATCH", body: { name: form.get("name") } });
  if (!res.ok) return formError(res.error);
  revalidatePath("/", "layout");
  return { ok: true, message: "Organization name saved." };
}

export async function inviteMemberAction(_: FormState, form: FormData): Promise<FormState> {
  const department = form.get("department_id");
  const res = await apiRequest("/organization/invitations", {
    method: "POST",
    body: {
      email: form.get("email"),
      role: form.get("role"),
      department_id: department ? Number(department) : null,
    },
  });
  if (!res.ok) return formError(res.error);
  revalidatePath("/settings");
  return { ok: true, message: `Invitation sent to ${String(form.get("email"))}.` };
}

export async function revokeInvitationAction(form: FormData): Promise<void> {
  await apiRequest(`/organization/invitations/${Number(form.get("id"))}`, { method: "DELETE" });
  revalidatePath("/settings");
}

export async function updateMemberAction(_: FormState, form: FormData): Promise<FormState> {
  const department = form.get("department_id");
  const res = await apiRequest(`/organization/members/${Number(form.get("id"))}`, {
    method: "PATCH",
    body: { role: form.get("role"), department_id: department ? Number(department) : null },
  });
  if (!res.ok) return formError(res.error);
  revalidatePath("/settings");
  return { ok: true, message: "Saved." };
}

export async function removeMemberAction(_: FormState, form: FormData): Promise<FormState> {
  const res = await apiRequest(`/organization/members/${Number(form.get("id"))}`, { method: "DELETE" });
  if (!res.ok) return formError(res.error);
  revalidatePath("/settings");
  return { ok: true, message: "Member removed." };
}

export async function createDepartmentAction(_: FormState, form: FormData): Promise<FormState> {
  const res = await apiRequest("/departments", { method: "POST", body: { name: form.get("name") } });
  if (!res.ok) return formError(res.error);
  revalidatePath("/settings");
  return { ok: true, message: `Added ${String(form.get("name"))}.` };
}
