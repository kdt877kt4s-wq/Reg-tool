import "server-only";

/**
 * Server-side API client. Runs only in server components and route
 * handlers, so the API location and any credentials never reach the
 * browser bundle.
 */

export type HealthCheck = { status: "ok" | "error"; detail?: string };

export type Health = {
  service: string;
  version: string;
  status: "ok" | "degraded";
  checks: Record<string, HealthCheck>;
  checked_at: string;
};

export type TaxonomyTerm = {
  kind: "org_type" | "program" | "function" | "topic" | "state";
  code: string;
  label: string;
  description: string | null;
  metadata: Record<string, unknown> | null;
};

export type ApiResult<T> = { ok: true; data: T } | { ok: false; error: string };

function apiUrl(path: string): string {
  const base = process.env.API_URL ?? "http://localhost:8000";
  return `${base.replace(/\/$/, "")}/api/v1${path}`;
}

async function getJson<T>(path: string): Promise<ApiResult<T>> {
  try {
    const res = await fetch(apiUrl(path), {
      headers: { Accept: "application/json" },
      cache: "no-store",
      signal: AbortSignal.timeout(5000),
    });
    // 503 from /health still carries a useful body.
    if (!res.ok && res.status !== 503) {
      return { ok: false, error: `The API answered ${res.status}.` };
    }
    return { ok: true, data: (await res.json()) as T };
  } catch {
    return { ok: false, error: "The API could not be reached." };
  }
}

export const api = {
  health: () => getJson<Health>("/health"),
  taxonomy: () => getJson<{ data: TaxonomyTerm[] }>("/taxonomy"),
};
