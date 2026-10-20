import "server-only";
import { cookies } from "next/headers";

/**
 * The browser holds only these HttpOnly cookies. The API token inside
 * rr_session is read and sent to the API by this server, never by page
 * scripts (see ADR 0005).
 */
export const SESSION_COOKIE = "rr_session";
export const ORG_COOKIE = "rr_org";
export const MFA_CHALLENGE_COOKIE = "rr_mfa";

const base = {
  httpOnly: true,
  secure: process.env.NODE_ENV === "production",
  sameSite: "lax" as const,
  path: "/",
};

export async function getSessionToken(): Promise<string | null> {
  return (await cookies()).get(SESSION_COOKIE)?.value ?? null;
}

export async function setSession(token: string, expiresAt: string): Promise<void> {
  const jar = await cookies();
  jar.set(SESSION_COOKIE, token, { ...base, expires: new Date(expiresAt) });
  jar.delete(MFA_CHALLENGE_COOKIE);
}

export async function clearSession(): Promise<void> {
  const jar = await cookies();
  jar.delete(SESSION_COOKIE);
  jar.delete(ORG_COOKIE);
  jar.delete(MFA_CHALLENGE_COOKIE);
}

export async function getOrgCookie(): Promise<number | null> {
  const raw = (await cookies()).get(ORG_COOKIE)?.value;
  return raw && /^\d+$/.test(raw) ? Number(raw) : null;
}

export async function setOrgCookie(organizationId: number): Promise<void> {
  (await cookies()).set(ORG_COOKIE, String(organizationId), { ...base, maxAge: 60 * 60 * 24 * 365 });
}

export async function setMfaChallenge(challenge: string): Promise<void> {
  (await cookies()).set(MFA_CHALLENGE_COOKIE, challenge, { ...base, maxAge: 5 * 60 });
}

export async function getMfaChallenge(): Promise<string | null> {
  return (await cookies()).get(MFA_CHALLENGE_COOKIE)?.value ?? null;
}
