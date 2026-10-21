import Link from "next/link";
import { SourceStatusLine } from "@/components/source-status";
import { inputClass } from "@/components/ui/field";
import { buttonClass } from "@/components/ui/button-styles";
import { apiRequest, type DocumentList, type Facets, type SourceStatus } from "@/lib/api";
import { DocumentRow } from "./document-row";

export const metadata = { title: "Regulatory intelligence" };

type Search = {
  q?: string;
  type?: string;
  agency?: string;
  relevance?: string;
  comments_open?: string;
  status?: string;
  from?: string;
  to?: string;
  page?: string;
};

const ALLOWED = ["q", "type", "agency", "relevance", "comments_open", "status", "from", "to", "page"] as const;

export default async function RegulationsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const params = await searchParams;
  const query = new URLSearchParams();
  for (const key of ALLOWED) {
    const value = params[key];
    if (value) query.set(key, key === "comments_open" ? "1" : value);
  }

  const [list, facets, sources] = await Promise.all([
    apiRequest<DocumentList>(`/regulatory-documents?${query}`),
    apiRequest<Facets>("/regulatory-documents/facets"),
    apiRequest<{ data: SourceStatus[] }>("/sources"),
  ]);

  const filtered = ALLOWED.some((k) => k !== "page" && params[k]);
  const pageHref = (page: number) => {
    const next = new URLSearchParams(query);
    next.set("page", String(page));
    return `/regulations?${next}`;
  };

  return (
    <div className="max-w-5xl">
      <h1 className="text-2xl font-semibold tracking-tight">Regulatory intelligence</h1>
      <p className="mt-2 max-w-[66ch] text-muted">
        Publications retrieved from the Federal Register for HHS, CMS and OIG. Each links to its official edition.
      </p>
      <div className="mt-4">{sources.ok && <SourceStatusLine sources={sources.data.data} />}</div>

      <form method="get" action="/regulations" className="mt-8 space-y-4 border-y border-rule py-5" role="search">
        <div className="flex flex-wrap gap-3">
          <label htmlFor="q" className="sr-only">
            Search
          </label>
          <input
            id="q"
            name="q"
            defaultValue={params.q}
            placeholder="Search titles and summaries, or enter a document number, citation, docket or RIN"
            className={`${inputClass} min-w-72 flex-1`}
          />
          <button type="submit" className={buttonClass("primary")}>
            Search
          </button>
        </div>
        <div className="flex flex-wrap items-end gap-x-5 gap-y-3 text-sm">
          <label className="space-y-1">
            <span className="block text-xs text-muted">Type</span>
            <select name="type" defaultValue={params.type ?? ""} className={`${inputClass} w-auto py-1.5`}>
              <option value="">All types</option>
              {facets.ok &&
                facets.data.types.map((t) => (
                  <option key={t.type} value={t.type}>
                    {t.label} ({t.documents})
                  </option>
                ))}
            </select>
          </label>
          <label className="space-y-1">
            <span className="block text-xs text-muted">Agency</span>
            <select name="agency" defaultValue={params.agency ?? ""} className={`${inputClass} w-auto max-w-72 py-1.5`}>
              <option value="">All agencies</option>
              {facets.ok &&
                facets.data.agencies.map((a) => (
                  <option key={a.slug} value={a.slug}>
                    {a.name} ({a.documents})
                  </option>
                ))}
            </select>
          </label>
          <label className="space-y-1">
            <span className="block text-xs text-muted">Published from</span>
            <input type="date" name="from" defaultValue={params.from} className={`${inputClass} w-auto py-1.5`} />
          </label>
          <label className="space-y-1">
            <span className="block text-xs text-muted">to</span>
            <input type="date" name="to" defaultValue={params.to} className={`${inputClass} w-auto py-1.5`} />
          </label>
          <label className="flex items-center gap-2 pb-2">
            <input type="checkbox" name="relevance" value="profile" defaultChecked={params.relevance === "profile"} className="size-4 accent-[var(--color-signal)]" />
            Amends a CFR part in our profile
          </label>
          <label className="flex items-center gap-2 pb-2">
            <input type="checkbox" name="comments_open" value="1" defaultChecked={Boolean(params.comments_open)} className="size-4 accent-[var(--color-signal)]" />
            Open for comment
          </label>
          {filtered && (
            <Link href="/regulations" className="pb-2 text-signal underline-offset-4 hover:underline">
              Clear filters
            </Link>
          )}
        </div>
      </form>

      {!list.ok ? (
        <p role="alert" className="mt-8 border-l-2 border-caution pl-3 text-sm">
          {list.error.message}
        </p>
      ) : (
        <section aria-label="Results" className="mt-6">
          {list.data.meta.profile_filter_unavailable ? (
            <p className="text-sm">
              Your regulatory profile has no federal programs selected, so RuleRadar cannot tell which CFR parts matter to
              you.{" "}
              <Link href="/settings/profile" className="text-signal underline-offset-4 hover:underline">
                Add programs to the profile
              </Link>
            </p>
          ) : (
            <p className="text-sm text-muted" aria-live="polite">
              {list.data.meta.total.toLocaleString("en-US")} {list.data.meta.total === 1 ? "publication" : "publications"}
              {params.relevance === "profile" && list.data.meta.profile_cfr_parts.length > 0 && (
                <> amending {list.data.meta.profile_cfr_parts.join(", ")}</>
              )}
            </p>
          )}

          {list.data.data.length === 0 && !list.data.meta.profile_filter_unavailable && (
            <p className="mt-6 max-w-[62ch] text-sm">
              {filtered
                ? "Nothing matches these filters. Try a broader search or clear the filters."
                : "No publications have been retrieved yet. The first scheduled check of the Federal Register will fill this list."}
            </p>
          )}

          <ol className="mt-4 divide-y divide-rule border-y border-rule">
            {list.data.data.map((doc) => (
              <DocumentRow key={doc.id} doc={doc} />
            ))}
          </ol>

          {list.data.meta.last_page > 1 && (
            <nav aria-label="Pages" className="mt-6 flex items-center justify-between text-sm">
              {list.data.meta.page > 1 ? (
                <Link href={pageHref(list.data.meta.page - 1)} className="text-signal underline-offset-4 hover:underline">
                  Newer results
                </Link>
              ) : (
                <span />
              )}
              <span className="text-muted">
                Page {list.data.meta.page} of {list.data.meta.last_page}
              </span>
              {list.data.meta.page < list.data.meta.last_page ? (
                <Link href={pageHref(list.data.meta.page + 1)} className="text-signal underline-offset-4 hover:underline">
                  Older results
                </Link>
              ) : (
                <span />
              )}
            </nav>
          )}
        </section>
      )}
    </div>
  );
}
