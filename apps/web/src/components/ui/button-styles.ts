import { cn } from "@/lib/utils";

/**
 * Button styles, usable from server and client components alike (a
 * function exported from a "use client" file cannot be called on the server).
 */
export type Variant = "primary" | "secondary" | "quiet" | "danger";

const variants: Record<Variant, string> = {
  primary: "bg-ink text-white hover:bg-ink/90",
  secondary: "border border-rule bg-paper text-ink hover:border-ink/40",
  quiet: "text-signal underline-offset-4 hover:underline px-0",
  danger: "border border-caution/40 text-caution hover:bg-caution/5",
};

export function buttonClass(variant: Variant = "primary", className?: string) {
  return cn(
    "inline-flex items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60",
    variants[variant],
    className,
  );
}
