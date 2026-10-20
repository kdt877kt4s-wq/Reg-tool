"use client";

import { useFormAction } from "@/lib/use-form-action";
import { SubmitButton } from "@/components/ui/button";
import { Field, FormMessage, inputClass } from "@/components/ui/field";
import { loginAction } from "../actions";

export function LoginForm({ next }: { next: string }) {
  const [state, onSubmit, pending] = useFormAction(loginAction, {});

  return (
    <form onSubmit={onSubmit} className="mt-8 space-y-5" noValidate>
      <FormMessage state={state} />
      <input type="hidden" name="next" value={next} />
      <Field id="email" label="Work email" error={state.fieldErrors?.email}>
        {(p) => <input {...p} name="email" type="email" autoComplete="username" required className={inputClass} />}
      </Field>
      <Field id="password" label="Password" error={state.fieldErrors?.password}>
        {(p) => <input {...p} name="password" type="password" autoComplete="current-password" required className={inputClass} />}
      </Field>
      <SubmitButton pending={pending} pendingLabel="Signing in" className="w-full">
        Sign in
      </SubmitButton>
    </form>
  );
}
