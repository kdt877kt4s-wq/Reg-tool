<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TaxonomyTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxonomyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => ['sometimes', 'string', Rule::in(TaxonomyTerm::KINDS)],
            'industry' => ['sometimes', 'string', 'max:40'],
        ]);

        $terms = TaxonomyTerm::query()
            ->where('industry', $validated['industry'] ?? 'healthcare')
            ->when($validated['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get(['kind', 'code', 'label', 'description', 'metadata']);

        return response()->json(['data' => $terms]);
    }
}
