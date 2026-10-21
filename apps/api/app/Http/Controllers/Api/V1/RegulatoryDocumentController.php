<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrganizationProfile;
use App\Models\RegulatoryDocument;
use App\Models\RegulatoryPassage;
use App\Models\RegulatoryRelation;
use App\Models\TaxonomyTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Regulatory publications retrieved from authoritative sources. Shared
 * across organizations; "profile matches" are computed per request from
 * the current organization's regulatory profile.
 */
class RegulatoryDocumentController extends Controller
{
    private const LIST_COLUMNS = [
        'id', 'source_id', 'external_id', 'type', 'action', 'title', 'agencies', 'cfr_parts', 'citation',
        'publication_date', 'effective_date', 'comments_close_date', 'status', 'text_status',
        'official_pdf_url', 'html_url', 'is_demo', 'significant', 'last_changed_at', 'current_version',
    ];

    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission('regulations.view');

        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'type' => ['sometimes', 'nullable', Rule::in(array_keys(RegulatoryDocument::TYPE_LABELS))],
            'agency' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[a-z0-9\-]+$/'],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'corrected', 'withdrawn'])],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'relevance' => ['sometimes', 'nullable', Rule::in(['profile'])],
            'comments_open' => ['sometimes', 'boolean'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $profileParts = $this->profileCfrParts();
        $query = RegulatoryDocument::query()->select(self::LIST_COLUMNS);
        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '') {
            $this->applySearch($query, $q);
        } else {
            $query->orderByDesc('publication_date')->orderByDesc('id');
        }

        $query
            ->when($filters['type'] ?? null, fn ($b, $type) => $b->where('type', $type))
            ->when($filters['status'] ?? null, fn ($b, $status) => $b->where('status', $status))
            ->when($filters['from'] ?? null, fn ($b, $from) => $b->whereDate('publication_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($b, $to) => $b->whereDate('publication_date', '<=', $to))
            ->when($filters['agency'] ?? null, fn ($b, $slug) => $b->whereRaw('agencies @> ?::jsonb', [json_encode([['slug' => $slug]])]))
            ->when($request->boolean('comments_open'), fn ($b) => $b->whereDate('comments_close_date', '>=', now()->toDateString()));

        $profileFilter = ($filters['relevance'] ?? null) === 'profile';
        if ($profileFilter) {
            $profileParts === []
                ? $query->whereRaw('1 = 0')
                : $query->whereRaw('jsonb_exists_any(cfr_parts, ?::text[])', [$this->pgArray($profileParts)]);
        }

        $page = $query->paginate((int) ($filters['limit'] ?? 25));

        return response()->json([
            'data' => collect($page->items())->map(fn (RegulatoryDocument $d) => $this->summary($d, $profileParts))->values(),
            'meta' => [
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'profile_cfr_parts' => $profileParts,
                'profile_filter_unavailable' => $profileFilter && $profileParts === [],
            ],
        ]);
    }

    public function show(int $documentId): JsonResponse
    {
        $this->authorizePermission('regulations.view');

        $document = RegulatoryDocument::query()->with('source:id,code,name')->findOrFail($documentId);
        $versions = $document->versions()->orderByDesc('version_no')->get(['id', 'version_no', 'changes', 'retrieved_at', 'text_chars']);
        $current = $versions->firstWhere('version_no', $document->current_version);

        $outgoing = RegulatoryRelation::query()->with('to:id,external_id,title,publication_date')->where('from_document_id', $document->id)->get();
        $incoming = RegulatoryRelation::query()->with('from:id,external_id,title,publication_date')->where('to_document_id', $document->id)->get();

        return response()->json(['data' => $this->summary($document, $this->profileCfrParts()) + [
            'abstract' => $document->abstract,
            'dates_text' => $document->dates_text,
            'docket_ids' => $document->docket_ids,
            'rins' => $document->rins,
            'start_page' => $document->start_page,
            'end_page' => $document->end_page,
            'raw_text_url' => $document->raw_text_url,
            'source' => ['code' => $document->source->code, 'name' => $document->source->name],
            'first_seen_at' => $document->first_seen_at->toIso8601String(),
            'retrieved_at' => $current?->retrieved_at?->toIso8601String(),
            'passage_count' => $current ? RegulatoryPassage::query()->where('version_id', $current->id)->count() : 0,
            'versions' => $versions->map(fn ($v) => [
                'version' => $v->version_no,
                'retrieved_at' => $v->retrieved_at->toIso8601String(),
                'changed_fields' => array_keys($v->changes ?? []),
                'changes' => $v->changes,
            ])->values(),
            'relations' => [
                ...$outgoing->map(fn (RegulatoryRelation $r) => [
                    'direction' => 'outgoing',
                    'relation' => $r->relation,
                    'document_number' => $r->to_external_id,
                    'document_id' => $r->to_document_id,
                    'title' => $r->to?->title,
                    'method' => $r->method,
                ]),
                ...$incoming->map(fn (RegulatoryRelation $r) => [
                    'direction' => 'incoming',
                    'relation' => $r->relation,
                    'document_number' => $r->from->external_id,
                    'document_id' => $r->from_document_id,
                    'title' => $r->from->title,
                    'method' => $r->method,
                ]),
            ],
        ]]);
    }

    public function passages(Request $request, int $documentId): JsonResponse
    {
        $this->authorizePermission('regulations.view');
        $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'around' => ['sometimes', 'integer', 'min:1'],
        ]);

        $document = RegulatoryDocument::query()->findOrFail($documentId, ['id', 'current_version', 'text_status']);
        $versionId = $document->versions()->where('version_no', $document->current_version)->value('id');
        $q = trim((string) $request->input('q', ''));

        $query = RegulatoryPassage::query()
            ->where('version_id', $versionId)
            ->select(['id', 'ordinal', 'heading_path', 'text', 'page_start', 'page_end'])
            ->orderBy('ordinal');

        if ($q !== '') {
            // Snippet markers are plain text the web app turns into <mark>; no HTML is produced here.
            $query->whereRaw("tsv @@ websearch_to_tsquery('english', ?)", [$q])
                ->selectRaw("ts_headline('english', text, websearch_to_tsquery('english', ?), 'StartSel=[[[, StopSel=]]], MaxWords=40, MinWords=15, MaxFragments=2') as snippet", [$q]);
        }

        // Open the page that contains a cited passage.
        $pageNumber = null;
        if ($q === '' && $request->filled('around')) {
            $ordinal = RegulatoryPassage::query()->where('version_id', $versionId)->whereKey((int) $request->input('around'))->value('ordinal');
            $pageNumber = $ordinal ? (int) ceil($ordinal / 50) : null;
        }

        $page = $query->paginate(50, ['*'], 'page', $pageNumber);

        return response()->json([
            'data' => collect($page->items())->map(fn (RegulatoryPassage $p) => [
                'id' => $p->id,
                'ordinal' => $p->ordinal,
                'heading_path' => $p->heading_path,
                'text' => $p->text,
                'page_start' => $p->page_start,
                'page_end' => $p->page_end,
                'snippet' => $q !== '' ? $p->getAttribute('snippet') : null,
            ])->values(),
            'meta' => [
                'text_status' => $document->text_status,
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function facets(): JsonResponse
    {
        $this->authorizePermission('regulations.view');

        $agencies = DB::select(<<<'SQL'
            SELECT a->>'slug' AS slug, a->>'name' AS name, count(*) AS documents
            FROM regulatory_documents, jsonb_array_elements(agencies) a
            GROUP BY 1, 2 ORDER BY documents DESC, name LIMIT 40
        SQL);
        $types = RegulatoryDocument::query()->selectRaw('type, count(*) as documents')->groupBy('type')->orderByDesc('documents')->get();

        return response()->json([
            'agencies' => array_map(fn ($a) => ['slug' => $a->slug, 'name' => $a->name, 'documents' => (int) $a->documents], $agencies),
            'types' => $types->map(fn ($t) => [
                'type' => $t->type,
                'label' => RegulatoryDocument::TYPE_LABELS[$t->type] ?? $t->type,
                'documents' => (int) $t->documents,
            ])->values(),
        ]);
    }

    private function applySearch(Builder $query, string $q): void
    {
        if (preg_match('/^\d{4}-\d{4,6}$/', $q)) {
            $query->where('external_id', $q)->orderByDesc('publication_date');

            return;
        }
        if (preg_match('/^(\d{1,3})\s*FR\s*(\d{1,6})$/i', $q, $m)) {
            $query->where('citation', "{$m[1]} FR {$m[2]}")->orderByDesc('publication_date');

            return;
        }

        $query->where(function (Builder $b) use ($q) {
            $b->whereRaw("search_tsv @@ websearch_to_tsquery('english', ?)", [$q])
                ->orWhereRaw('docket_ids @> ?::jsonb', [json_encode([$q])])
                ->orWhereRaw('rins @> ?::jsonb', [json_encode([$q])]);
        })
            ->orderByRaw("ts_rank(search_tsv, websearch_to_tsquery('english', ?)) DESC", [$q])
            ->orderByDesc('publication_date');
    }

    /**
     * CFR parts tied to the programs in the organization's profile. These
     * are screening hints: a match means "this document amends a part
     * linked to a program you selected", not that it applies to you.
     *
     * @return list<string>
     */
    private function profileCfrParts(): array
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

    /** @param list<string> $values */
    private function pgArray(array $values): string
    {
        return '{'.implode(',', array_map(fn ($v) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $v).'"', $values)).'}';
    }

    /**
     * @param  list<string>  $profileParts
     * @return array<string, mixed>
     */
    private function summary(RegulatoryDocument $d, array $profileParts): array
    {
        return [
            'id' => $d->id,
            'document_number' => $d->external_id,
            'citation' => $d->citation,
            'type' => $d->type,
            'type_label' => RegulatoryDocument::TYPE_LABELS[$d->type] ?? $d->type,
            'action' => $d->action,
            'title' => $d->title,
            'agencies' => $d->agencies,
            'cfr_parts' => $d->cfr_parts,
            'profile_matches' => array_values(array_intersect($d->cfr_parts, $profileParts)),
            'publication_date' => $d->publication_date->toDateString(),
            'effective_date' => $d->effective_date?->toDateString(),
            'comments_close_date' => $d->comments_close_date?->toDateString(),
            'status' => $d->status,
            'text_status' => $d->text_status,
            'significant' => $d->significant,
            'official_pdf_url' => $d->official_pdf_url,
            'html_url' => $d->html_url,
            'is_demo' => $d->is_demo,
            'version' => $d->current_version,
            'last_changed_at' => $d->last_changed_at->toIso8601String(),
        ];
    }
}
