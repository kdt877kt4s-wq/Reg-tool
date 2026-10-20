"use client";

import { useFormAction } from "@/lib/use-form-action";
import { SubmitButton } from "@/components/ui/button";
import { FormMessage, inputClass } from "@/components/ui/field";
import type { Profile, TaxonomyTerm } from "@/lib/api";
import { saveProfileAction } from "../../actions";

type Options = Record<"org_types" | "programs" | "functions" | "states", TaxonomyTerm[]>;

export function ProfileForm({
  profile,
  options,
  editable,
  welcome,
}: {
  profile: Profile;
  options: Options;
  editable: boolean;
  welcome: boolean;
}) {
  const [state, onSubmit, pending] = useFormAction(saveProfileAction, {});
  const errors = state.fieldErrors ?? {};
  const errorFor = (field: string) =>
    errors[field] ?? Object.entries(errors).find(([k]) => k.startsWith(`${field}.`))?.[1];

  return (
    <form onSubmit={onSubmit} className="mt-10 space-y-12" noValidate>
      {welcome && <input type="hidden" name="welcome" value="1" />}
      <FormMessage state={state} />

      <CheckboxGroup
        name="org_types"
        legend="What kind of organization are you?"
        hint="Choose all that apply. Required."
        terms={options.org_types}
        selected={profile.org_types}
        disabled={!editable}
        error={errorFor("org_types")}
      />

      <CheckboxGroup
        name="programs"
        legend="Which federal programs do you take part in?"
        hint="The regulation parts shown help RuleRadar spot rules that amend them. They are screening hints, not a finding that a rule applies."
        terms={options.programs}
        selected={profile.programs}
        disabled={!editable}
        error={errorFor("programs")}
        showCfr
      />

      <CheckboxGroup
        name="functions"
        legend="Which operational functions should RuleRadar watch for you?"
        terms={options.functions}
        selected={profile.functions}
        disabled={!editable}
        error={errorFor("functions")}
      />

      <CheckboxGroup
        name="states"
        legend="Where do you operate?"
        hint="Choose every state where you hold a license, contract or members. Required."
        terms={options.states}
        selected={profile.states}
        disabled={!editable}
        error={errorFor("states")}
        compact
      />

      <fieldset className="grid gap-8 md:grid-cols-2" disabled={!editable}>
        <TextList
          name="products"
          label="Products and services"
          hint="One per line, for example “HMO D-SNP” or “Prior authorization software”."
          values={profile.products}
          error={errorFor("products")}
        />
        <TextList
          name="obligations"
          label="Known compliance obligations"
          hint="One per line, for example “CMS program audit corrective action plan”."
          values={profile.obligations}
          error={errorFor("obligations")}
        />
      </fieldset>

      {editable ? (
        <div className="flex items-center gap-4 border-t border-rule pt-6">
          <SubmitButton pending={pending} pendingLabel="Saving">{welcome ? "Save and finish setup" : "Save profile"}</SubmitButton>
          <p className="text-xs text-muted">Saving creates a new version; earlier versions are kept.</p>
        </div>
      ) : (
        <p className="border-t border-rule pt-6 text-sm text-muted">Only administrators and compliance officers can change the profile.</p>
      )}
    </form>
  );
}

function CheckboxGroup({
  name,
  legend,
  hint,
  terms,
  selected,
  disabled,
  error,
  compact,
  showCfr,
}: {
  name: string;
  legend: string;
  hint?: string;
  terms: TaxonomyTerm[];
  selected: string[];
  disabled: boolean;
  error?: string;
  compact?: boolean;
  showCfr?: boolean;
}) {
  const errorId = `${name}-error`;
  return (
    <fieldset disabled={disabled} aria-describedby={error ? errorId : undefined}>
      <legend className="text-base font-semibold">{legend}</legend>
      {hint && <p className="mt-1 max-w-[70ch] text-sm text-muted">{hint}</p>}
      {error && (
        <p id={errorId} className="mt-2 text-sm text-caution">
          {error}
        </p>
      )}
      <div
        className={
          compact
            ? "mt-4 grid grid-cols-4 gap-x-4 gap-y-2 sm:grid-cols-6 lg:grid-cols-9"
            : "mt-4 grid gap-x-8 gap-y-2.5 sm:grid-cols-2"
        }
      >
        {terms.map((t) => (
          <label key={t.code} className="flex items-start gap-2.5 text-sm" title={compact ? t.label : undefined}>
            <input
              type="checkbox"
              name={name}
              value={t.code}
              defaultChecked={selected.includes(t.code)}
              className="mt-0.5 size-4 shrink-0 accent-[var(--color-signal)]"
            />
            <span>
              {compact ? t.code : t.label}
              {compact && <span className="sr-only"> {t.label}</span>}
              {showCfr && t.metadata?.cfr_parts && (
                <span className="block text-xs text-muted">{t.metadata.cfr_parts.join(", ")}</span>
              )}
            </span>
          </label>
        ))}
      </div>
    </fieldset>
  );
}

function TextList({
  name,
  label,
  hint,
  values,
  error,
}: {
  name: string;
  label: string;
  hint: string;
  values: string[];
  error?: string;
}) {
  return (
    <div className="space-y-1.5">
      <label htmlFor={name} className="block text-base font-semibold">
        {label}
      </label>
      <p id={`${name}-hint`} className="text-sm text-muted">
        {hint}
      </p>
      <textarea
        id={name}
        name={name}
        rows={5}
        defaultValue={values.join("\n")}
        aria-invalid={Boolean(error)}
        aria-describedby={`${name}-hint`}
        className={inputClass}
      />
      {error && <p className="text-xs text-caution">{error}</p>}
    </div>
  );
}
