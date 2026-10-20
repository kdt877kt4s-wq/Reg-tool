/**
 * Pure helpers for API responses. Kept free of Next.js imports so they
 * can be unit tested.
 */

export type ApiError = {
  status: number;
  message: string;
  code?: string;
  fieldErrors: Record<string, string>;
};

export type ApiResult<T> = { ok: true; status: number; data: T } | { ok: false; error: ApiError };

type ErrorBody = { message?: unknown; code?: unknown; errors?: unknown };

/** Normalise a Laravel JSON error body into one message plus the first error per field. */
export function toApiError(status: number, body: unknown): ApiError {
  const b = (body && typeof body === "object" ? body : {}) as ErrorBody;
  const fieldErrors: Record<string, string> = {};

  if (b.errors && typeof b.errors === "object") {
    for (const [field, messages] of Object.entries(b.errors as Record<string, unknown>)) {
      const first = Array.isArray(messages) ? messages[0] : messages;
      if (typeof first === "string") fieldErrors[field] = first;
    }
  }

  const fallback =
    status === 0
      ? "RuleRadar could not reach its server. Try again in a moment."
      : status === 401
        ? "Your session has ended. Sign in again."
        : status === 403
          ? "You do not have permission to do this."
          : status === 404
            ? "That item could not be found."
            : status === 429
              ? "Too many attempts. Wait a minute and try again."
              : "Something went wrong. Try again.";

  const message =
    status === 422 && Object.keys(fieldErrors).length > 0
      ? "Check the highlighted fields."
      : typeof b.message === "string" && b.message && status !== 500
        ? b.message
        : fallback;

  return {
    status,
    message,
    code: typeof b.code === "string" ? b.code : undefined,
    fieldErrors,
  };
}

/** Form-action state shared by every form. */
export type FormState = {
  ok?: boolean;
  message?: string;
  fieldErrors?: Record<string, string>;
};

export function formError(error: ApiError): FormState {
  return { ok: false, message: error.message, fieldErrors: error.fieldErrors };
}

/** Read a list field from submitted form data, dropping blanks. */
export function listField(form: FormData, name: string): string[] {
  return form
    .getAll(name)
    .map((v) => String(v).trim())
    .filter(Boolean);
}

/** Split a textarea into one entry per non-empty line. */
export function lines(value: FormDataEntryValue | null): string[] {
  return String(value ?? "")
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean);
}
