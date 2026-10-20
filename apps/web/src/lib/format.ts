/**
 * Formatting helpers. Calendar dates from the API ("2026-10-02") are dates,
 * not instants: they are formatted in UTC so no time zone shifts the day.
 */

export function formatDate(isoDate: string | null | undefined): string | null {
  if (!isoDate) return null;
  const d = new Date(`${isoDate.slice(0, 10)}T00:00:00Z`);
  if (Number.isNaN(d.getTime())) return null;
  return d.toLocaleDateString("en-US", { timeZone: "UTC", year: "numeric", month: "short", day: "numeric" });
}

export function formatDateTime(iso: string | null | undefined, timeZone = "America/Chicago"): string | null {
  if (!iso) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  return d.toLocaleString("en-US", { timeZone, month: "short", day: "numeric", hour: "numeric", minute: "2-digit", timeZoneName: "short" });
}

/** Whole days from `today` to a calendar date (negative when past). */
export function daysUntil(isoDate: string, today: Date = new Date()): number {
  const target = Date.UTC(Number(isoDate.slice(0, 4)), Number(isoDate.slice(5, 7)) - 1, Number(isoDate.slice(8, 10)));
  const now = Date.UTC(today.getUTCFullYear(), today.getUTCMonth(), today.getUTCDate());
  return Math.round((target - now) / 86_400_000);
}

/** Split an API snippet with [[[match]]] markers into text and highlighted parts. */
export function snippetParts(snippet: string): { text: string; match: boolean }[] {
  const parts: { text: string; match: boolean }[] = [];
  const re = /\[\[\[(.*?)\]\]\]/gs;
  let last = 0;
  for (const m of snippet.matchAll(re)) {
    if (m.index! > last) parts.push({ text: snippet.slice(last, m.index), match: false });
    parts.push({ text: m[1] ?? "", match: true });
    last = m.index! + m[0].length;
  }
  if (last < snippet.length) parts.push({ text: snippet.slice(last), match: false });
  return parts;
}

/** "Comments close Dec 15, 2026 (56 days)", or that they closed. */
export function commentDeadlineNote(date: string | null, today: Date = new Date()): string | null {
  if (!date) return null;
  const days = daysUntil(date, today);
  if (days < 0) return `Comments closed ${formatDate(date)}`;
  if (days === 0) return "Comments close today";
  return `Comments close ${formatDate(date)} (${days} ${days === 1 ? "day" : "days"})`;
}

/** The calendar date `days` before `now`, as YYYY-MM-DD (UTC). */
export function isoDateDaysAgo(days: number, now: Date = new Date()): string {
  return new Date(now.getTime() - days * 86_400_000).toISOString().slice(0, 10);
}
