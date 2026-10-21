import Link from "next/link";
import { notFound } from "next/navigation";
import { buttonClass } from "@/components/ui/button-styles";
import { inputClass } from "@/components/ui/field";
import { apiRequest, type Assignees, type TaskDetail } from "@/lib/api";
import { dueNote, formatDate, formatDateTime } from "@/lib/format";
import { PRIORITY_LABELS, eventLabel, fileSize, pageLabel } from "@/lib/labels";
import { DeadlineBadge, StatusBadge } from "../task-row";
import { TaskForm } from "../task-form";
import { TaskControls } from "./task-controls";

export const metadata = { title: "Task" };

type Params = { created?: string; edit?: string; evidence?: string; evidence_error?: string };

export default async function TaskPage({ params, searchParams }: { params: Promise<{ id: string }>; searchParams: Promise<Params> }) {
  const [{ id }, query] = await Promise.all([params, searchParams]);
  if (!/^\d+$/.test(id)) notFound();

  const res = await apiRequest<{ data: TaskDetail }>(`/tasks/${id}`);
  if (!res.ok) {
    if (res.error.status === 404) notFound();
    return (
      <p role="alert" className="border-l-2 border-caution pl-3 text-sm">
        {res.error.message}
      </p>
    );
  }
  const task = res.data.data;
  const can = (a: TaskDetail["allowed_actions"][number]) => task.allowed_actions.includes(a);
  const editing = query.edit === "1" && can("edit");
  const assignees = editing ? await apiRequest<Assignees>("/tasks/assignees") : null;
  const active = !["done", "cancelled"].includes(task.status);

  return (
    <div className="max-w-4xl">
      <p className="text-sm">
        <Link href="/tasks" className="text-signal underline-offset-4 hover:underline">
          Compliance tasks
        </Link>
      </p>

      {query.created && (
        <p role="status" className="mt-4 border-l-2 border-signal pl-3 text-sm">
          Task created{task.owner ? ` and ${task.owner.name} was notified` : ""}.
        </p>
      )}

      <header className="mt-3">
        <div className="flex flex-wrap items-center gap-2">
          <StatusBadge status={task.status} />
          <DeadlineBadge type={task.due_date ? task.due_date_type : null} />
          <span className="text-xs text-muted">{PRIORITY_LABELS[task.priority]} priority</span>
        </div>
        <h1 className="mt-2 text-2xl font-semibold leading-snug tracking-tight">{task.title}</h1>
      </header>

      {task.status_note && (task.status === "returned" || task.status === "cancelled") && (
        <p className="mt-4 max-w-[66ch] border-l-2 border-caution pl-3 text-sm">
          <span className="font-medium">{task.status === "returned" ? "Returned: " : "Cancelled: "}</span>
          {task.status_note}
        </p>
      )}

      <dl className="mt-6 grid gap-x-8 gap-y-4 border-y border-rule py-5 text-sm sm:grid-cols-3">
        <div>
          <dt className="text-muted">Owner</dt>
          <dd className="mt-0.5 font-medium">{task.owner?.name ?? <span className="text-caution">Unassigned</span>}</dd>
        </div>
        <div>
          <dt className="text-muted">Department</dt>
          <dd className="mt-0.5 font-medium">{task.department?.name ?? "None"}</dd>
        </div>
        <div>
          <dt className="text-muted">Due</dt>
          <dd className="mt-0.5">
            {task.due_date ? (
              <>
                <span className="font-medium">{formatDate(task.due_date)}</span>
                {active && <span className={task.overdue ? "ml-2 text-caution" : "ml-2 text-muted"}>{dueNote(task.due_date)}</span>}
                {task.due_date_basis && <span className="mt-0.5 block text-xs text-muted">{task.due_date_basis}</span>}
              </>
            ) : (
              "No due date"
            )}
          </dd>
        </div>
      </dl>

      {editing && assignees ? (
        <section aria-labelledby="edit-heading" className="mt-8">
          <div className="flex items-baseline justify-between">
            <h2 id="edit-heading" className="text-lg font-semibold">
              Edit task
            </h2>
            <Link href={`/tasks/${task.id}`} className="text-sm text-signal underline-offset-4 hover:underline">
              Done editing
            </Link>
          </div>
          <div className="mt-4">
            {assignees.ok ? <TaskForm task={task} assignees={assignees.data} /> : <p role="alert">{assignees.error.message}</p>}
          </div>
        </section>
      ) : (
        <>
          <div className="mt-6 flex flex-wrap items-start justify-between gap-4">
            <TaskControls taskId={task.id} allowed={task.allowed_actions} hasEvidence={task.evidence.length > 0} />
            {can("edit") && (
              <Link href={`/tasks/${task.id}?edit=1`} className={buttonClass("secondary")}>
                Edit or reassign
              </Link>
            )}
          </div>

          {task.description && (
            <section aria-labelledby="desc-heading" className="mt-8">
              <h2 id="desc-heading" className="text-sm font-semibold uppercase tracking-wide text-muted">
                What needs to be done
              </h2>
              <p className="mt-2 max-w-[66ch] whitespace-pre-line text-[15px] leading-relaxed">{task.description}</p>
            </section>
          )}
        </>
      )}

      {task.document && (
        <section aria-labelledby="source-heading" className="mt-8 rounded-md border border-rule p-5">
          <h2 id="source-heading" className="text-sm font-semibold uppercase tracking-wide text-muted">
            Where this comes from
          </h2>
          <p className="mt-2 font-serif text-base leading-snug">
            <Link href={`/regulations/${task.document.id}`} className="hover:text-signal hover:underline hover:underline-offset-4">
              {task.document.title}
            </Link>
          </p>
          <p className="mt-1 text-xs text-muted">
            FR document {task.document.document_number}
            {task.document_citation && `, ${task.document_citation}`}
            {task.from_action && " · From an accepted action in the approved impact assessment"}
          </p>
          {task.citations.length > 0 && (
            <ul className="mt-3 space-y-1 text-sm">
              {task.citations.map((c) => (
                <li key={c.id}>
                  <Link href={`/regulations/${task.document!.id}?around=${c.id}#passage-${c.id}`} className="text-signal underline-offset-4 hover:underline">
                    {c.heading_path ?? "Cited passage"}
                  </Link>
                  {pageLabel(c.page_start, c.page_end) && <span className="text-muted">, {pageLabel(c.page_start, c.page_end)}</span>}
                </li>
              ))}
            </ul>
          )}
        </section>
      )}

      <section id="evidence" aria-labelledby="evidence-heading" className="mt-10 scroll-mt-8">
        <h2 id="evidence-heading" className="text-lg font-semibold">
          Evidence <span className="font-normal text-muted">({task.evidence.length})</span>
        </h2>
        {query.evidence === "added" && (
          <p role="status" className="mt-3 border-l-2 border-signal pl-3 text-sm">
            Evidence added.
          </p>
        )}
        {query.evidence_error && (
          <p role="alert" className="mt-3 border-l-2 border-caution pl-3 text-sm">
            {query.evidence_error}
          </p>
        )}

        {task.evidence.length === 0 ? (
          <p className="mt-3 text-sm text-muted">No evidence yet. A task needs at least one file or note before it can be submitted.</p>
        ) : (
          <ol className="mt-4 divide-y divide-rule border-y border-rule">
            {task.evidence.map((e) => (
              <li key={e.id} className="py-4 text-sm">
                {e.file && (
                  <p>
                    <a href={`/api/files/${e.file.id}`} className="font-medium text-signal underline-offset-4 hover:underline">
                      {e.file.filename}
                    </a>
                    <span className="ml-2 text-xs text-muted">
                      {fileSize(e.file.size)}
                      {e.file.scan_status === "not_scanned" && " · Not scanned for malware"}
                      {e.file.scan_status === "clean" && " · Scanned, clean"}
                      {e.file.scan_status === "infected" && " · Blocked: failed malware scan"}
                    </span>
                  </p>
                )}
                {e.note && <p className={e.file ? "mt-1 whitespace-pre-line" : "whitespace-pre-line"}>{e.note}</p>}
                <p className="mt-1 text-xs text-muted">
                  {e.submitted_by}, {formatDateTime(e.created_at)}
                  {e.file && (
                    <>
                      {" · "}
                      <span title={e.file.sha256} className="font-mono">
                        SHA-256 {e.file.sha256.slice(0, 12)}…
                      </span>
                    </>
                  )}
                </p>
              </li>
            ))}
          </ol>
        )}

        {can("add_evidence") && (
          <form
            action={`/api/tasks/${task.id}/evidence`}
            method="post"
            encType="multipart/form-data"
            className="mt-6 max-w-xl space-y-4 rounded-md border border-rule p-4"
          >
            <h3 className="text-sm font-semibold">Add evidence</h3>
            <div className="space-y-1.5">
              <label htmlFor="evidence-file" className="block text-sm font-medium">
                File
              </label>
              <input
                id="evidence-file"
                type="file"
                name="file"
                accept=".pdf,.docx,.doc,.xlsx,.xls,.csv,.pptx,.txt,.png,.jpg,.jpeg,.eml,.msg,.zip"
                aria-describedby="evidence-file-hint"
                className="block w-full text-sm file:mr-3 file:rounded-md file:border file:border-rule file:bg-paper file:px-3 file:py-1.5 file:text-sm"
              />
              <p id="evidence-file-hint" className="text-xs text-muted">
                Up to 25 MB: PDF, Office documents, CSV, text, images, email or ZIP. Do not upload patient information.
              </p>
            </div>
            <div className="space-y-1.5">
              <label htmlFor="evidence-note" className="block text-sm font-medium">
                Note
              </label>
              <textarea id="evidence-note" name="note" rows={3} maxLength={5000} className={inputClass} placeholder="What this shows, or where the record lives." />
            </div>
            <button type="submit" className={buttonClass("secondary")}>
              Add evidence
            </button>
          </form>
        )}
      </section>

      <section aria-labelledby="history-heading" className="mt-12">
        <h2 id="history-heading" className="text-lg font-semibold">
          History
        </h2>
        <ol className="mt-4 space-y-3 border-l border-rule pl-5 text-sm">
          {task.events.map((e, i) => (
            <li key={i}>
              <span className="font-medium">{eventLabel(e.type)}</span>
              {e.actor && <span className="text-muted"> by {e.actor}</span>}
              <span className="text-muted">, {formatDateTime(e.created_at)}</span>
              {typeof e.details?.reason === "string" && <span className="mt-0.5 block text-muted">“{e.details.reason}”</span>}
            </li>
          ))}
        </ol>
      </section>
    </div>
  );
}
