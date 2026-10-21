import Link from "next/link";
import { apiRequest, type Assignees, type RegulatoryDocumentSummary } from "@/lib/api";
import { TaskForm } from "../task-form";

export const metadata = { title: "New task" };

export default async function NewTaskPage({ searchParams }: { searchParams: Promise<{ document?: string }> }) {
  const { document } = await searchParams;
  const documentId = document && /^\d+$/.test(document) ? Number(document) : undefined;
  const [assignees, doc] = await Promise.all([
    apiRequest<Assignees>("/tasks/assignees"),
    documentId ? apiRequest<{ data: RegulatoryDocumentSummary }>(`/regulatory-documents/${documentId}`) : null,
  ]);

  return (
    <div className="max-w-3xl">
      <p className="text-sm">
        <Link href="/tasks" className="text-signal underline-offset-4 hover:underline">
          Compliance tasks
        </Link>
      </p>
      <h1 className="mt-2 text-2xl font-semibold tracking-tight">New task</h1>
      {doc?.ok && (
        <p className="mt-2 text-sm text-muted">
          Linked to FR {doc.data.data.document_number}: <span className="font-serif text-ink">{doc.data.data.title}</span>
        </p>
      )}

      <div className="mt-8">
        {assignees.ok ? (
          <TaskForm assignees={assignees.data} documentId={doc?.ok ? documentId : undefined} />
        ) : (
          <p role="alert" className="border-l-2 border-caution pl-3 text-sm">
            {assignees.error.status === 403 ? "Your role cannot create tasks. Ask a compliance officer or your department manager." : assignees.error.message}
          </p>
        )}
      </div>
    </div>
  );
}
