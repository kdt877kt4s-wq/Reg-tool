import "server-only";
import { redirect } from "next/navigation";
import { cache } from "react";
import { apiRequest, getMe, type Me, type OrganizationInfo } from "./api";

/**
 * Guard for signed-in pages. Sends people where they need to be:
 * sign-in, two-factor enrollment, or (with no organization) an
 * explanation. Authorization itself is enforced by the API.
 */
export async function requireAccount(): Promise<Me> {
  const me = await getMe();
  if (!me) redirect("/login");
  return me;
}

/** Cached per request: the layout and the page share one lookup. */
export const requireOrganization = cache(async (): Promise<{ me: Me; org: OrganizationInfo }> => {
  const me = await requireAccount();
  if (me.mfa_enrollment_required) redirect("/security/two-factor");
  if (me.memberships.length === 0) redirect("/no-organization");

  const res = await apiRequest<OrganizationInfo>("/organization");
  if (!res.ok) {
    if (res.error.status === 401) redirect("/login");
    if (res.error.code === "mfa_enrollment_required") redirect("/security/two-factor");
    throw new Error(res.error.message);
  }
  return { me, org: res.data };
});

export function can(org: OrganizationInfo, permission: string): boolean {
  return org.permissions.includes(permission);
}
