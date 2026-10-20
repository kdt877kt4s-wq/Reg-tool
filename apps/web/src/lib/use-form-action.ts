"use client";

import { useActionState, useTransition } from "react";

/**
 * Like useActionState, but submits through onSubmit so React does not
 * reset the form afterwards: when the server rejects a field, everything
 * the person entered stays in place.
 */
export function useFormAction<S>(action: (prev: S, form: FormData) => Promise<S>, initial: S) {
  const [state, dispatch, pending] = useActionState(action, initial);
  const [, startTransition] = useTransition();

  function onSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const submitter = (event.nativeEvent as SubmitEvent).submitter;
    const form = new FormData(event.currentTarget, submitter);
    startTransition(() => dispatch(form));
  }

  return [state, onSubmit, pending] as const;
}
