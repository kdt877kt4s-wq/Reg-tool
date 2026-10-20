"use client";

import Link from "next/link";
import { useFormAction } from "@/lib/use-form-action";
import { SubmitButton, buttonClass } from "@/components/ui/button";
import { Field, FormMessage, inputClass } from "@/components/ui/field";
import { twoFactorAction, type SetupState } from "./actions";

export function TwoFactorSetup({ nextHref }: { nextHref: string }) {
  const [state, onSubmit, pending] = useFormAction(twoFactorAction, { step: "start" } as SetupState);

  if (state.step === "start") {
    return (
      <form onSubmit={onSubmit} className="mt-8 space-y-4">
        <input type="hidden" name="intent" value="start" />
        <FormMessage state={{ message: state.message }} />
        <p className="text-sm">You need an authenticator app on your phone, such as Microsoft Authenticator, Google Authenticator or 1Password.</p>
        <SubmitButton pending={pending} pendingLabel="Preparing">Set up two-factor login</SubmitButton>
      </form>
    );
  }

  if (state.step === "scan") {
    return (
      <form onSubmit={onSubmit} className="mt-8 space-y-6" noValidate>
        <input type="hidden" name="intent" value="confirm" />
        <FormMessage state={{ message: state.message }} />
        <ol className="space-y-6 text-sm">
          <li>
            <p className="font-medium">1. Scan this code with your authenticator app.</p>
            <div
              className="mt-3 inline-block rounded-md border border-rule bg-white p-2"
              role="img"
              aria-label="QR code for your authenticator app"
              // Generated on our server from the API's provisioning URI.
              dangerouslySetInnerHTML={{ __html: state.qrSvg ?? "" }}
            />
            <p className="mt-3 text-muted">
              Can’t scan it? Enter this key instead:{" "}
              <code className="rounded bg-ink/5 px-1.5 py-0.5 text-ink break-all">{state.secret}</code>
            </p>
          </li>
          <li>
            <p className="font-medium">2. Enter the 6-digit code it shows.</p>
            <Field id="code" label="6-digit code" error={state.fieldErrors?.code} className="mt-3 max-w-48">
              {(p) => (
                <input
                  {...p}
                  name="code"
                  inputMode="numeric"
                  pattern="[0-9]*"
                  maxLength={6}
                  autoComplete="one-time-code"
                  required
                  className={`${inputClass} tracking-[0.3em] tabular-nums`}
                />
              )}
            </Field>
          </li>
        </ol>
        <SubmitButton pending={pending} pendingLabel="Checking">Turn on two-factor login</SubmitButton>
      </form>
    );
  }

  return (
    <div className="mt-8 space-y-5">
      <p role="status" className="border-l-2 border-signal pl-3 text-sm">
        Two-factor login is on.
      </p>
      <div>
        <h2 className="text-base font-semibold">Save your recovery codes</h2>
        <p className="mt-1 text-sm text-muted">
          If you lose your phone, each of these lets you sign in once. They are shown only now. Store them in your
          password manager.
        </p>
        <ul className="mt-4 grid grid-cols-2 gap-x-6 gap-y-1.5 rounded-md border border-rule p-4 text-sm tabular-nums">
          {state.recoveryCodes?.map((c) => (
            <li key={c}>
              <code>{c}</code>
            </li>
          ))}
        </ul>
      </div>
      <Link href={nextHref} className={buttonClass("primary")}>
        I saved my codes, continue
      </Link>
    </div>
  );
}
