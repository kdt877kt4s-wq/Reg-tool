/**
 * Primary navigation. A section that is not built yet is listed with the
 * stage it arrives in and is rendered as plain text, never as a link or a
 * button, so nothing in the interface pretends to work before it does.
 */
export type NavItem = {
  label: string;
  href: string;
  /** Implementation stage that delivers this section. */
  stage: number;
};

export const CURRENT_STAGE = 5;

export const NAV_ITEMS: readonly NavItem[] = [
  { label: "Overview", href: "/", stage: 0 },
  { label: "Regulatory intelligence", href: "/regulations", stage: 2 },
  { label: "Impact assessments", href: "/assessments", stage: 3 },
  { label: "Policy analysis", href: "/policy-analysis", stage: 6 },
  { label: "Compliance tasks", href: "/tasks", stage: 4 },
  { label: "Regulatory calendar", href: "/calendar", stage: 4 },
  { label: "Reports", href: "/reports", stage: 5 },
  { label: "AI assistant", href: "/assistant", stage: 6 },
  { label: "Organization settings", href: "/settings", stage: 1 },
];

export function isAvailable(item: NavItem, stage: number = CURRENT_STAGE): boolean {
  return item.stage <= stage;
}

export function availabilityNote(item: NavItem, stage: number = CURRENT_STAGE): string | null {
  if (isAvailable(item, stage)) return null;
  return item.stage > 5 ? "After the MVP" : `Arrives in Stage ${item.stage}`;
}
