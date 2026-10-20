import Link from "next/link";
import { notFound } from "next/navigation";
import { inputClass } from "@/components/ui/field";
import { buttonClass } from "@/components/ui/button";
import { apiRequest, type PassageList, type RegulatoryDocumentDetail } from "@/lib/api";
import { commentDeadlineNote, formatDate, formatDateTime, snippetParts } from "@/lib/format";
import { primaryAgency } from "../document-row";

const FIELD_LABELS: Record<string, string> = {
  effective_date: "Effective date",
  comments_close_date: "Comment deadline",
  publication_date: "Publication date",
  title: "Title",
  abstract: "Summary",
  action: "Action",
  cfr_parts: "CFR parts",
  citation: "Citation",
  official_pdf_url: "Official PDF",
  dates_text: "Dates statement",
};

export async function generateMetadata({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const res = await apiRequest<{ data: RegulatoryDocumentDetail }>(`/regulatory-documents/${Number(id)}`);
  return { title: res.ok ? res.data.data.title.slice(0, 80) : "Publication" };
}

export default async function DocumentPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ q?: string; page?: string }>;
}) {
  const { id } = await params;
  const { q, page } = await searchParams;
  const documentId = Number(id);
  if (!Number.isInteger(documentId) || documentId < 1) notFound();

  const passageQuery = new URLSearchParams();
  if (q) passageQuery.set("q", q);
  if (page) passageQuery.set("page", page);

  const [res, passages] = await Promise.all([
    apiRequest<{ data: RegulatoryDocumentDetail }>(`/regulatory-documents/${documentId}`),
    apiRequest<PassageList>(`/regulatory-documents/${documentId}/passages?${passageQuery}`),
  ]);
  if (!res.ok) {
    if (res.error.status === 404) notFound();
    throw new Error(res.error.message);
  }
  const doc = res.data.data;
  const correctedBy = doc.relations.filter((r) => r.direction === "incoming");
  const corrects = doc.relations.filter((r) => r.direction === "outgoing");

  return (
    <article className="max-w-4xl">
      <p className="text-sm">
        <Link href="/regulations" className="text-signal underline-offset-4 hover:underline">
          Regulatory intelligence
        </Link>
      </p>

      <header className="mt-4">
        <p className="flex flex-wrap gap-x-3 text-sm text-muted">
          <span className="font-medium text-ink">{doc.type_label}</span>
          {primaryAgency(doc) && <span>{primaryAgency(doc)}</span>}
          {doc.action && <span>{doc.action}</span>}
        </p>
        <h1 className="mt-2 font-serif text-2xl leading-snug md:text-3xl">{doc.title}</h1>
        <p className="mt-2 text-sm text-muted">
          {doc.citation ? `${doc.citation}, ` : ""}Federal Register document {doc.document_number}
        </p>
      </header>

      {correctedBy.length > 0 && (
        <div role="note" className="mt-6 border-l-2 border-caution pl-4 text-sm">
          {correctedBy.map((r) => (
            <p key={`${r.relation}-${r.document_number}`}>
              {r.relation === "withdraws" ? "Withdrawn by " : "Corrected by "}
              {r.document_id ? (
                <Link href={`/regulations/${r.document_id}`} className="font-medium text-signal underline-offset-4 hover:underline">
                  document {r.document_number}
                </Link>
              ) : (
                <>document {r.document_number}</>
              )}
              . Read it alongside this document.
            </p>
          ))}
        </div>
      )}

      <section aria-labelledby="sources-heading" className="mt-8 rounded-md border border-rule p-5">
        <h2 id="sources-heading" className="text-base font-semibold">
          Official sources
        </h2>
        <ul className="mt-3 space-y-2 text-sm">
          {doc.official_pdf_url && (
            <li>
              <a href={doc.official_pdf_url} target="_blank" rel="noopener noreferrer" className="font-medium text-signal underline-offset-4 hover:underline">
                Official edition (GovInfo PDF)
              </a>
              <span className="text-muted"> The legally official text.</span>
            </li>
          )}
          {doc.html_url && (
            <li>
              <a href={doc.html_url} target="_blank" rel="noopener noreferrer" className="text-signal underline-offset-4 hover:underline">
                FederalRegister.gov page
              </a>
              <span className="text-muted"> A readable rendition; not the official edition.</span>
            </li>
          )}
        </ul>
        <p className="mt-3 text-xs text-muted">
          Retrieved from the {doc.source.name} {formatDateTime(doc.retrieved_at)}. Version {doc.version}.
        </p>
      </section>

      <section aria-labelledby="dates-heading" className="mt-10">
        <h2 id="dates-heading" className="text-lg font-semibold">
          Dates
        </h2>
        <dl className="mt-3 divide-y divide-rule border-y border-rule text-sm">
          <Row label="Published">{formatDate(doc.publication_date)}</Row>
          <Row label="Effective">{formatDate(doc.effective_date) ?? <span className="text-muted">Not stated in the source</span>}</Row>
          <Row label="Comment period">{commentDeadlineNote(doc.comments_close_date) ?? <span className="text-muted">No comment deadline stated</span>}</Row>
          {doc.dates_text && <Row label="As published">{doc.dates_text}</Row>}
        </dl>
      </section>

      <section aria-labelledby="scope-heading" className="mt-10">
        <h2 id="scope-heading" className="text-lg font-semibold">
          What it touches
        </h2>
        <dl className="mt-3 divide-y divide-rule border-y border-rule text-sm">
          <Row label="CFR parts">
            {doc.cfr_parts.length === 0 ? (
              <span className="text-muted">None listed</span>
            ) : (
              <ul className="flex flex-wrap gap-2">
                {doc.cfr_parts.map((part) => (
                  <li
                    key={part}
                    className={doc.profile_matches.includes(part) ? "rounded-sm border border-signal/40 bg-signal/5 px-1.5" : "px-0"}
                  >
                    {part}
                  </li>
                ))}
              </ul>
            )}
          </Row>
          <Row label="Agencies">{doc.agencies.map((a) => a.name).join(", ")}</Row>
          {doc.docket_ids.length > 0 && <Row label="Dockets">{doc.docket_ids.join(", ")}</Row>}
          {doc.rins.length > 0 && <Row label="RIN">{doc.rins.join(", ")}</Row>}
        </dl>
        {doc.profile_matches.length > 0 && (
          <p className="mt-3 max-w-[66ch] text-sm text-muted">
            Highlighted parts are linked to programs in your regulatory profile. That is a reason to review the
            document, not a finding that it applies to your organization.
          </p>
        )}
      </section>

      {doc.abstract && (
        <section aria-labelledby="summary-heading" className="mt-10">
          <h2 id="summary-heading" className="text-lg font-semibold">
            Summary as published
          </h2>
          <blockquote className="mt-3 border-l-2 border-ink/20 pl-4 font-serif text-[1.05rem] leading-relaxed">{doc.abstract}</blockquote>
          <p className="mt-2 text-xs text-muted">Text from the Federal Register, unchanged.</p>
        </section>
      )}

      {corrects.length > 0 && (
        <section aria-labelledby="relations-heading" className="mt-10">
          <h2 id="relations-heading" className="text-lg font-semibold">
            Earlier document
          </h2>
          <ul className="mt-3 space-y-2 text-sm">
            {corrects.map((r) => (
              <li key={r.document_number}>
                This document {r.relation === "withdraws" ? "withdraws" : "corrects"}{" "}
                {r.document_id ? (
                  <Link href={`/regulations/${r.document_id}`} className="text-signal underline-offset-4 hover:underline">
                    {r.title ?? `document ${r.document_number}`}
                  </Link>
                ) : (
                  <>document {r.document_number}, not yet retrieved by RuleRadar</>
                )}
                .
                <span className="block text-xs text-muted">
                  {r.method === "parsed_from_text"
                    ? `Identified from the reference "FR Doc. ${r.document_number}" in this document's text.`
                    : "Identified from the Federal Register's own correction record."}
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}

      {doc.versions.length > 1 && (
        <section aria-labelledby="versions-heading" className="mt-10">
          <h2 id="versions-heading" className="text-lg font-semibold">
            Changes since first retrieved
          </h2>
          <ol className="mt-3 divide-y divide-rule border-y border-rule text-sm">
            {doc.versions.map((v) => (
              <li key={v.version} className="py-3">
                <p>
                  <span className="font-medium">Version {v.version}</span>
                  <span className="text-muted">, retrieved {formatDateTime(v.retrieved_at)}</span>
                </p>
                {v.changes && Object.keys(v.changes).length > 0 && (
                  <ul className="mt-1 space-y-0.5 text-muted">
                    {Object.entries(v.changes).map(([field, change]) => (
                      <li key={field}>
                        {FIELD_LABELS[field] ?? field}: {display(change.from)} changed to {display(change.to)}
                      </li>
                    ))}
                  </ul>
                )}
              </li>
            ))}
          </ol>
        </section>
      )}

      <section aria-labelledby="text-heading" className="mt-12">
        <h2 id="text-heading" className="text-lg font-semibold">
          Full text
        </h2>
        {doc.text_status === "pending" && <p className="mt-3 text-sm text-muted">The full text is being retrieved. Check back shortly.</p>}
        {doc.text_status === "unavailable" && (
          <p className="mt-3 text-sm text-muted">The Federal Register does not offer plain text for this document. Read the official PDF.</p>
        )}
        {doc.text_status === "available" && (
          <>
            <form method="get" className="mt-3 flex max-w-xl gap-2" role="search">
              <label htmlFor="text-q" className="sr-only">
                Search this document
              </label>
              <input id="text-q" name="q" defaultValue={q} placeholder="Search this document" className={inputClass} />
              <button type="submit" className={buttonClass("secondary")}>
                Search
              </button>
            </form>
            {q && passages.ok && (
              <p className="mt-3 text-sm text-muted">
                {passages.data.meta.total} {passages.data.meta.total === 1 ? "passage matches" : "passages match"} &ldquo;{q}&rdquo;.{" "}
                <Link href={`/regulations/${doc.id}#text-heading`} className="text-signal underline-offset-4 hover:underline">
                  Show all text
                </Link>
              </p>
            )}
            {passages.ok ? (
              <ol className="mt-6 space-y-8">
                {passages.data.data.map((p) => (
                  <li key={p.id} id={`passage-${p.id}`} className="scroll-mt-8">
                    <p className="text-xs text-muted">
                      {p.heading_path && <span className="font-medium text-ink">{p.heading_path}</span>}
                      {p.page_start && (
                        <span>
                          {p.heading_path ? ", " : ""}page {p.page_start}
                          {p.page_end && p.page_end !== p.page_start ? `–${p.page_end}` : ""}
                        </span>
                      )}
                    </p>
                    <div className="mt-1.5 max-w-[72ch] whitespace-pre-line font-serif leading-relaxed">
                      {p.snippet
                        ? snippetParts(p.snippet).map((part, i) =>
                            part.match ? (
                              <mark key={i} className="bg-signal/15 text-ink">
                                {part.text}
                              </mark>
                            ) : (
                              <span key={i}>{part.text}</span>
                            ),
                          )
                        : p.text}
                    </div>
                  </li>
                ))}
              </ol>
            ) : (
              <p role="alert" className="mt-4 text-sm">
                {passages.error.message}
              </p>
            )}
            {passages.ok && passages.data.meta.last_page > 1 && (
              <nav aria-label="Text pages" className="mt-8 flex justify-between text-sm">
                {passages.data.meta.page > 1 ? (
                  <Link href={`?${new URLSearchParams({ ...(q ? { q } : {}), page: String(passages.data.meta.page - 1) })}`} className="text-signal underline-offset-4 hover:underline">
                    Previous part
                  </Link>
                ) : (
                  <span />
                )}
                <span className="text-muted">
                  Part {passages.data.meta.page} of {passages.data.meta.last_page}
                </span>
                {passages.data.meta.page < passages.data.meta.last_page ? (
                  <Link href={`?${new URLSearchParams({ ...(q ? { q } : {}), page: String(passages.data.meta.page + 1) })}`} className="text-signal underline-offset-4 hover:underline">
                    Next part
                  </Link>
                ) : (
                  <span />
                )}
              </nav>
            )}
          </>
        )}
      </section>
    </article>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="grid gap-1 py-3 sm:grid-cols-[10rem_1fr] sm:gap-4">
      <dt className="text-muted">{label}</dt>
      <dd>{children}</dd>
    </div>
  );
}

function display(value: unknown): string {
  if (value === null || value === undefined || value === "") return "(none)";
  if (Array.isArray(value)) return value.join(", ") || "(none)";
  if (typeof value === "string" && /^\d{4}-\d{2}-\d{2}$/.test(value)) return formatDate(value) ?? value;
  return String(value);
}
