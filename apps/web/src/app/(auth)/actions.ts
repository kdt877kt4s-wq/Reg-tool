"use server";

import { redirect } from "next/navigation";
import { apiRequest } from "@/lib/api";
import { formError, type FormState } from "@/lib/api-core";
import { clearSession, getMfaChallenge, getSessionToken, setMfaChallenge, setSession } from "@/lib/session";

type TokenResponse = { token: string; expires_at: string };
type LoginResponse = TokenResponse | { mfa_required: true; challenge: string };

/** Only allow redirects to paths on this site. */
function safeNext(value: FormDataEntryValue | null): string {
  const next = String(value ?? "");
  return next.startsWith("/") && !next.startsWith("//") ? next : "/";
}

export async function loginAction(_: FormState, form: FormData): Promise<FormState> {
  const res = await apiRequest<LoginResponse>("/auth/login", {
    method: "POST",
    auth: false,
    body: { email: form.get("email"), password: form.get("password") },
  });
  if (!res.ok) return formError(res.error);

  if ("mfa_required" in res.data) {
    await setMfaChallenge(res.data.challenge);
    redirect(`/login/verify?next=${encodeURIComponent(safeNext(form.get("next")))}`);
  }

  await setSession(res.data.token, res.data.expires_at);
  redirect(safeNext(form.get("next")));
}

export async function verifyAction(_: FormState, form: FormData): Promise<FormState> {
  const challenge = await getMfaChallenge();
  if (!challenge) return { ok: false, message: "This sign-in attempt expired. Sign in again." };

  const useRecovery = form.get("method") === "recovery";
  const res = await apiRequest<TokenResponse>("/auth/mfa/challenge", {
    method: "POST",
    auth: false,
    body: useRecovery
      ? { challenge, recovery_code: form.get("recovery_code") }
      : { challenge, code: form.get("code") },
  });
  if (!res.ok) return formError(res.error);

  await setSession(res.data.token, res.data.expires_at);
  redirect(safeNext(form.get("next")));
}

export async function registerAction(_: FormState, form: FormData): Promise<FormState> {
  const res = await apiRequest<TokenResponse>("/auth/register", {
    method: "POST",
    auth: false,
    body: {
      name: form.get("name"),
      email: form.get("email"),
      organization_name: form.get("organization_name"),
      password: form.get("password"),
      password_confirmation: form.get("password_confirmation"),
    },
  });
  if (!res.ok) return formError(res.error);

  await setSession(res.data.token, res.data.expires_at);
  // Administrators set up two-factor next, then the regulatory profile.
  redirect("/security/two-factor?onboarding=1");
}

export async function forgotPasswordAction(_: FormState, form: FormData): Promise<FormState> {
  const res = await apiRequest<{ message: string }>("/auth/password/forgot", {
    method: "POST",
    auth: false,
    body: { email: form.get("email") },
  });
  return res.ok ? { ok: true, message: res.data.message } : formError(res.error);
}

export async function resetPasswordAction(_: FormState, form: FormData): Promise<FormState> {
  const res = await apiRequest<{ message: string }>("/auth/password/reset", {
    method: "POST",
    auth: false,
    body: {
      token: form.get("token"),
      email: form.get("email"),
      password: form.get("password"),
      password_confirmation: form.get("password_confirmation"),
    },
  });
  if (!res.ok) return formError(res.error);
  redirect("/login?reset=1");
}

export async function acceptInvitationAction(_: FormState, form: FormData): Promise<FormState> {
  const token = String(form.get("token") ?? "");
  const body: Record<string, FormDataEntryValue | null> = { password: form.get("password") };
  if (form.has("name")) {
    body.name = form.get("name");
    body.password_confirmation = form.get("password_confirmation");
  }

  const res = await apiRequest<{ message: string }>(`/invitations/${encodeURIComponent(token)}/accept`, {
    method: "POST",
    auth: false,
    body,
  });
  if (!res.ok) return formError(res.error);
  redirect("/login?joined=1");
}

export async function logoutAction(): Promise<void> {
  if (await getSessionToken()) {
    await apiRequest("/auth/logout", { method: "POST", organizationId: null });
  }
  await clearSession();
  redirect("/login");
}
