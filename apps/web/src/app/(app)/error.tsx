"use client";

import { buttonClass } from "@/components/ui/button-styles";

export default function SignedInError({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return (
    <div className="max-w-xl">
      <h1 className="text-2xl font-semibold tracking-tight">This page could not load</h1>
      <p className="mt-3 text-sm text-muted">
        RuleRadar could not get what this page needs from its server. Nothing was changed. Try again, and if it keeps
        happening, contact your administrator.
      </p>
      <button type="button" onClick={reset} className={`${buttonClass("primary")} mt-6`}>
        Try again
      </button>
    </div>
  );
}
