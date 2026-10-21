<?php

namespace App\Analysis;

use App\Ai\AiRequestFailed;
use App\Ai\Embedder;
use App\Models\RegulatoryPassage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Chooses which passages of one document version the model sees:
 *   1. front matter (summary, dates, the opening of the preamble),
 *   2. regulatory text (amended CFR sections: headings with §),
 *   3. the passages most relevant to the organization's profile, ranked by
 *      full-text match and, when embeddings exist, by meaning, combined
 *      with reciprocal-rank fusion.
 * Within the budget; returned in document order.
 */
final class PassageSelector
{
    public function __construct(private Embedder $embedder) {}

    /** @return Collection<int, RegulatoryPassage> */
    public function select(int $versionId, string $profileQuery, int $maxPassages, int $maxChars): Collection
    {
        $all = RegulatoryPassage::query()
            ->where('version_id', $versionId)
            ->orderBy('ordinal')
            ->get(['id', 'ordinal', 'heading_path', 'text', 'page_start', 'page_end']);

        if ($all->isEmpty()) {
            return $all;
        }

        $chosen = [];
        $chars = 0;
        $add = function (RegulatoryPassage $p) use (&$chosen, &$chars, $maxPassages, $maxChars): bool {
            if (isset($chosen[$p->id])) {
                return true;
            }
            if (count($chosen) >= $maxPassages || $chars + strlen($p->text) > $maxChars) {
                return false;
            }
            $chosen[$p->id] = $p;
            $chars += strlen($p->text);

            return true;
        };

        // 1. Front matter.
        foreach ($all->take(4) as $p) {
            $add($p);
        }

        // 2. Regulatory text, up to a third of the passage budget.
        $regulatory = $all->filter(fn ($p) => $p->heading_path && str_contains($p->heading_path, '§'))->take(intdiv($maxPassages, 3));
        foreach ($regulatory as $p) {
            $add($p);
        }

        // 3. Relevance to the profile.
        foreach ($this->rankByRelevance($versionId, $profileQuery, $all) as $id) {
            if (! $add($all->firstWhere('id', $id))) {
                break;
            }
        }

        return collect($chosen)->sortBy('ordinal')->values();
    }

    /** @param Collection<int, RegulatoryPassage> $all @return list<int> passage ids, best first */
    private function rankByRelevance(int $versionId, string $profileQuery, Collection $all): array
    {
        $rankings = [];

        $terms = self::tsTerms($profileQuery);
        if ($terms !== '') {
            $rankings[] = array_map('intval', DB::table('regulatory_passages')
                ->where('version_id', $versionId)
                ->whereRaw("tsv @@ to_tsquery('english', ?)", [$terms])
                ->orderByRaw("ts_rank(tsv, to_tsquery('english', ?)) DESC", [$terms])
                ->limit(60)
                ->pluck('id')
                ->all());
        }

        if ($this->embedder->enabled() && DB::table('regulatory_passages')->where('version_id', $versionId)->whereNotNull('embedding')->exists()) {
            try {
                $query = Embedder::literal($this->embedder->embed([$profileQuery], 'query')[0]);
                $rankings[] = array_map('intval', DB::table('regulatory_passages')
                    ->where('version_id', $versionId)
                    ->whereNotNull('embedding')
                    ->orderByRaw('embedding <=> ?::vector', [$query])
                    ->limit(60)
                    ->pluck('id')
                    ->all());
            } catch (AiRequestFailed) {
                // Full-text ranking alone is still a sound selection.
            }
        }

        // Reciprocal-rank fusion (k = 60); document order breaks ties.
        $scores = [];
        foreach ($rankings as $ranking) {
            foreach ($ranking as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0) + 1 / (60 + $rank + 1);
            }
        }
        arsort($scores);
        $ranked = array_keys($scores);

        // Then the rest of the document in order, so short documents are covered fully.
        return array_values(array_unique([...$ranked, ...$all->pluck('id')->all()]));
    }

    /** "Medicare Advantage; claims" -> "medicare | advantage | claims" (safe for to_tsquery). */
    public static function tsTerms(string $text): string
    {
        preg_match_all('/[a-z][a-z0-9]{2,}/', strtolower($text), $m);
        $stop = ['and', 'the', 'for', 'with', 'our', 'organization', 'program', 'programs', 'other'];

        return implode(' | ', array_slice(array_values(array_diff(array_unique($m[0]), $stop)), 0, 40));
    }
}
