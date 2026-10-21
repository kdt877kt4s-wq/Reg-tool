<?php

namespace App\Regulations;

use App\Models\OrganizationProfile;
use App\Models\TaxonomyTerm;
use Illuminate\Database\Eloquent\Builder;

/**
 * CFR parts tied to the programs in the current organization's profile.
 * These are screening hints: a match means "this document amends a part
 * linked to a program you selected", not that it applies to you.
 */
final class ProfileMatcher
{
    /** @return list<string> */
    public function parts(): array
    {
        $programs = OrganizationProfile::query()->value('programs');
        $codes = is_string($programs) ? json_decode($programs, true) : $programs;
        if (! is_array($codes) || $codes === []) {
            return [];
        }

        return TaxonomyTerm::query()
            ->where('kind', 'program')
            ->whereIn('code', $codes)
            ->pluck('metadata')
            ->map(fn ($m) => is_string($m) ? json_decode($m, true) : $m)
            ->flatMap(fn ($m) => is_array($m) ? ($m['cfr_parts'] ?? []) : [])
            ->unique()->sort()->values()->all();
    }

    /**
     * Limit a document query to profile matches. With no parts the query
     * matches nothing.
     *
     * @param  list<string>  $parts
     */
    public function scope(Builder $query, array $parts): Builder
    {
        return $parts === []
            ? $query->whereRaw('1 = 0')
            : $query->whereRaw('jsonb_exists_any(cfr_parts, ?::text[])', [self::pgArray($parts)]);
    }

    /** @param list<string> $values */
    public static function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn ($v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"', $values)).'}';
    }
}
