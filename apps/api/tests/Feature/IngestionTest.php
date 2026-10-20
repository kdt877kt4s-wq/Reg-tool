<?php

use App\Models\RegulatoryDocument;
use App\Models\RegulatoryDocumentVersion;
use App\Models\RegulatoryPassage;
use App\Models\RegulatoryRelation;
use App\Models\Source;
use Illuminate\Support\Facades\Artisan;

/*
| Ingestion against recorded, real Federal Register responses.
*/

beforeEach(fn () => $this->docs = frDocuments());

it('stores real publications with official links and separate dates', function () {
    fakeFederalRegister([$this->docs['2026-20281'], $this->docs['2026-20134']]);

    $run = pollFederalRegister();

    expect($run->status)->toBe('succeeded')
        ->and($run->fetched)->toBe(2)
        ->and($run->created)->toBe(2);

    $globe = RegulatoryDocument::where('external_id', '2026-20281')->firstOrFail();
    expect($globe->type)->toBe('rule')
        ->and($globe->publication_date->toDateString())->toBe('2026-10-02')
        ->and($globe->effective_date->toDateString())->toBe('2026-11-30')
        ->and($globe->comments_close_date)->toBeNull()
        ->and($globe->citation)->toBe('91 FR 62936')
        ->and($globe->cfr_parts)->toBe(['42 CFR 513'])
        ->and($globe->official_pdf_url)->toBe('https://www.govinfo.gov/content/pkg/FR-2026-10-02/pdf/2026-20281.pdf')
        ->and(collect($globe->agencies)->pluck('slug')->all())->toContain('centers-for-medicare-medicaid-services')
        ->and($globe->is_demo)->toBeFalse()
        ->and($globe->text_status)->toBe('unavailable'); // the fake serves no text for it

    $source = Source::where('code', 'federal_register')->firstOrFail();
    expect($source->status)->toBe('healthy')
        ->and($source->cursor_date->toDateString())->toBe('2026-10-02')
        ->and($source->last_success_at)->not->toBeNull();
});

it('splits the full text into passages with headings and printed pages', function () {
    fakeFederalRegister([$this->docs['2026-20134']]);
    pollFederalRegister();

    $doc = RegulatoryDocument::where('external_id', '2026-20134')->firstOrFail();
    $passages = RegulatoryPassage::where('document_id', $doc->id)->orderBy('ordinal')->get();

    expect($doc->text_status)->toBe('available')
        ->and($passages->count())->toBeGreaterThanOrEqual(10)
        ->and($passages->pluck('heading_path')->filter()->implode('|'))->toContain('II. Summary of Errors > A. Summary of Errors in the Preamble')
        ->and($passages->pluck('page_start')->filter()->min())->toBe(62323)
        ->and($passages->pluck('text')->implode(' '))->not->toContain('<a ');
});

it('does not duplicate documents or versions when nothing changed', function () {
    fakeFederalRegister([$this->docs['2026-20281'], $this->docs['2026-20134']]);
    pollFederalRegister();
    $second = pollFederalRegister();

    expect($second->unchanged)->toBe(2)
        ->and($second->created)->toBe(0)
        ->and(RegulatoryDocument::count())->toBe(2)
        ->and(RegulatoryDocumentVersion::count())->toBe(2);
});

it('records a new version with the changed fields when the source changes', function () {
    fakeFederalRegister([$this->docs['2026-20281']]);
    pollFederalRegister();

    $changed = $this->docs['2026-20281'];
    $changed['effective_on'] = '2027-01-04';
    fakeFederalRegister([$changed]);
    $run = pollFederalRegister();

    $doc = RegulatoryDocument::where('external_id', '2026-20281')->firstOrFail();
    $v2 = $doc->versions()->where('version_no', 2)->firstOrFail();

    expect($run->updated)->toBe(1)
        ->and($doc->current_version)->toBe(2)
        ->and($doc->effective_date->toDateString())->toBe('2027-01-04')
        ->and($v2->changes)->toEqual(['effective_date' => ['from' => '2026-11-30', 'to' => '2027-01-04']]); // jsonb reorders keys
});

it('links a correction to the rule it corrects, parsed from the text', function () {
    fakeFederalRegister([$this->docs['2026-15588'], $this->docs['2026-20134']]);
    pollFederalRegister();

    $original = RegulatoryDocument::where('external_id', '2026-15588')->firstOrFail();
    $relation = RegulatoryRelation::firstOrFail();

    expect($relation->relation)->toBe('corrects')
        ->and($relation->to_external_id)->toBe('2026-15588')
        ->and($relation->to_document_id)->toBe($original->id)
        ->and($relation->method)->toBe('parsed_from_text')
        ->and($original->status)->toBe('corrected');
});

it('links the correction once the corrected rule arrives later', function () {
    fakeFederalRegister([$this->docs['2026-20134']]);
    pollFederalRegister();
    expect(RegulatoryRelation::firstOrFail()->to_document_id)->toBeNull();

    fakeFederalRegister([$this->docs['2026-15588'], $this->docs['2026-20134']]);
    pollFederalRegister();

    $original = RegulatoryDocument::where('external_id', '2026-15588')->firstOrFail();
    expect(RegulatoryRelation::firstOrFail()->to_document_id)->toBe($original->id)
        ->and($original->status)->toBe('corrected');
});

it('marks the source degraded and creates nothing when the source fails', function () {
    fakeFederalRegister([], 503);

    $exit = Artisan::call('ruleradar:poll', ['source' => 'federal_register', '--days' => 5]);

    $source = Source::where('code', 'federal_register')->firstOrFail();
    expect($exit)->toBe(1)
        ->and(RegulatoryDocument::count())->toBe(0)
        ->and($source->status)->toBe('degraded')
        ->and($source->consecutive_failures)->toBe(1)
        ->and($source->runs()->latest('id')->first()->status)->toBe('failed');
});
