import Link from "next/link";
import { apiRequest, type CalendarEntry } from "@/lib/api";
import { formatDate, monthGrid, parseMonth, shiftMonth, todayIso } from "@/lib/format";
import { DEADLINE_KIND_LABELS } from "@/lib/labels";
import { cn } from "@/lib/utils";

export const metadata = { title: "Regulatory calendar" };

const WEEKDAYS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];

function entryHref(e: CalendarEntry): string {
  return e.task ? `/tasks/${e.task.id}` : `/regulations/${e.document!.id}`;
}

function EntryChip({ e }: { e: CalendarEntry }) {
  return (
    <Link
      href={entryHref(e)}
      title={`${DEADLINE_KIND_LABELS[e.kind]}: ${e.title}`}
      className={cn(
        "block truncate rounded-sm px-1.5 py-0.5 text-[11px] leading-tight",
        e.deadline_type === "official" ? "bg-ink text-white hover:bg-ink/85" : "border border-ink/40 hover:border-ink",
        e.task?.overdue && "border-caution text-caution",
      )}
    >
      <span className="font-medium">{e.kind === "task_due" ? "Task" : e.kind === "effective" ? "Effective" : "Comments"}</span> {e.title}
    </Link>
  );
}

export default async function CalendarPage({ searchParams }: { searchParams: Promise<{ month?: string }> }) {
  const params = await searchParams;
  const { year, month } = parseMonth(params.month);
  const weeks = monthGrid(year, month);
  const from = weeks[0]![0]!;
  const to = weeks.at(-1)!.at(-1)!;
  const current = `${year}-${String(month).padStart(2, "0")}`;
  const thisMonth = todayIso().slice(0, 7);
  const today = todayIso();

  const res = await apiRequest<{ data: CalendarEntry[] }>(`/calendar?from=${from}&to=${to}`);
  const entries = res.ok ? res.data.data : [];
  const byDay = new Map<string, CalendarEntry[]>();
  for (const e of entries) byDay.set(e.date, [...(byDay.get(e.date) ?? []), e]);
  const inMonth = entries.filter((e) => e.date.startsWith(current));
  const title = new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString("en-US", { timeZone: "UTC", month: "long", year: "numeric" });

  return (
    <div className="max-w-6xl">
      <h1 className="text-2xl font-semibold tracking-tight">Regulatory calendar</h1>
      <p className="mt-2 max-w-[70ch] text-muted">
        Effective and comment-close dates published in the Federal Register for publications you assessed or that match
        your profile, plus task due dates. A publication without a published date has no entry; RuleRadar does not
        estimate dates.
      </p>

      <div className="mt-6 flex flex-wrap items-center justify-between gap-4">
        <div className="flex items-center gap-4">
          <Link href={`/calendar?month=${shiftMonth(year, month, -1)}`} className="rounded-md border border-rule px-2.5 py-1 text-sm hover:border-ink/40" aria-label="Previous month">
            ←
          </Link>
          <h2 className="min-w-40 text-center text-lg font-semibold">{title}</h2>
          <Link href={`/calendar?month=${shiftMonth(year, month, 1)}`} className="rounded-md border border-rule px-2.5 py-1 text-sm hover:border-ink/40" aria-label="Next month">
            →
          </Link>
          {current !== thisMonth && (
            <Link href="/calendar" className="text-sm text-signal underline-offset-4 hover:underline">
              This month
            </Link>
          )}
        </div>
        <ul className="flex flex-wrap gap-4 text-xs" aria-label="Legend">
          <li className="flex items-center gap-1.5">
            <span className="inline-block h-3 w-5 rounded-sm bg-ink" aria-hidden /> Official date (published or set by a regulation)
          </li>
          <li className="flex items-center gap-1.5">
            <span className="inline-block h-3 w-5 rounded-sm border border-ink/40" aria-hidden /> Internal target
          </li>
        </ul>
      </div>

      {!res.ok && (
        <p role="alert" className="mt-6 border-l-2 border-caution pl-3 text-sm">
          {res.error.message}
        </p>
      )}

      {/* Month grid: a visual overview. The list below carries the same entries for screen readers and small screens. */}
      <div aria-hidden className="mt-6 hidden overflow-hidden rounded-md border border-rule md:block">
        <div className="grid grid-cols-7 border-b border-rule bg-ink/[0.03] text-xs text-muted">
          {WEEKDAYS.map((d) => (
            <div key={d} className="px-2 py-1.5">
              {d}
            </div>
          ))}
        </div>
        {weeks.map((week) => (
          <div key={week[0]} className="grid grid-cols-7 border-b border-rule last:border-b-0">
            {week.map((day) => {
              const items = byDay.get(day) ?? [];
              const outside = !day.startsWith(current);
              return (
                <div key={day} className={cn("min-h-24 space-y-1 border-r border-rule p-1.5 last:border-r-0", outside && "bg-ink/[0.02]")}>
                  <p className={cn("text-xs tabular-nums", outside ? "text-muted/60" : "text-muted", day === today && "font-semibold text-signal")}>
                    {Number(day.slice(8))}
                  </p>
                  {items.slice(0, 3).map((e) => (
                    <EntryChip key={e.id} e={e} />
                  ))}
                  {items.length > 3 && <p className="text-[11px] text-muted">+{items.length - 3} more</p>}
                </div>
              );
            })}
          </div>
        ))}
      </div>

      <section aria-labelledby="agenda-heading" className="mt-10">
        <h2 id="agenda-heading" className="text-lg font-semibold">
          {title}: {inMonth.length} {inMonth.length === 1 ? "date" : "dates"}
        </h2>
        {inMonth.length === 0 ? (
          <p className="mt-3 text-sm text-muted">Nothing falls in this month.</p>
        ) : (
          <ol className="mt-4 divide-y divide-rule border-y border-rule">
            {inMonth.map((e) => (
              <li key={e.id} className="grid gap-1 py-3 text-sm sm:grid-cols-[8rem_10rem_1fr] sm:gap-4">
                <span className={cn("tabular-nums", e.date < today && !e.task ? "text-muted" : "font-medium")}>{formatDate(e.date)}</span>
                <span>
                  <span
                    className={cn(
                      "rounded-sm px-1.5 py-0.5 text-[11px] font-medium",
                      e.deadline_type === "official" ? "bg-ink text-white" : "border border-ink/40",
                    )}
                  >
                    {DEADLINE_KIND_LABELS[e.kind]}
                  </span>
                  <span className="sr-only">{e.deadline_type === "official" ? ", official" : ", internal target"}</span>
                </span>
                <span className="min-w-0">
                  <Link href={entryHref(e)} className={cn("hover:text-signal hover:underline hover:underline-offset-4", !e.task && "font-serif")}>
                    {e.title}
                  </Link>
                  <span className="block text-xs text-muted">
                    {e.task
                      ? [e.task.owner ?? "Unassigned", e.task.overdue ? "overdue" : null, e.basis].filter(Boolean).join(" · ")
                      : [e.basis, e.assessed ? "assessed" : null, e.profile_match ? "matches your profile" : null].filter(Boolean).join(" · ")}
                  </span>
                </span>
              </li>
            ))}
          </ol>
        )}
      </section>
    </div>
  );
}
