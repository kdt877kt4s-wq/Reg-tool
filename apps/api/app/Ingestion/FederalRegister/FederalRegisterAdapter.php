<?php

namespace App\Ingestion\FederalRegister;

use App\Ingestion\Contracts\SourceAdapter;
use App\Ingestion\NormalizedDocument;
use App\Ingestion\SourceUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Federal Register API v1 (no key required). Field names and agency slugs
 * were verified against live responses; see tests/Fixtures/federal_register.
 *
 * FederalRegister.gov states its XML rendition is not the official legal
 * edition. The GovInfo PDF (pdf_url) is, so it is kept as the official
 * source link.
 */
final class FederalRegisterAdapter implements SourceAdapter
{
    public const FIELDS = [
        'abstract', 'action', 'agencies', 'cfr_references', 'citation', 'comments_close_on',
        'correction_of', 'dates', 'docket_ids', 'document_number', 'effective_on', 'end_page',
        'html_url', 'pdf_url', 'publication_date', 'raw_text_url', 'regulation_id_numbers',
        'significant', 'start_page', 'title', 'type',
    ];

    private const TYPES = [
        'Rule' => 'rule',
        'Proposed Rule' => 'proposed_rule',
        'Notice' => 'notice',
        'Presidential Document' => 'presidential',
    ];

    /** @param array<string, mixed> $config */
    public function __construct(private array $config) {}

    public function code(): string
    {
        return 'federal_register';
    }

    public function discover(CarbonImmutable $from, CarbonImmutable $to): iterable
    {
        $seen = [];

        foreach ($this->config['queries'] as $query) {
            $url = $this->searchUrl($query, $from, $to);

            while ($url !== null) {
                $page = $this->getJson($url);

                foreach ($page['results'] ?? [] as $raw) {
                    $number = (string) ($raw['document_number'] ?? '');
                    // The same document can match more than one query.
                    if ($number === '' || isset($seen[$number])) {
                        continue;
                    }
                    $seen[$number] = true;
                    yield self::normalize($raw);
                }

                $url = $page['next_page_url'] ?? null;
            }
        }
    }

    public function fetchText(string $rawTextUrl): ?string
    {
        try {
            $response = $this->http()->accept('text/plain, text/html')->get($rawTextUrl);
        } catch (ConnectionException $e) {
            throw new SourceUnavailable('Federal Register text could not be reached: '.$e->getMessage(), previous: $e);
        }

        if ($response->status() === 404) {
            return null;
        }
        $this->assertOk($response);

        return FederalRegisterText::clean($response->body());
    }

    /** @param array<string, mixed> $raw */
    public static function normalize(array $raw): NormalizedDocument
    {
        $action = self::str($raw['action'] ?? null);
        [$relation, $relatesTo] = self::structuredRelation($raw, $action);

        return new NormalizedDocument(
            externalId: (string) $raw['document_number'],
            type: self::TYPES[$raw['type'] ?? ''] ?? 'other',
            action: $action,
            title: trim((string) ($raw['title'] ?? '')),
            abstract: self::str($raw['abstract'] ?? null),
            datesText: self::str($raw['dates'] ?? null),
            agencies: array_values(array_map(
                fn (array $a) => ['slug' => (string) ($a['slug'] ?? ''), 'name' => (string) ($a['name'] ?? $a['raw_name'] ?? '')],
                array_filter($raw['agencies'] ?? [], 'is_array'),
            )),
            cfrParts: self::cfrParts($raw['cfr_references'] ?? []),
            docketIds: array_values(array_map('strval', $raw['docket_ids'] ?? [])),
            rins: array_values(array_map('strval', $raw['regulation_id_numbers'] ?? [])),
            citation: self::str($raw['citation'] ?? null),
            startPage: isset($raw['start_page']) ? (int) $raw['start_page'] : null,
            endPage: isset($raw['end_page']) ? (int) $raw['end_page'] : null,
            significant: isset($raw['significant']) ? (bool) $raw['significant'] : null,
            publicationDate: (string) $raw['publication_date'],
            effectiveDate: self::str($raw['effective_on'] ?? null),
            commentsCloseDate: self::str($raw['comments_close_on'] ?? null),
            officialPdfUrl: self::str($raw['pdf_url'] ?? null),
            htmlUrl: self::str($raw['html_url'] ?? null),
            rawTextUrl: self::str($raw['raw_text_url'] ?? null),
            relation: $relation,
            relatesTo: $relatesTo,
        );
    }

    /**
     * Corrections and withdrawals are recognised from the published action
     * ("Final rule; correction."). The target comes from correction_of when
     * the source fills it; otherwise it is parsed from the text later.
     *
     * @param  array<string, mixed>  $raw
     * @return array{0: ?string, 1: ?string}
     */
    private static function structuredRelation(array $raw, ?string $action): array
    {
        $relation = self::relationFromAction($action);
        $target = self::str($raw['correction_of'] ?? null);

        if ($target !== null) {
            return [$relation ?? 'corrects', basename($target)];
        }

        return [$relation, null];
    }

    public static function relationFromAction(?string $action): ?string
    {
        $a = strtolower((string) $action);

        return match (true) {
            str_contains($a, 'correction'), str_contains($a, 'correcting amendment') => 'corrects',
            str_contains($a, 'withdrawal'), str_contains($a, 'withdrawn') => 'withdraws',
            default => null,
        };
    }

    /** @param mixed $references @return list<string> */
    private static function cfrParts(mixed $references): array
    {
        $parts = [];
        foreach (is_array($references) ? $references : [] as $ref) {
            if (is_array($ref) && isset($ref['title'], $ref['part']) && $ref['part'] !== '') {
                $parts[] = "{$ref['title']} CFR {$ref['part']}";
            }
        }

        return array_values(array_unique($parts));
    }

    /**
     * The API expects list parameters as "fields[]=a&fields[]=b". PHP's
     * http_build_query writes "fields[0]=a", which the API rejects with
     * HTTP 400, so indexes are removed.
     *
     * @param  array{agencies: list<string>, types: list<string>}  $query
     */
    public function searchUrl(array $query, CarbonImmutable $from, CarbonImmutable $to): string
    {
        $params = [
            'per_page' => $this->config['per_page'],
            'order' => 'oldest',
            'fields' => self::FIELDS,
            'conditions' => [
                'agencies' => $query['agencies'],
                'type' => $query['types'],
                'publication_date' => ['gte' => $from->toDateString(), 'lte' => $to->toDateString()],
            ],
        ];

        $queryString = preg_replace('/%5B\d+%5D/', '%5B%5D', http_build_query($params, '', '&', PHP_QUERY_RFC3986));

        return rtrim($this->config['base_url'], '/').'/documents.json?'.$queryString;
    }

    /** @return array<string, mixed> */
    private function getJson(string $url): array
    {
        try {
            $response = $this->http()->acceptJson()->get($url);
        } catch (ConnectionException $e) {
            throw new SourceUnavailable('Federal Register could not be reached: '.$e->getMessage(), previous: $e);
        }
        $this->assertOk($response);

        $json = $response->json();
        if (! is_array($json) || ! array_key_exists('results', $json) && ($json['count'] ?? null) !== 0) {
            throw new SourceUnavailable('Federal Register returned an unexpected response shape.');
        }

        return $json;
    }

    private function assertOk(Response $response): void
    {
        if (! $response->successful()) {
            throw new SourceUnavailable("Federal Register answered HTTP {$response->status()}.");
        }
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) $this->config['timeout'])
            ->withUserAgent((string) $this->config['user_agent'])
            ->retry(3, (int) ($this->config['retry_sleep_ms'] ?? 2000), fn ($e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)), throw: false);
    }

    private static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
