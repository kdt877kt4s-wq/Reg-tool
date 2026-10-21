"use client";

import { SubmitButton } from "@/components/ui/button";
import { FormMessage } from "@/components/ui/field";
import type { NotificationPreference } from "@/lib/api";
import type { FormState } from "@/lib/api-core";
import { useFormAction } from "@/lib/use-form-action";
import { savePreferencesAction } from "../notifications/actions";

export function NotificationPreferencesForm({ preferences }: { preferences: NotificationPreference[] }) {
  const [state, onSubmit, pending] = useFormAction<FormState>(savePreferencesAction, {});
  return (
    <form onSubmit={onSubmit} className="mt-4 max-w-xl space-y-4">
      <fieldset>
        <legend className="text-sm text-muted">In-app notifications are always on. Choose which also arrive by email.</legend>
        <ul className="mt-3 divide-y divide-rule border-y border-rule">
          {preferences.map((p) => (
            <li key={p.kind} className="py-2.5">
              <input type="hidden" name="kind" value={p.kind} />
              <label className="flex items-center justify-between gap-4 text-sm">
                <span>{p.label}</span>
                <span className="flex items-center gap-2 text-xs text-muted">
                  Email
                  <input type="checkbox" name={`email_${p.kind}`} defaultChecked={p.email} className="h-4 w-4" />
                </span>
              </label>
            </li>
          ))}
        </ul>
      </fieldset>
      <FormMessage state={state} />
      <SubmitButton pending={pending} variant="secondary">
        Save email preferences
      </SubmitButton>
    </form>
  );
}
