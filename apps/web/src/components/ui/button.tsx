"use client";

import { useFormStatus } from "react-dom";
import { buttonClass, type Variant } from "./button-styles";

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
