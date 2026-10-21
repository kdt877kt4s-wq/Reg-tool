import "server-only";
import { cache } from "react";
import { API_CONFIG_MESSAGES, resolveApiBase, toApiError, type ApiError, type ApiResult } from "./api-core";
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

const apiBase = resolveApiBase(process.env.API_URL, process.env.NODE_ENV === "production");
if (!apiBase.ok) console.error(`[ruleradar] API_URL is ${apiBase.reason}: ${API_CONFIG_MESSAGES[apiBase.reason]}`);

function apiUrl(path: string): string | null {
  return apiBase.ok ? `${apiBase.base}/api/v1${path}` : null;
}

function configError(): ApiError {
  const reason = apiBase.ok ? "missing" : apiBase.reason;
  return { status: 0, code: "api_not_configured", message: API_CONFIG_MESSAGES[reason], fieldErrors: {} };
}

/** Log why the API could not be reached (host only, never tokens or bodies). */
function logUnreachable(url: string, error: unknown): void {
  const cause = error instanceof Error ? ((error.cause as { code?: string } | undefined)?.code ?? error.name) : "unknown";
  console.error(`[ruleradar] API unreachable at ${new URL(url).origin}: ${cause}`);
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

  const url = apiUrl(path);
  if (!url) return { ok: false, error: configError() };

  let res: Response;
  try {
    res = await fetch(url, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      cache: "no-store",
      signal: AbortSignal.timeout(10_000),
    });
  } catch (error) {
    logUnreachable(url, error);
    return { ok: false, error: toApiError(0, null) };
  }

  const json: unknown = await res.json().catch(() => null);
  if (!res.ok) return { ok: false, error: toApiError(res.status, json) };
  return { ok: true, status: res.status, data: json as T };
}

/**
 * Raw request for bodies apiRequest does not handle (file uploads and
 * downloads). Adds the token and organization; returns the Response.
 */
export async function apiFetch(path: string, init: { method?: string; body?: BodyInit; timeoutMs?: number } = {}): Promise<Response | null> {
  const token = await getSessionToken();
  const organizationId = await getActiveOrganizationId();
  const url = apiUrl(path);
  if (!token || organizationId === null || !url) return null;
  try {
    return await fetch(url, {
      method: init.method ?? "GET",
      headers: { Accept: "application/json", Authorization: `Bearer ${token}`, "X-Organization-Id": String(organizationId) },
      body: init.body,
      cache: "no-store",
      signal: AbortSignal.timeout(init.timeoutMs ?? 60_000),
    });
  } catch (error) {
    logUnreachable(url, error);
    return null;
  }
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

export type RegulatoryDocumentSummary = {
  id: number;
  document_number: string;
  citation: string | null;
  type: string;
  type_label: string;
  action: string | null;
  title: string;
  agencies: { slug: string; name: string }[];
  cfr_parts: string[];
  profile_matches: string[];
  publication_date: string;
  effective_date: string | null;
  comments_close_date: string | null;
  status: "active" | "corrected" | "withdrawn";
  text_status: "pending" | "available" | "unavailable";
  significant: boolean | null;
  official_pdf_url: string | null;
  html_url: string | null;
  is_demo: boolean;
  version: number;
  last_changed_at: string;
};

export type DocumentList = {
  data: RegulatoryDocumentSummary[];
  meta: { total: number; page: number; last_page: number; profile_cfr_parts: string[]; profile_filter_unavailable: boolean };
};

export type RegulatoryDocumentDetail = RegulatoryDocumentSummary & {
  abstract: string | null;
  dates_text: string | null;
  docket_ids: string[];
  rins: string[];
  start_page: number | null;
  end_page: number | null;
  raw_text_url: string | null;
  source: { code: string; name: string };
  first_seen_at: string;
  retrieved_at: string | null;
  passage_count: number;
  versions: { version: number; retrieved_at: string; changed_fields: string[]; changes: Record<string, { from: unknown; to: unknown }> | null }[];
  relations: {
    direction: "outgoing" | "incoming";
    relation: "corrects" | "withdraws";
    document_number: string;
    document_id: number | null;
    title: string | null;
    method: "structured_field" | "parsed_from_text";
  }[];
};

export type Passage = {
  id: number;
  ordinal: number;
  heading_path: string | null;
  text: string;
  page_start: number | null;
  page_end: number | null;
  snippet: string | null;
};

export type PassageList = {
  data: Passage[];
  meta: { text_status: string; total: number; page: number; last_page: number };
};

export type SourceStatus = {
  code: string;
  name: string;
  status: "unknown" | "healthy" | "degraded";
  stale: boolean;
  last_success_at: string | null;
  last_run_at: string | null;
  last_error: string | null;
  documents: number;
};

export type Facets = {
  agencies: { slug: string; name: string; documents: number }[];
  types: { type: string; label: string; documents: number }[];
};

export type Claim = {
  text: string;
  basis: "regulatory_text" | "ai_interpretation";
  citations: number[];
  quote?: string;
};

export type AnalysisOutput = {
  what_changed: Claim[];
  why_it_matters: Claim[];
  applicability: { level: "likely" | "possible" | "unlikely"; reasons: Claim[]; missing_facts: string[] };
  affected_functions: string[];
  operational_impacts: Claim[];
  policies_to_review: Claim[];
  deadlines: { date: string; label: string; citations: number[]; in_source_record: boolean }[];
  suggested_actions: { title: string; description: string; priority: "high" | "medium" | "low"; citations: number[] }[];
  model_confidence: string;
  confidence_reason: string;
};

export type CitationInfo = {
  id: number;
  ordinal: number;
  heading_path: string | null;
  page_start: number | null;
  page_end: number | null;
  excerpt: string;
};

export type Analysis = {
  id: number;
  status: "queued" | "running" | "completed" | "failed";
  error: string | null;
  model: string | null;
  prompt_version: string;
  profile_version: number;
  confidence: "high" | "medium" | "low" | null;
  output: AnalysisOutput | null;
  citations: Record<string, CitationInfo>;
  removed: { section: string; text: string; reason: string }[];
  passages_sent: number | null;
  created_at: string;
  completed_at: string | null;
  has_assessment: boolean;
};

export type AnalysisResponse = { data: Analysis | null; can_run: boolean; configured: boolean };

export type AssessmentAction = {
  id: number;
  title: string;
  description: string | null;
  priority: "high" | "medium" | "low";
  state: "proposed" | "accepted" | "dismissed";
  origin: "ai" | "person";
  citation_passage_ids: number[];
};

export type Assessment = {
  id: number;
  status: "draft" | "approved";
  decision: "applicable" | "not_applicable" | "monitor" | null;
  rationale: string | null;
  affected_functions: string[];
  analysis_id: number | null;
  document_version: number;
  source_changed: boolean;
  approved_by: string | null;
  approved_at: string | null;
  updated_by: string | null;
  updated_at: string;
  actions: AssessmentAction[];
};

export type QueueRow = {
  group: "awaiting_review" | "approved" | "in_progress" | "failed";
  document: {
    id: number;
    document_number: string;
    title: string;
    type_label: string;
    publication_date: string;
    effective_date: string | null;
    comments_close_date: string | null;
  };
  analysis: { status: Analysis["status"]; confidence: Analysis["confidence"]; applicability: string | null } | null;
  assessment: { status: Assessment["status"]; decision: Assessment["decision"]; source_changed: boolean; approved_at: string | null } | null;
  updated_at: string;
};

export type TaskStatus = "open" | "in_progress" | "submitted" | "returned" | "done" | "cancelled";
export type TaskAction = "edit" | "cancel" | "start" | "add_evidence" | "submit" | "accept" | "return";

export type TaskSummary = {
  id: number;
  title: string;
  status: TaskStatus;
  priority: "high" | "medium" | "low";
  owner: { id: number; name: string } | null;
  department: { id: number; name: string } | null;
  due_date: string | null;
  due_date_type: "official" | "internal" | null;
  overdue: boolean;
  document: { id: number; document_number: string; title: string } | null;
  updated_at: string;
};

export type Evidence = {
  id: number;
  note: string | null;
  submitted_by: string | null;
  created_at: string;
  file: { id: number; filename: string; mime: string; size: number; sha256: string; scan_status: "not_scanned" | "clean" | "infected" } | null;
};

export type TaskDetail = TaskSummary & {
  description: string | null;
  due_date_basis: string | null;
  status_note: string | null;
  assessment_id: number | null;
  from_action: boolean;
  citations: { id: number; heading_path: string | null; page_start: number | null; page_end: number | null }[];
  document_citation: string | null;
  submitted_at: string | null;
  completed_at: string | null;
  allowed_actions: TaskAction[];
  evidence: Evidence[];
  events: { type: string; actor: string | null; details: Record<string, unknown> | null; created_at: string }[];
};

export type Assignees = {
  data: { id: number; name: string; role_label: string; department: { id: number; name: string } | null }[];
  departments: { id: number; name: string }[];
};

export type CalendarEntry = {
  id: string;
  date: string;
  kind: "effective" | "comments_close" | "task_due";
  deadline_type: "official" | "internal";
  title: string;
  basis: string | null;
  document?: { id: number; document_number: string; title: string; type: string };
  assessed?: boolean;
  decision?: string | null;
  profile_match?: boolean;
  task?: { id: number; status: TaskStatus; priority: string; owner: string | null; overdue: boolean };
};

export type AppNotification = {
  id: number;
  kind: string;
  title: string;
  body: string | null;
  path: string | null;
  read_at: string | null;
  emailed: boolean;
  created_at: string;
};

export type NotificationPreference = { kind: string; label: string; email: boolean; in_app: boolean };

export type DashboardSummary = {
  my_tasks: { active: number; overdue: number; due_this_week: number; returned: number };
  to_review: number;
  tasks: { scope: "organization" | "visible"; active: number; overdue: number; unassigned: number; done_30d: number };
  assessments: { awaiting_review: number; in_progress: number; approved: number; source_changed: number };
  deadlines: CalendarEntry[];
  weekly_publications: { week: string; total: number; matches: number }[];
  profile_configured: boolean;
};

export type ReportMeta = { organization: string | null; generated_at: string; generated_by: string | null; notice: string };

export type ActivityRow = {
  document_id: number;
  document_number: string;
  citation: string | null;
  type: string;
  title: string;
  agencies: string;
  publication_date: string;
  effective_date: string | null;
  comments_close_date: string | null;
  cfr_parts: string;
  profile_match: boolean;
  ai_applicability: "likely" | "possible" | "unlikely" | null;
  ai_confidence: "high" | "medium" | "low" | null;
  assessment_status: "draft" | "approved" | null;
  decision: "applicable" | "not_applicable" | "monitor" | null;
  approved_by: string | null;
  approved_at: string | null;
  source_changed_since_approval: boolean | null;
  tasks: number;
  tasks_done: number;
  source_url: string | null;
};

export type ActivityReport = {
  meta: ReportMeta & { from: string; to: string; scope: "profile" | "all"; profile_cfr_parts: string[]; truncated: boolean };
  summary: { publications: number; profile_matches: number; analyzed: number; approved: number; applies: number; source_changed: number };
  data: ActivityRow[];
};

export type TaskReportRow = {
  task_id: number;
  title: string;
  status: TaskStatus;
  priority: "high" | "medium" | "low";
  owner: string | null;
  department: string | null;
  due_date: string | null;
  deadline_type: "official" | "internal" | null;
  deadline_source: string | null;
  overdue: boolean;
  evidence_items: number;
  times_returned: number;
  submitted_at: string | null;
  completed_at: string | null;
  accepted_by: string | null;
  source_document: string | null;
  source_title: string | null;
  from_assessment_action: boolean;
};

export type TaskReport = {
  meta: ReportMeta & { status: string };
  summary: { tasks: number; active: number; overdue: number; done: number; done_without_evidence: number; unassigned: number };
  data: TaskReportRow[];
};

export type EvidencePack = {
  meta: ReportMeta;
  document: {
    id: number;
    document_number: string;
    citation: string | null;
    title: string;
    type: string;
    publication_date: string;
    effective_date: string | null;
    comments_close_date: string | null;
    current_version: number;
    html_url: string | null;
    official_pdf_url: string | null;
  };
  assessment: Assessment;
  analysis: Analysis | null;
  tasks: {
    id: number;
    title: string;
    status: TaskStatus;
    owner: string | null;
    department: string | null;
    due_date: string | null;
    due_date_type: "official" | "internal" | null;
    due_date_basis: string | null;
    completed_at: string | null;
    evidence: { note: string | null; submitted_by: string | null; created_at: string; file: { filename: string; size: number; sha256: string; scan_status: string } | null }[];
    history: { type: string; actor: string | null; created_at: string }[];
  }[];
  audit_trail: { id: number; action: string; actor: string | null; created_at: string; hash: string }[];
  audit_chain: { valid: boolean; checked: number; broken_at_id: number | null };
};

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
