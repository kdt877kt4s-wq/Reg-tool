import Link from "next/link";
import { buttonClass } from "@/components/ui/button-styles";
import { apiRequest, type TaskSummary } from "@/lib/api";
import { cn } from "@/lib/utils";
import { TaskRow } from "./task-row";

export const metadata = { title: "Compliance tasks" };

const VIEWS = [
  { key: "mine", label: "Assigned to me" },
  { key: "visible", label: "All I can see" },
] as const;

const FILTERS = [
  { key: "active", label: "Active" },
  { key: "overdue", label: "Overdue" },
  { key: "submitted", label: "Waiting for review" },
  { key: "unassigned", label: "Unassigned" },
  { key: "done", label: "Done" },
  { key: "cancelled", label: "Cancelled" },
] as const;

type Params = { view?: string; filter?: string; document?: string };

function query(view: string, filter: string, document: string | null): string {
  const q = new URLSearchParams({ view });
  if (document) q.set("document_id", document);
  if (filter === "overdue") q.set("overdue", "1");
  else if (filter === "unassigned") q.set("unassigned", "1");
  else q.set("status", filter);
  return q.toString();
}

export default async function TasksPage({ searchParams }: { searchParams: Promise<Params> }) {
  const params = await searchParams;
  const view = VIEWS.some((v) => v.key === params.view) ? params.view! : "mine";
  const filter = FILTERS.some((f) => f.key === params.filter) ? params.filter! : "active";
  const document = params.document && /^\d+$/.test(params.document) ? params.document : null;
  const res = await apiRequest<{ data: TaskSummary[]; can_create: boolean }>(`/tasks?${query(view, filter, document)}`);

  const link = (next: Params) => {
    const q = new URLSearchParams({ view: next.view ?? view, filter: next.filter ?? filter });
    if (document) q.set("document", document);
    return `/tasks?${q.toString()}`;
  };

  return (
    <div className="max-w-5xl">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Compliance tasks</h1>
          <p className="mt-2 max-w-[66ch] text-muted">
            Work your organization committed to. Tasks come from approved impact assessments or are added by hand.
          </p>
        </div>
        {res.ok && res.data.can_create && (
          <Link href="/tasks/new" className={buttonClass("primary")}>
            New task
          </Link>
        )}
      </div>

      {document && (
        <p className="mt-4 text-sm">
          Showing tasks from one publication.{" "}
          <Link href={`/tasks?view=${view}&filter=${filter}`} className="text-signal underline-offset-4 hover:underline">
            Show all
          </Link>
        </p>
      )}

      <nav aria-label="Task views" className="mt-8 flex flex-wrap gap-x-6 gap-y-2 border-b border-rule text-sm">
        {VIEWS.map((v) => (
          <Link
            key={v.key}
            href={link({ view: v.key })}
            aria-current={v.key === view ? "page" : undefined}
            className={cn("-mb-px border-b-2 pb-2", v.key === view ? "border-ink font-medium" : "border-transparent text-muted hover:text-ink")}
          >
            {v.label}
          </Link>
        ))}
      </nav>
      <ul aria-label="Filter" className="mt-4 flex flex-wrap gap-2 text-xs">
        {FILTERS.map((f) => (
          <li key={f.key}>
            <Link
              href={link({ filter: f.key })}
              aria-current={f.key === filter ? "true" : undefined}
              className={cn(
                "inline-block rounded-full border px-3 py-1",
                f.key === filter ? "border-ink bg-ink text-white" : "border-rule hover:border-ink/40",
              )}
            >
              {f.label}
            </Link>
          </li>
        ))}
      </ul>

      {!res.ok ? (
        <p role="alert" className="mt-8 border-l-2 border-caution pl-3 text-sm">
          {res.error.message}
        </p>
      ) : res.data.data.length === 0 ? (
        <p className="mt-8 max-w-[62ch] text-sm text-muted">
          {view === "mine" && filter === "active"
            ? "Nothing is assigned to you right now."
            : "No tasks match this view."}{" "}
          Approving an impact assessment turns its accepted actions into tasks.
        </p>
      ) : (
        <ol className="mt-6 divide-y divide-rule border-y border-rule">
          {res.data.data.map((t) => (
            <TaskRow key={t.id} task={t} />
          ))}
        </ol>
      )}
    </div>
  );
}
