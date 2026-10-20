"use client";

import { useState } from "react";
import { useFormAction } from "@/lib/use-form-action";
import { SubmitButton } from "@/components/ui/button";
import { Field, FormMessage, inputClass } from "@/components/ui/field";
import { verifyAction } from "../../actions";

export function VerifyForm({ next }: { next: string }) {
  const [state, onSubmit, pending] = useFormAction(verifyAction, {});
  const [recovery, setRecovery] = useState(false);

  return (
    <form onSubmit={onSubmit} className="mt-8 space-y-5" noValidate>
      <FormMessage state={state} />
      <input type="hidden" name="next" value={next} />
      <input type="hidden" name="method" value={recovery ? "recovery" : "code"} />

      {recovery ? (
        <Field id="recovery_code" label="Recovery code" hint="Each recovery code works once." error={state.fieldErrors?.recovery_code}>
          {(p) => <input {...p} name="recovery_code" autoComplete="off" required className={inputClass} />}
        </Field>
      ) : (
        <Field id="code" label="6-digit code" error={state.fieldErrors?.code}>
          {(p) => (
            <input
              {...p}
              name="code"
              inputMode="numeric"
              pattern="[0-9]*"
              maxLength={6}
              autoComplete="one-time-code"
              autoFocus
              required
              className={`${inputClass} tracking-[0.3em] tabular-nums`}
            />
          )}
        </Field>
      )}

      <SubmitButton pending={pending} pendingLabel="Checking" className="w-full">
        Continue
      </SubmitButton>

      <button type="button" onClick={() => setRecovery(!recovery)} className="text-sm text-signal underline-offset-4 hover:underline">
        {recovery ? "Use a code from my authenticator app" : "I can’t use my authenticator app"}
      </button>
    </form>
  );
}
