import Link from "next/link";
import type { TaskSummary } from "@/lib/api";
import { formatDate } from "@/lib/format";
import { PRIORITY_LABELS, TASK_STATUS_LABELS } from "@/lib/labels";
import { cn } from "@/lib/utils";

export function DeadlineBadge({ type }: { type: "official" | "internal" | null }) {
  if (!type) return null;
  return type === "official" ? (
    <span className="rounded-sm bg-ink px-1.5 py-0.5 text-[11px] font-medium text-white">Official deadline</span>
  ) : (
    <span className="rounded-sm border border-ink/40 px-1.5 py-0.5 text-[11px] font-medium">Internal target</span>
  );
}

export function StatusBadge({ status }: { status: TaskSummary["status"] }) {
  const tone =
    status === "returned"
      ? "border-caution/50 text-caution"
      : status === "submitted"
        ? "border-signal/50 text-signal"
        : status === "done"
          ? "border-ink/30 text-ink"
          : status === "cancelled"
            ? "border-rule text-muted line-through"
            : "border-rule";
  return <span className={cn("rounded-sm border px-1.5 py-0.5 text-[11px] font-medium", tone)}>{TASK_STATUS_LABELS[status]}</span>;
}

export function TaskRow({ task }: { task: TaskSummary }) {
  return (
    <li className="grid gap-2 py-4 sm:grid-cols-[1fr_auto] sm:items-start sm:gap-6">
      <div className="min-w-0">
        <h3 className="text-[15px] font-medium leading-snug">
          <Link href={`/tasks/${task.id}`} className="hover:text-signal hover:underline hover:underline-offset-4">
            {task.title}
          </Link>
        </h3>
        <p className="mt-1 text-xs text-muted">
          {task.owner ? task.owner.name : <span className="text-caution">Unassigned</span>}
          {task.department && `, ${task.department.name}`}
          {` · ${PRIORITY_LABELS[task.priority]} priority`}
          {task.document && ` · From FR ${task.document.document_number}`}
        </p>
      </div>
      <div className="flex flex-wrap items-center gap-2 sm:justify-end">
        <StatusBadge status={task.status} />
        {task.due_date && (
          <>
            <DeadlineBadge type={task.due_date_type} />
            <span className={cn("text-xs tabular-nums", task.overdue ? "font-medium text-caution" : "text-muted")}>
              {task.overdue ? "Overdue, was due " : "Due "}
              {formatDate(task.due_date)}
            </span>
          </>
        )}
      </div>
    </li>
  );
}
