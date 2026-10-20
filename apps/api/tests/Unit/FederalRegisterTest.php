<?php

use App\Ingestion\FederalRegister\FederalRegisterAdapter;
use App\Ingestion\FederalRegister\FederalRegisterText;
use App\Ingestion\FederalRegisterReferences;
use App\Ingestion\PassageChunker;
use Tests\TestCase;

uses(TestCase::class);

function fixtureJson(string $name): array
{
    return json_decode(file_get_contents(__DIR__."/../Fixtures/federal_register/{$name}.json"), true);
}

it('normalizes a real final rule without inferring missing values', function () {
    $doc = FederalRegisterAdapter::normalize(fixtureJson('document-2026-20281'));

    expect($doc->externalId)->toBe('2026-20281')
        ->and($doc->type)->toBe('rule')
        ->and($doc->publicationDate)->toBe('2026-10-02')
        ->and($doc->effectiveDate)->toBe('2026-11-30')
        ->and($doc->commentsCloseDate)->toBeNull()
        ->and($doc->cfrParts)->toBe(['42 CFR 513'])
        ->and($doc->officialPdfUrl)->toStartWith('https://www.govinfo.gov/')
        ->and($doc->relation)->toBeNull();
});

it('recognises a correction from its published action', function () {
    $doc = FederalRegisterAdapter::normalize(fixtureJson('document-2026-20134'));

    expect($doc->action)->toBe('Final rule; correction.')
        ->and($doc->relation)->toBe('corrects')
        ->and($doc->relatesTo)->toBeNull(); // the API leaves correction_of empty
});

it('normalizes proposed rules from a real search with comment deadlines', function () {
    $first = FederalRegisterAdapter::normalize(fixtureJson('search-cfr-42-422')['results'][0]);

    expect($first->type)->toBe('proposed_rule')
        ->and($first->cfrParts)->toContain('42 CFR 422')
        ->and($first->effectiveDate)->toBeNull();
});

it('cleans the raw text rendition', function () {
    $text = FederalRegisterText::clean(file_get_contents(__DIR__.'/../Fixtures/federal_register/document-2026-20134.raw.txt'));

    expect($text)->not->toContain('<')
        ->not->toContain('&#160;')
        ->toContain('[[Page 62323]]')
        ->toContain('"Medicare');
});

it('chunks real text with exact offsets, headings and pages', function () {
    $clean = FederalRegisterText::clean(file_get_contents(__DIR__.'/../Fixtures/federal_register/document-2026-20134.raw.txt'));
    $result = PassageChunker::chunk($clean);

    foreach ($result['passages'] as $p) {
        expect(substr($result['text'], $p['char_start'], $p['char_end'] - $p['char_start']))->toBe($p['text'])
            ->and(strlen($p['text']))->toBeLessThanOrEqual(PassageChunker::MAX_CHARS + PassageChunker::TARGET_CHARS);
    }

    $headings = array_filter(array_column($result['passages'], 'heading_path'));
    expect($headings)->toContain('I. Background')
        ->and(max(array_filter(array_column($result['passages'], 'page_end'))))->toBe(62327);
});

it('finds the referenced document but never the document itself', function () {
    $clean = FederalRegisterText::clean(file_get_contents(__DIR__.'/../Fixtures/federal_register/document-2026-20134.raw.txt'));

    expect(FederalRegisterReferences::firstReferencedDocument($clean, '2026-20134'))->toBe('2026-15588')
        ->and(FederalRegisterReferences::firstReferencedDocument('[FR Doc. 2026-20134 Filed 9-30-26]', '2026-20134'))->toBeNull();
});

it('detects headings by level', function (string $line, ?int $level) {
    expect(PassageChunker::headingLevel($line))->toBe($level);
})->with([
    ['I. Background', 1],
    ['II. Summary of Errors', 1],
    ['A. Summary of Errors in the Preamble', 2],
    ['§ 422.111 Disclosure requirements.', 4],
    ['There was a technical error in the calculation.', null],
]);
