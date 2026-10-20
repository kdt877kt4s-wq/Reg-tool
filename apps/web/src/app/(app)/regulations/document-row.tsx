import Link from "next/link";
import type { RegulatoryDocumentSummary } from "@/lib/api";
import { commentDeadlineNote, daysUntil, formatDate } from "@/lib/format";

/** The issuing agency to show: the most specific one, not the parent department. */
export function primaryAgency(doc: Pick<RegulatoryDocumentSummary, "agencies">): string | null {
  const specific = doc.agencies.find((a) => a.slug !== "health-and-human-services-department");
  return (specific ?? doc.agencies[0])?.name ?? null;
}

export function DocumentRow({ doc }: { doc: RegulatoryDocumentSummary }) {
  const comments = commentDeadlineNote(doc.comments_close_date);
  const commentsOpen = doc.comments_close_date !== null && daysUntil(doc.comments_close_date) >= 0;

  return (
    <li className="py-5">
      <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-xs text-muted">
        <span className="font-medium text-ink">{doc.type_label}</span>
        {primaryAgency(doc) && <span>{primaryAgency(doc)}</span>}
        {doc.citation && <span>{doc.citation}</span>}
        <span>Document {doc.document_number}</span>
      </div>

      <h2 className="mt-1.5 font-serif text-lg leading-snug">
        <Link href={`/regulations/${doc.id}`} className="hover:text-signal hover:underline hover:underline-offset-4">
          {doc.title}
        </Link>
      </h2>

      <dl className="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-sm">
        <div className="flex gap-1.5">
          <dt className="text-muted">Published</dt>
          <dd>{formatDate(doc.publication_date)}</dd>
        </div>
        <div className="flex gap-1.5">
          <dt className="text-muted">Effective</dt>
          <dd>{formatDate(doc.effective_date) ?? <span className="text-muted">not stated</span>}</dd>
        </div>
        {comments && (
          <div>
            <dt className="sr-only">Comment period</dt>
            <dd className={commentsOpen ? "font-medium" : "text-muted"}>{comments}</dd>
          </div>
        )}
      </dl>

      {(doc.profile_matches.length > 0 || doc.status !== "active" || doc.is_demo) && (
        <ul className="mt-2.5 flex flex-wrap gap-2 text-xs">
          {doc.profile_matches.length > 0 && (
            <li className="rounded-sm border border-signal/40 bg-signal/5 px-2 py-0.5 text-ink">
              Amends {doc.profile_matches.join(", ")}, linked to a program in your profile
            </li>
          )}
          {doc.status === "corrected" && <li className="rounded-sm border border-caution/40 px-2 py-0.5 text-caution">A correction was published</li>}
          {doc.status === "withdrawn" && <li className="rounded-sm border border-caution/40 px-2 py-0.5 text-caution">Withdrawn</li>}
          {doc.is_demo && <li className="rounded-sm border border-rule px-2 py-0.5">Demonstration data</li>}
        </ul>
      )}
    </li>
  );
}
