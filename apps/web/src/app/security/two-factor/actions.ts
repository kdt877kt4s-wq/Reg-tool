"use server";

import QRCode from "qrcode";
import { apiRequest } from "@/lib/api";
import { formError } from "@/lib/api-core";

export type SetupState = {
  step: "start" | "scan" | "done";
  secret?: string;
  qrSvg?: string;
  recoveryCodes?: string[];
  message?: string;
  fieldErrors?: Record<string, string>;
};

/** One action drives the whole setup: "start" fetches a secret, "confirm" checks a code. */
export async function twoFactorAction(prev: SetupState, form: FormData): Promise<SetupState> {
  if (form.get("intent") === "start") {
    const res = await apiRequest<{ secret: string; otpauth_uri: string }>("/auth/mfa/setup", {
      method: "POST",
      organizationId: null,
    });
    if (!res.ok) return { step: "start", ...formError(res.error) };

    const qrSvg = await QRCode.toString(res.data.otpauth_uri, { type: "svg", margin: 1, width: 192 });
    return { step: "scan", secret: res.data.secret, qrSvg };
  }

  const res = await apiRequest<{ recovery_codes: string[] }>("/auth/mfa/confirm", {
    method: "POST",
    organizationId: null,
    body: { code: form.get("code") },
  });
  if (!res.ok) return { ...prev, ...formError(res.error) };

  return { step: "done", recoveryCodes: res.data.recovery_codes };
}
