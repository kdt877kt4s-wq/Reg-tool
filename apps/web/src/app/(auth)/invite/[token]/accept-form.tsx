"use client";

import { useFormAction } from "@/lib/use-form-action";
import { SubmitButton } from "@/components/ui/button";
import { Field, FormMessage, inputClass } from "@/components/ui/field";
import { acceptInvitationAction } from "../../actions";

export function AcceptForm({ token, accountExists }: { token: string; accountExists: boolean }) {
  const [state, onSubmit, pending] = useFormAction(acceptInvitationAction, {});
  const e = state.fieldErrors ?? {};

  return (
    <form onSubmit={onSubmit} className="mt-8 space-y-5" noValidate>
      <FormMessage state={state} />
      <input type="hidden" name="token" value={token} />
      {!accountExists && (
        <Field id="name" label="Your name" error={e.name}>
          {(p) => <input {...p} name="name" autoComplete="name" required className={inputClass} />}
        </Field>
      )}
      <Field
        id="password"
        label={accountExists ? "Your password" : "Choose a password"}
        hint={accountExists ? undefined : "At least 12 characters."}
        error={e.password}
      >
        {(p) => (
          <input
            {...p}
            name="password"
            type="password"
            autoComplete={accountExists ? "current-password" : "new-password"}
            required
            className={inputClass}
          />
        )}
      </Field>
      {!accountExists && (
        <Field id="password_confirmation" label="Confirm password">
          {(p) => <input {...p} name="password_confirmation" type="password" autoComplete="new-password" required className={inputClass} />}
        </Field>
      )}
      <SubmitButton pending={pending} pendingLabel="Joining" className="w-full">
        Accept invitation
      </SubmitButton>
    </form>
  );
}
