<?php

namespace App\Analysis;

use App\Models\OrganizationProfile;
use App\Models\TaxonomyTerm;

/** The current organization's profile as labelled facts for the prompt and retrieval. */
final class ProfileFacts
{
    /** @return array{facts: array<string, mixed>, version: int, query: string, cfr_parts: list<string>, functions: list<string>} */
    public static function current(): array
    {
        $profile = OrganizationProfile::query()->first();
        $terms = TaxonomyTerm::query()->where('industry', 'healthcare')->get(['kind', 'code', 'label', 'metadata']);
        $label = fn (string $kind, array $codes) => $terms->where('kind', $kind)->whereIn('code', $codes)->pluck('label')->values()->all();

        $codes = fn (string $field) => $profile ? ($profile->getAttribute($field) ?? []) : [];

        $facts = [
            'organization_types' => $label('org_type', $codes('org_types')),
            'states' => $codes('states'),
            'federal_programs' => $label('program', $codes('programs')),
            'operational_functions' => $label('function', $codes('functions')),
            'products_and_services' => $codes('products'),
            'known_obligations' => $codes('obligations'),
        ];
        if (! $profile || $profile->completed_at === null) {
            $facts['note'] = 'The organization has not completed its regulatory profile.';
        }

        $cfrParts = $terms->where('kind', 'program')->whereIn('code', $codes('programs'))
            ->flatMap(fn ($t) => $t->metadata['cfr_parts'] ?? [])->unique()->values()->all();

        $query = implode('; ', array_merge(
            $facts['organization_types'],
            $facts['federal_programs'],
            $facts['operational_functions'],
            $facts['products_and_services'],
        ));

        return [
            'facts' => $facts,
            'version' => $profile?->version ?? 0,
            'query' => $query !== '' ? $query : 'health plan compliance',
            'cfr_parts' => $cfrParts,
            'functions' => $terms->where('kind', 'function')->pluck('code')->values()->all(),
        ];
    }
}
