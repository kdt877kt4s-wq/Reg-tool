import Link from "next/link";
import { PrintButton } from "@/components/print-button";
import { buttonClass } from "@/components/ui/button-styles";
import type { ReportMeta } from "@/lib/api";
import { formatDateTime } from "@/lib/format";

/** Title block shared by every report; on paper it says who generated it and when. */
export function ReportHeader({ title, subtitle, meta, csvHref }: { title: string; subtitle: string; meta: ReportMeta; csvHref?: string }) {
  return (
    <header className="border-b border-rule pb-6">
      <p className="text-sm print:hidden">
        <Link href="/reports" className="text-signal underline-offset-4 hover:underline">
          Reports
        </Link>
      </p>
      <p className="mt-3 text-xs uppercase tracking-wide text-muted">RuleRadar report · {meta.organization}</p>
      <h1 className="mt-1 text-2xl font-semibold tracking-tight">{title}</h1>
      <p className="mt-1 text-muted">{subtitle}</p>
      <p className="mt-3 text-xs text-muted">
        Generated {formatDateTime(meta.generated_at)}
        {meta.generated_by && ` by ${meta.generated_by}`}. {meta.notice}
      </p>
      <div className="mt-4 flex flex-wrap gap-3 print:hidden">
        <PrintButton />
        {csvHref && (
          <a href={csvHref} className={buttonClass("secondary")}>
            Download CSV
          </a>
        )}
      </div>
    </header>
  );
}

export function SummaryStats({ items }: { items: [string, number, boolean?][] }) {
  return (
    <dl className="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-md border border-rule bg-rule sm:grid-cols-3 lg:grid-cols-6">
      {items.map(([label, value, warn]) => (
        <div key={label} className="bg-paper p-3">
          <dt className="text-xs text-muted">{label}</dt>
          <dd className={warn && value > 0 ? "mt-1 text-xl font-semibold tabular-nums text-caution" : "mt-1 text-xl font-semibold tabular-nums"}>{value}</dd>
        </div>
      ))}
    </dl>
  );
}
