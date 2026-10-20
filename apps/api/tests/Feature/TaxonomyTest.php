<?php

use App\Models\TaxonomyTerm;
use Database\Seeders\HealthcareTaxonomySeeder;

beforeEach(fn () => $this->seed(HealthcareTaxonomySeeder::class));

it('seeds every taxonomy kind', function () {
    foreach (TaxonomyTerm::KINDS as $kind) {
        expect(TaxonomyTerm::where('kind', $kind)->count())->toBeGreaterThan(0);
    }
    expect(TaxonomyTerm::where('kind', 'state')->count())->toBe(52); // 50 states + DC + PR
});

it('is idempotent', function () {
    $before = TaxonomyTerm::count();
    $this->seed(HealthcareTaxonomySeeder::class);
    expect(TaxonomyTerm::count())->toBe($before);
});

it('lists terms filtered by kind', function () {
    $this->getJson('/api/v1/taxonomy?kind=program')
        ->assertOk()
        ->assertJsonFragment(['code' => 'medicare_advantage'])
        ->assertJsonMissing(['code' => 'TX']);
});

it('marks CFR parts on programs as screening hints only', function () {
    $ma = TaxonomyTerm::where('code', 'medicare_advantage')->firstOrFail();
    expect($ma->metadata['cfr_parts'])->toBe(['42 CFR 422']);
});

it('rejects an unknown kind', function () {
    $this->getJson('/api/v1/taxonomy?kind=nonsense')->assertUnprocessable();
});
