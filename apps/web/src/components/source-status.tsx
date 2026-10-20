import type { SourceStatus } from "@/lib/api";
import { formatDateTime } from "@/lib/format";

/** One line per source. Degraded or stale sources are called out so silence is never read as "no news". */
export function SourceStatusLine({ sources }: { sources: SourceStatus[] }) {
  if (sources.length === 0) return null;

  return (
    <div className="space-y-2">
      {sources.map((s) => {
        const checked = formatDateTime(s.last_success_at);
        if (s.status === "degraded" || s.stale) {
          return (
            <p key={s.code} role="status" className="border-l-2 border-caution pl-3 text-sm">
              <span className="font-medium">{s.name} may be missing recent publications.</span>{" "}
              {checked ? `Last successful check ${checked}.` : "It has not been read successfully yet."}{" "}
              {s.status === "degraded" && "The latest attempt failed; RuleRadar retries on schedule."}
            </p>
          );
        }
        return (
          <p key={s.code} className="text-sm text-muted">
            {s.name} checked {checked}. {s.documents.toLocaleString("en-US")} publications on file.
          </p>
        );
      })}
    </div>
  );
}
