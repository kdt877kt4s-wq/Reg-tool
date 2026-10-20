import "server-only";
import { cache } from "react";
import { toApiError, type ApiResult } from "./api-core";
import { getOrgCookie, getSessionToken } from "./session";

/**
 * Server-side API client. Runs only in server components and server
 * actions: the API token is attached here and never reaches the browser.
 */

export type { ApiResult } from "./api-core";

type Options = {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  /** Attach the session token (default true). */
  auth?: boolean;
  /** Organization to address; defaults to the active one. Pass null for account routes. */
  organizationId?: number | null;
};

function apiUrl(path: string): string {
  const base = process.env.API_URL ?? "http://localhost:8000";
  return `${base.replace(/\/$/, "")}/api/v1${path}`;
}

export async function apiRequest<T>(path: string, options: Options = {}): Promise<ApiResult<T>> {
  const { method = "GET", body, auth = true } = options;
  const headers: Record<string, string> = { Accept: "application/json" };

  if (auth) {
    const token = await getSessionToken();
    if (!token) return { ok: false, error: toApiError(401, null) };
    headers.Authorization = `Bearer ${token}`;

    const organizationId = options.organizationId === undefined ? await getActiveOrganizationId() : options.organizationId;
    if (organizationId !== null) headers["X-Organization-Id"] = String(organizationId);
  }
  if (body !== undefined) headers["Content-Type"] = "application/json";

  let res: Response;
  try {
    res = await fetch(apiUrl(path), {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      cache: "no-store",
      signal: AbortSignal.timeout(10_000),
    });
  } catch {
    return { ok: false, error: toApiError(0, null) };
  }

  const json: unknown = await res.json().catch(() => null);
  if (!res.ok) return { ok: false, error: toApiError(res.status, json) };
  return { ok: true, status: res.status, data: json as T };
}

/* ---------- Shapes returned by the API ---------- */

export type Me = {
  user: { id: number; name: string; email: string; mfa_enabled: boolean; recovery_codes_remaining: number | null };
  memberships: { organization_id: number; organization_name: string; role: string; role_label: string }[];
  mfa_enrollment_required: boolean;
};

export type OrganizationInfo = {
  organization: { id: number; name: string; slug: string; industry: string };
  membership: { role: string; role_label: string; department_id: number | null };
  permissions: string[];
  profile: { completed: boolean; version: number };
  subscription: { plan: string; plan_name: string; status: string } | null;
};

export type Profile = {
  org_types: string[];
  states: string[];
  programs: string[];
  functions: string[];
  products: string[];
  obligations: string[];
  version: number;
  completed: boolean;
  updated_at: string | null;
};

export type TaxonomyTerm = {
  kind: "org_type" | "program" | "function" | "topic" | "state";
  code: string;
  label: string;
  description: string | null;
  metadata: { cfr_parts?: string[] } | null;
};

export type Member = {
  id: number;
  user: { id: number; name: string; email: string; mfa_enabled: boolean };
  role: string;
  role_label: string;
  department: { id: number; name: string } | null;
  status: string;
};

export type Invitation = {
  id: number;
  email: string;
  role: string;
  role_label: string;
  department_id: number | null;
  invited_by: string | null;
  expires_at: string;
};

export type Department = { id: number; name: string };

export type Health = {
  service: string;
  version: string;
  status: "ok" | "degraded";
  checks: Record<string, { status: "ok" | "error"; detail?: string }>;
  checked_at: string;
};

/* ---------- Cached per request ---------- */

export const getMe = cache(async (): Promise<Me | null> => {
  const res = await apiRequest<Me>("/auth/me", { organizationId: null });
  return res.ok ? res.data : null;
});

/** The organization this request addresses: the cookie's choice if still valid, else the first membership. */
export const getActiveOrganizationId = cache(async (): Promise<number | null> => {
  const me = await getMe();
  if (!me || me.memberships.length === 0) return null;
  const chosen = await getOrgCookie();
  const valid = me.memberships.some((m) => m.organization_id === chosen);
  return valid && chosen !== null ? chosen : me.memberships[0]!.organization_id;
});

export const getTaxonomy = cache(async (): Promise<TaxonomyTerm[]> => {
  const res = await apiRequest<{ data: TaxonomyTerm[] }>("/taxonomy", { auth: false });
  return res.ok ? res.data.data : [];
});

export const api = {
  health: () => apiRequest<Health>("/health", { auth: false }),
};
