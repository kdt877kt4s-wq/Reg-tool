"use client";

import { useFormAction } from "@/lib/use-form-action";
import { SubmitButton } from "@/components/ui/button";
import { Field, FormMessage, inputClass } from "@/components/ui/field";
import { registerAction } from "../actions";

export function RegisterForm() {
  const [state, onSubmit, pending] = useFormAction(registerAction, {});
  const e = state.fieldErrors ?? {};

  return (
    <form onSubmit={onSubmit} className="mt-8 space-y-5" noValidate>
      <FormMessage state={state} />
      <Field id="organization_name" label="Organization name" error={e.organization_name}>
        {(p) => <input {...p} name="organization_name" autoComplete="organization" required className={inputClass} />}
      </Field>
      <Field id="name" label="Your name" error={e.name}>
        {(p) => <input {...p} name="name" autoComplete="name" required className={inputClass} />}
      </Field>
      <Field id="email" label="Work email" error={e.email}>
        {(p) => <input {...p} name="email" type="email" autoComplete="email" required className={inputClass} />}
      </Field>
      <Field id="password" label="Password" hint="At least 12 characters. A short phrase works well." error={e.password}>
        {(p) => <input {...p} name="password" type="password" autoComplete="new-password" required minLength={12} className={inputClass} />}
      </Field>
      <Field id="password_confirmation" label="Confirm password" error={e.password_confirmation}>
        {(p) => <input {...p} name="password_confirmation" type="password" autoComplete="new-password" required className={inputClass} />}
      </Field>
      <SubmitButton pending={pending} pendingLabel="Creating organization" className="w-full">
        Create organization
      </SubmitButton>
    </form>
  );
}
