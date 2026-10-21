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
