"use client";

import { useFormStatus } from "react-dom";
import { cn } from "@/lib/utils";

type Variant = "primary" | "secondary" | "quiet" | "danger";

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

/** Submit button that shows progress while its form's action runs. */
export function SubmitButton({
  children,
  pendingLabel,
  variant = "primary",
  className,
  name,
  value,
  pending: pendingProp,
}: {
  children: React.ReactNode;
  pendingLabel?: string;
  variant?: Variant;
  className?: string;
  name?: string;
  value?: string;
  /** Pass when the form submits through onSubmit rather than action. */
  pending?: boolean;
}) {
  const status = useFormStatus();
  const pending = pendingProp ?? status.pending;
  return (
    <button type="submit" name={name} value={value} disabled={pending} aria-disabled={pending} className={buttonClass(variant, className)}>
      {pending ? (pendingLabel ?? children) : children}
    </button>
  );
}
