"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { apiRequest } from "@/lib/api";
import { formError, type FormState } from "@/lib/api-core";

/** Mark one notification read and open what it points to. */
export async function openNotificationAction(form: FormData): Promise<void> {
  const id = Number(form.get("id"));
  const path = String(form.get("path") ?? "");
  await apiRequest(`/notifications/${id}/read`, { method: "POST" });
  revalidatePath("/", "layout");
  // Only paths inside this app are followed.
  redirect(path.startsWith("/") && !path.startsWith("//") ? path : "/notifications");
}

export async function readAllNotificationsAction(): Promise<void> {
  await apiRequest("/notifications/read-all", { method: "POST" });
  revalidatePath("/", "layout");
}

export async function savePreferencesAction(_: FormState, form: FormData): Promise<FormState> {
  const kinds = form.getAll("kind").map(String);
  const email = Object.fromEntries(kinds.map((k) => [k, form.get(`email_${k}`) === "on"]));
  const res = await apiRequest("/notification-preferences", { method: "PUT", body: { email } });
  if (!res.ok) return formError(res.error);
  revalidatePath("/settings");
  return { ok: true, message: "Email preferences saved." };
}
