/** Plain-language labels shared by analysis and assessment screens. */

export const DECISION_LABELS = {
  applicable: "Applies to us",
  not_applicable: "Does not apply to us",
  monitor: "Monitor",
} as const;

export const APPLICABILITY_LABELS = {
  likely: "Likely applies",
  possible: "May apply",
  unlikely: "Unlikely to apply",
} as const;

export const CONFIDENCE_LABELS = {
  high: "High confidence",
  medium: "Medium confidence",
  low: "Low confidence",
} as const;

export const PRIORITY_LABELS = { high: "High", medium: "Medium", low: "Low" } as const;

export const BASIS_LABELS = {
  regulatory_text: "From the regulation",
  ai_interpretation: "AI interpretation",
} as const;

/** "page 62324" or "pages 62324–62326" for a cited passage. */
export function pageLabel(start: number | null, end: number | null): string | null {
  if (!start) return null;
  return end && end !== start ? `pages ${start}–${end}` : `page ${start}`;
}

export const TASK_STATUS_LABELS = {
  open: "Open",
  in_progress: "In progress",
  submitted: "Waiting for review",
  returned: "Returned",
  done: "Done",
  cancelled: "Cancelled",
} as const;

export const DEADLINE_KIND_LABELS = {
  effective: "Effective date",
  comments_close: "Comments close",
  task_due: "Task due",
} as const;

/** "1.2 MB" for evidence file sizes. */
export function fileSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const EVENT_LABELS: Record<string, string> = {
  created: "Created",
  assigned: "Assigned",
  edited: "Edited",
  started: "Started",
  evidence_added: "Added evidence",
  submitted: "Submitted for review",
  accepted: "Accepted as done",
  returned: "Returned",
  cancelled: "Cancelled",
};

export function eventLabel(type: string): string {
  return EVENT_LABELS[type] ?? type;
}
