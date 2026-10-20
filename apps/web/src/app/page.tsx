import { AppShell } from "@/components/app-shell";
import { api, type Health, type TaxonomyTerm } from "@/lib/api";

export const dynamic = "force-dynamic";

const KIND_LABELS: Record<TaxonomyTerm["kind"], string> = {
  org_type: "Organization types",
  program: "Federal programs",
  function: "Operational functions",
  topic: "Regulatory topics",
  state: "States and territories",
};

export default async function OverviewPage() {
  const [health, taxonomy] = await Promise.all([api.health(), api.taxonomy()]);

  return (
    <AppShell activeHref="/">
      <div className="max-w-3xl">
        <h1 className="text-2xl font-semibold tracking-tight">Overview</h1>
        <p className="mt-3 max-w-[62ch] text-muted">
          RuleRadar is in its foundation stage. Regulatory monitoring starts with the
          Federal Register in Stage 2; until then this page reports the health of the
          platform itself.
        </p>

        <section aria-labelledby="system-heading" className="mt-12">
          <h2 id="system-heading" className="text-lg font-semibold">
            System status
          </h2>
          {health.ok ? (
            <HealthRows health={health.data} />
          ) : (
            <p role="alert" className="mt-4 border-l-2 border-caution pl-4 text-sm">
              {health.error} Start it with <code>php artisan serve</code> in{" "}
              <code>apps/api</code>, then reload this page.
            </p>
          )}
        </section>

        <section aria-labelledby="taxonomy-heading" className="mt-12">
          <h2 id="taxonomy-heading" className="text-lg font-semibold">
            Healthcare profile vocabulary
          </h2>
          <p className="mt-2 max-w-[62ch] text-sm text-muted">
            The terms organizations will choose from when they describe what they do.
            RuleRadar uses them to judge which regulations may be relevant.
          </p>
          {taxonomy.ok ? (
            <TaxonomyRows terms={taxonomy.data.data} />
          ) : (
            <p className="mt-4 text-sm text-muted">Unavailable: {taxonomy.error}</p>
          )}
        </section>
      </div>
    </AppShell>
  );
}

function HealthRows({ health }: { health: Health }) {
  const rows: [string, string, boolean][] = [
    ["API", `${health.status === "ok" ? "Running" : "Degraded"}, version ${health.version}`, health.status === "ok"],
    ...Object.entries(health.checks).map(
      ([name, check]): [string, string, boolean] => [
        name.charAt(0).toUpperCase() + name.slice(1),
        check.status === "ok" ? "Connected" : (check.detail ?? "Failed"),
        check.status === "ok",
      ],
    ),
  ];

  return (
    <dl className="mt-4 divide-y divide-rule border-y border-rule">
      {rows.map(([label, value, good]) => (
        <div key={label} className="flex justify-between gap-6 py-3 text-sm">
          <dt className="text-muted">{label}</dt>
          <dd className={good ? "font-medium" : "font-medium text-caution"}>{value}</dd>
        </div>
      ))}
      <div className="flex justify-between gap-6 py-3 text-sm">
        <dt className="text-muted">Checked</dt>
        <dd>
          <time dateTime={health.checked_at}>
            {new Date(health.checked_at).toLocaleString("en-US", { timeZone: "UTC", timeZoneName: "short" })}
          </time>
        </dd>
      </div>
    </dl>
  );
}

function TaxonomyRows({ terms }: { terms: TaxonomyTerm[] }) {
  if (terms.length === 0) {
    return (
      <p className="mt-4 text-sm text-muted">
        No terms yet. Run <code>php artisan db:seed</code> in <code>apps/api</code>.
      </p>
    );
  }

  const byKind = Object.entries(KIND_LABELS).map(([kind, label]) => ({
    label,
    count: terms.filter((t) => t.kind === kind).length,
  }));

  return (
    <dl className="mt-4 divide-y divide-rule border-y border-rule">
      {byKind.map(({ label, count }) => (
        <div key={label} className="flex justify-between gap-6 py-3 text-sm">
          <dt className="text-muted">{label}</dt>
          <dd className="font-medium tabular-nums">{count}</dd>
        </div>
      ))}
    </dl>
  );
}
