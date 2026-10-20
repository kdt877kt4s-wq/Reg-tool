<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\OrganizationProfile;
use App\Models\OrganizationProfileVersion;
use App\Models\TaxonomyTerm;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The organization's regulatory profile. Each save is a new version; past
 * versions are kept so later applicability screenings stay explainable.
 */
class ProfileController extends Controller
{
    /** profile field => taxonomy kind its values must come from */
    private const TAXONOMY_FIELDS = [
        'org_types' => 'org_type',
        'states' => 'state',
        'programs' => 'program',
        'functions' => 'function',
    ];

    public function show(): JsonResponse
    {
        $this->authorizePermission('profile.view');

        return response()->json(['profile' => $this->present(OrganizationProfile::query()->first())]);
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $this->authorizePermission('profile.edit');

        $rules = [
            'org_types' => ['required', 'array', 'min:1', 'max:10'],
            'states' => ['required', 'array', 'min:1', 'max:60'],
            'programs' => ['present', 'array', 'max:30'],
            'functions' => ['present', 'array', 'max:30'],
            'products' => ['present', 'array', 'max:25'],
            'products.*' => ['string', 'min:2', 'max:120'],
            'obligations' => ['present', 'array', 'max:25'],
            'obligations.*' => ['string', 'min:2', 'max:200'],
        ];
        foreach (self::TAXONOMY_FIELDS as $field => $kind) {
            $rules[$field.'.*'] = ['string', 'distinct', $this->inTaxonomy($kind)];
        }

        $data = $request->validate($rules, [
            'org_types.required' => 'Choose at least one organization type.',
            'states.required' => 'Choose at least one state where you operate.',
        ]);

        $profile = DB::transaction(function () use ($data, $audit) {
            $profile = OrganizationProfile::query()->lockForUpdate()->first() ?? new OrganizationProfile;
            $before = $profile->exists ? $profile->snapshot() : null;

            $profile->fill(collect(OrganizationProfile::LIST_FIELDS)->mapWithKeys(
                fn (string $f) => [$f => array_values(array_unique($data[$f] ?? []))]
            )->all());
            $profile->version = ($profile->version ?? 0) + 1;
            $profile->completed_at ??= now();
            $profile->updated_by = $this->tenant()->userId();
            $profile->save();

            OrganizationProfileVersion::create([
                'version' => $profile->version,
                'snapshot' => $profile->snapshot(),
                'changed_by' => $this->tenant()->userId(),
                'created_at' => now(),
            ]);

            $audit->record('profile.updated', $profile, $before, $profile->snapshot() + ['version' => $profile->version]);

            return $profile;
        });

        return response()->json(['profile' => $this->present($profile)]);
    }

    private function inTaxonomy(string $kind): Closure
    {
        $codes = TaxonomyTerm::query()->where('industry', 'healthcare')->where('kind', $kind)->pluck('code')->all();

        return function (string $attribute, mixed $value, Closure $fail) use ($codes) {
            if (! in_array($value, $codes, true)) {
                $fail('One of the selected values is not recognized.');
            }
        };
    }

    /** @return array<string, mixed> */
    private function present(?OrganizationProfile $profile): array
    {
        $empty = array_fill_keys(OrganizationProfile::LIST_FIELDS, []);

        return ($profile ? $profile->snapshot() : $empty) + [
            'version' => $profile->version ?? 0,
            'completed' => $profile?->completed_at !== null,
            'updated_at' => $profile?->updated_at?->toIso8601String(),
        ];
    }
}
