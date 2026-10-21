import { describe, expect, it } from "vitest";
import { formError, lines, listField, resolveApiBase, toApiError } from "./api-core";

describe("toApiError", () => {
  it("keeps the first message per field from a Laravel validation error", () => {
    const err = toApiError(422, {
      message: "The email field is required. (and 1 more error)",
      errors: { email: ["The email field is required."], "states.0": ["One of the selected values is not recognized."] },
    });
    expect(err.message).toBe("Check the highlighted fields.");
    expect(err.fieldErrors).toEqual({
      email: "The email field is required.",
      "states.0": "One of the selected values is not recognized.",
    });
  });

  it("passes through API messages and codes", () => {
    const err = toApiError(403, { message: "Set up two-factor login to continue.", code: "mfa_enrollment_required" });
    expect(err.message).toBe("Set up two-factor login to continue.");
    expect(err.code).toBe("mfa_enrollment_required");
  });

  it("never shows a raw server error message", () => {
    expect(toApiError(500, { message: "SQLSTATE[08006] connection refused" }).message).toBe("Something went wrong. Try again.");
  });

  it("explains an unreachable server", () => {
    expect(toApiError(0, null).message).toMatch(/could not reach/);
  });

  it("becomes form state", () => {
    expect(formError(toApiError(429, null))).toEqual({
      ok: false,
      message: "Too many attempts. Wait a minute and try again.",
      fieldErrors: {},
    });
  });
});

describe("form helpers", () => {
  it("reads checkbox lists without blanks", () => {
    const form = new FormData();
    form.append("states", "TX");
    form.append("states", " ");
    form.append("states", "LA");
    expect(listField(form, "states")).toEqual(["TX", "LA"]);
  });

  it("splits a textarea into lines", () => {
    expect(lines("HMO D-SNP\r\n\n  PPO  \n")).toEqual(["HMO D-SNP", "PPO"]);
    expect(lines(null)).toEqual([]);
  });
});

describe("resolveApiBase", () => {
  it("defaults to the local API only in development", () => {
    expect(resolveApiBase(undefined, false)).toEqual({ ok: true, base: "http://localhost:8000" });
    expect(resolveApiBase(undefined, true)).toEqual({ ok: false, reason: "missing" });
    expect(resolveApiBase("  ", true)).toEqual({ ok: false, reason: "missing" });
  });

  it("requires HTTPS in production except on this machine", () => {
    expect(resolveApiBase("http://api.example.com", true)).toEqual({ ok: false, reason: "insecure" });
    expect(resolveApiBase("http://127.0.0.1:8000", true)).toEqual({ ok: true, base: "http://127.0.0.1:8000" });
    expect(resolveApiBase("not a url", true)).toEqual({ ok: false, reason: "invalid" });
  });

  it("accepts the address with or without a trailing /api/v1", () => {
    expect(resolveApiBase("https://api.example.com/", true)).toEqual({ ok: true, base: "https://api.example.com" });
    expect(resolveApiBase("https://api.example.com/api/v1", true)).toEqual({ ok: true, base: "https://api.example.com" });
  });
});
