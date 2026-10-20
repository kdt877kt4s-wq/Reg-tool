import { apiRequest, getTaxonomy, type Profile } from "@/lib/api";
import { can, requireOrganization } from "@/lib/auth";
import { ProfileForm } from "./profile-form";

export const metadata = { title: "Regulatory profile" };

export default async function ProfilePage({ searchParams }: { searchParams: Promise<{ welcome?: string }> }) {
  const [{ org }, taxonomy, res, { welcome }] = await Promise.all([
    requireOrganization(),
    getTaxonomy(),
    apiRequest<{ profile: Profile }>("/organization/profile"),
    searchParams,
  ]);

  if (!res.ok) {
    return <p role="alert">{res.error.message}</p>;
  }

  const editable = can(org, "profile.edit");
  const terms = (kind: string) => taxonomy.filter((t) => t.kind === kind);

  return (
    <div className="max-w-4xl">
      <h1 className="text-2xl font-semibold tracking-tight">Regulatory profile</h1>
      <p className="mt-3 max-w-[66ch] text-muted">
        {welcome
          ? "Last step. Tell RuleRadar what your organization does and where. It uses these facts to flag which new regulations may apply, and shows you why."
          : "The facts RuleRadar uses to judge which regulations may apply to your organization. Every save is kept as a version."}
      </p>
      {res.data.profile.version > 0 && (
        <p className="mt-2 text-sm text-muted">
          Version {res.data.profile.version}
          {res.data.profile.updated_at && `, saved ${new Date(res.data.profile.updated_at).toLocaleDateString("en-US", { dateStyle: "medium" })}`}
        </p>
      )}

      <ProfileForm
        profile={res.data.profile}
        editable={editable}
        welcome={Boolean(welcome)}
        options={{
          org_types: terms("org_type"),
          programs: terms("program"),
          functions: terms("function"),
          states: terms("state"),
        }}
      />
    </div>
  );
}
