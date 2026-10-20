"use client";

import { useFormAction } from "@/lib/use-form-action";
import { SubmitButton } from "@/components/ui/button";
import { Field, FormMessage, inputClass } from "@/components/ui/field";
import { resetPasswordAction } from "../actions";

export function ResetForm({ token, email }: { token: string; email: string }) {
  const [state, onSubmit, pending] = useFormAction(resetPasswordAction, {});

  return (
    <form onSubmit={onSubmit} className="mt-8 space-y-5" noValidate>
      <FormMessage state={state} />
      <input type="hidden" name="token" value={token} />
      <input type="hidden" name="email" value={email} />
      <Field id="password" label="New password" hint="At least 12 characters." error={state.fieldErrors?.password}>
        {(p) => <input {...p} name="password" type="password" autoComplete="new-password" required minLength={12} className={inputClass} />}
      </Field>
      <Field id="password_confirmation" label="Confirm new password">
        {(p) => <input {...p} name="password_confirmation" type="password" autoComplete="new-password" required className={inputClass} />}
      </Field>
      <SubmitButton pending={pending} pendingLabel="Saving" className="w-full">
        Save new password
      </SubmitButton>
    </form>
  );
}
