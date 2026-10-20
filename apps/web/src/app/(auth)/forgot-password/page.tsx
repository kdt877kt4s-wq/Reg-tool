"use client";

import Link from "next/link";
import { useFormAction } from "@/lib/use-form-action";
import { SubmitButton } from "@/components/ui/button";
import { Field, FormMessage, inputClass } from "@/components/ui/field";
import { forgotPasswordAction } from "../actions";

export default function ForgotPasswordPage() {
  const [state, onSubmit, pending] = useFormAction(forgotPasswordAction, {});

  return (
    <>
      <h1 className="text-2xl font-semibold tracking-tight">Reset your password</h1>
      <p className="mt-3 text-sm text-muted">Enter the email you sign in with. We will send a link to set a new password.</p>
      <form onSubmit={onSubmit} className="mt-8 space-y-5" noValidate>
        <FormMessage state={state} />
        {!state.ok && (
          <>
            <Field id="email" label="Work email" error={state.fieldErrors?.email}>
              {(p) => <input {...p} name="email" type="email" autoComplete="email" required className={inputClass} />}
            </Field>
            <SubmitButton pending={pending} pendingLabel="Sending" className="w-full">
              Send reset link
            </SubmitButton>
          </>
        )}
      </form>
      <p className="mt-8 text-sm">
        <Link href="/login" className="text-signal underline-offset-4 hover:underline">
          Back to sign in
        </Link>
      </p>
    </>
  );
}
