import { cn } from "@/lib/utils";

export const inputClass =
  "block w-full rounded-md border border-rule bg-paper px-3 py-2 text-sm text-ink placeholder:text-muted/70 focus:border-signal focus:outline-none focus:ring-2 focus:ring-signal/20 aria-[invalid=true]:border-caution";

/** Label, control, hint and error wired together for screen readers. */
export function Field({
  id,
  label,
  error,
  hint,
  children,
  className,
}: {
  id: string;
  label: string;
  error?: string;
  hint?: string;
  children: (props: { id: string; "aria-invalid": boolean; "aria-describedby"?: string }) => React.ReactNode;
  className?: string;
}) {
  const describedBy = [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(" ") || undefined;
  return (
    <div className={cn("space-y-1.5", className)}>
      <label htmlFor={id} className="block text-sm font-medium">
        {label}
      </label>
      {children({ id, "aria-invalid": Boolean(error), "aria-describedby": describedBy })}
      {hint && (
        <p id={`${id}-hint`} className="text-xs text-muted">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} className="text-xs text-caution">
          {error}
        </p>
      )}
    </div>
  );
}

export function FormMessage({ state }: { state: { ok?: boolean; message?: string } }) {
  if (!state.message) return null;
  return (
    <p
      role={state.ok ? "status" : "alert"}
      className={cn(
        "border-l-2 pl-3 text-sm",
        state.ok ? "border-signal text-ink" : "border-caution text-ink",
      )}
    >
      {state.message}
    </p>
  );
}
