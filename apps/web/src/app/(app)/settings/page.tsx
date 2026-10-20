import Link from "next/link";
import { revokeInvitationAction } from "@/app/(app)/actions";
import { apiRequest, type Department, type Invitation, type Member } from "@/lib/api";
import { can, requireOrganization } from "@/lib/auth";
import { ROLES } from "@/lib/roles";
import { CreateDepartmentForm, InviteForm, MemberRow, RenameForm } from "./forms";

export const metadata = { title: "Organization settings" };

export default async function SettingsPage() {
  const { me, org } = await requireOrganization();
  const canViewMembers = can(org, "members.view");
  const canManage = can(org, "members.manage");

  const [members, invitations, departments] = await Promise.all([
    canViewMembers ? apiRequest<{ data: Member[] }>("/organization/members") : null,
    canViewMembers ? apiRequest<{ data: Invitation[] }>("/organization/invitations") : null,
    apiRequest<{ data: Department[] }>("/departments"),
  ]);
  const departmentList = departments.ok ? departments.data.data : [];

  return (
    <div className="max-w-4xl space-y-14">
      <header>
        <h1 className="text-2xl font-semibold tracking-tight">Organization settings</h1>
        <p className="mt-2 text-muted">
          {org.organization.name}
          {org.subscription && `, ${org.subscription.plan_name} plan (${org.subscription.status})`}
        </p>
      </header>

      <section aria-labelledby="org-heading">
        <h2 id="org-heading" className="text-lg font-semibold">
          Organization
        </h2>
        {can(org, "organization.update") ? (
          <RenameForm name={org.organization.name} />
        ) : (
          <p className="mt-2 text-sm text-muted">Only administrators can rename the organization.</p>
        )}
      </section>

      <section aria-labelledby="profile-heading">
        <h2 id="profile-heading" className="text-lg font-semibold">
          Regulatory profile
        </h2>
        <p className="mt-2 text-sm text-muted">
          {org.profile.completed ? `Version ${org.profile.version} is in use.` : "Not completed yet."}{" "}
          <Link href="/settings/profile" className="text-signal underline-offset-4 hover:underline">
            {can(org, "profile.edit") ? "Edit the profile" : "View the profile"}
          </Link>
        </p>
      </section>

      {canViewMembers && (
        <section aria-labelledby="members-heading">
          <h2 id="members-heading" className="text-lg font-semibold">
            People
          </h2>
          {members?.ok ? (
            <div className="mt-4 overflow-x-auto">
              <table className="w-full min-w-[40rem] text-left text-sm">
                <thead className="border-b border-rule text-xs text-muted">
                  <tr>
                    <th scope="col" className="py-2 pr-4 font-medium">Person</th>
                    <th scope="col" className="py-2 pr-4 font-medium">Role and department</th>
                    <th scope="col" className="py-2 pr-4 font-medium">Two-factor</th>
                    {canManage && <th scope="col" className="py-2 font-medium"><span className="sr-only">Actions</span></th>}
                  </tr>
                </thead>
                <tbody className="divide-y divide-rule">
                  {members.data.data.map((m) => (
                    <MemberRow
                      key={m.id}
                      member={m}
                      roles={ROLES}
                      departments={departmentList}
                      editable={canManage}
                      isSelf={m.user.id === me.user.id}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p role="alert" className="mt-4 text-sm">{members?.ok === false && members.error.message}</p>
          )}

          {invitations?.ok && invitations.data.data.length > 0 && (
            <div className="mt-8">
              <h3 className="text-base font-semibold">Pending invitations</h3>
              <ul className="mt-3 divide-y divide-rule border-y border-rule text-sm">
                {invitations.data.data.map((i) => (
                  <li key={i.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                    <span>
                      {i.email} <span className="text-muted">as {i.role_label}, expires {new Date(i.expires_at).toLocaleDateString("en-US", { dateStyle: "medium" })}</span>
                    </span>
                    {canManage && (
                      <form action={revokeInvitationAction}>
                        <input type="hidden" name="id" value={i.id} />
                        <button type="submit" className="text-sm text-caution underline-offset-4 hover:underline">
                          Revoke
                        </button>
                      </form>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          )}

          {canManage && <InviteForm roles={ROLES} departments={departmentList} />}
        </section>
      )}

      <section aria-labelledby="departments-heading">
        <h2 id="departments-heading" className="text-lg font-semibold">
          Departments
        </h2>
        <p className="mt-2 text-sm text-muted">Compliance tasks are routed to departments and their managers.</p>
        {departmentList.length > 0 ? (
          <ul className="mt-4 flex flex-wrap gap-2 text-sm">
            {departmentList.map((d) => (
              <li key={d.id} className="rounded-md border border-rule px-3 py-1.5">
                {d.name}
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-4 text-sm text-muted">No departments yet.</p>
        )}
        {can(org, "departments.manage") && <CreateDepartmentForm />}
      </section>

      <section aria-labelledby="security-heading">
        <h2 id="security-heading" className="text-lg font-semibold">
          Your security
        </h2>
        <p className="mt-2 text-sm text-muted">
          Two-factor login is {me.user.mfa_enabled ? "on" : "off"} for {me.user.email}.{" "}
          <Link href="/security/two-factor" className="text-signal underline-offset-4 hover:underline">
            Manage two-factor login
          </Link>
        </p>
      </section>
    </div>
  );
}
