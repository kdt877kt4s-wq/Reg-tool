<?php

namespace App\Analysis;

use Illuminate\Support\Carbon;

/**
 * Checks model output against what was actually provided, in code:
 *   - every citation must name a passage that was sent,
 *   - statements restating the regulation must cite at least one passage,
 *   - a quote must appear in a cited passage,
 *   - a deadline must be a date the source record states or a cited
 *     passage contains,
 *   - every suggested action must cite at least one passage.
 * Statements that fail are removed and reported; confidence is lowered.
 * Output whose shape is wrong is rejected outright.
 */
final class AnalysisValidator
{
    private const CLAIM_SECTIONS = ['what_changed', 'why_it_matters', 'operational_impacts', 'policies_to_review'];

    /** @var list<array{section: string, text: string, reason: string}> */
    private array $removed = [];

    /**
     * @param  array<string, mixed>  $input  the model's tool input
     * @param  array<string, int>  $refs  "P1" => passage id
     * @param  array<int, string>  $passageText  passage id => text
     * @param  list<string>  $statedDates  YYYY-MM-DD dates in the source record
     * @param  list<string>  $allowedFunctions
     * @return array{output: array<string, mixed>, validation: array<string, mixed>, confidence: string}
     *
     * @throws InvalidAnalysis
     */
    public function validate(array $input, array $refs, array $passageText, array $statedDates, array $allowedFunctions): array
    {
        $this->removed = [];
        $this->assertShape($input);

        $output = [];
        $kept = 0;

        foreach (self::CLAIM_SECTIONS as $section) {
            $output[$section] = $this->claims($section, $input[$section], $refs, $passageText);
            $kept += count($output[$section]);
        }

        $app = $input['applicability'];
        $reasons = $this->claims('applicability', $app['reasons'] ?? [], $refs, $passageText);
        $kept += count($reasons);
        $output['applicability'] = [
            'level' => $app['level'],
            'reasons' => $reasons,
            'missing_facts' => array_values(array_filter(array_map(fn ($f) => is_string($f) ? trim($f) : '', $app['missing_facts'] ?? []))),
        ];

        $output['affected_functions'] = array_values(array_intersect(
            array_unique(array_filter($input['affected_functions'], 'is_string')),
            $allowedFunctions,
        ));

        $output['deadlines'] = [];
        foreach ($input['deadlines'] as $deadline) {
            $ids = $this->resolve($deadline['citations'] ?? [], $refs);
            $date = is_string($deadline['date'] ?? null) ? $deadline['date'] : '';
            $label = trim((string) ($deadline['label'] ?? ''));

            if ($ids === null || ! $this->isDate($date) || $label === '') {
                $this->remove('deadlines', $label ?: $date, 'The deadline was malformed or cited a passage that was not provided.');

                continue;
            }
            $stated = in_array($date, $statedDates, true);
            if (! $stated && ! $this->dateInPassages($date, $ids, $passageText)) {
                $this->remove('deadlines', "{$label}: {$date}", 'This date is not in the source record or in the cited passages.');

                continue;
            }
            $output['deadlines'][] = [
                'date' => $date,
                'label' => $label,
                'citations' => $ids,
                'in_source_record' => $stated,
            ];
            $kept++;
        }

        $output['suggested_actions'] = [];
        foreach ($input['suggested_actions'] as $action) {
            $ids = $this->resolve($action['citations'] ?? [], $refs);
            $title = trim((string) ($action['title'] ?? ''));
            if ($title === '' || $ids === null || $ids === []) {
                $this->remove('suggested_actions', $title, 'Suggested actions must cite the passages they are based on.');

                continue;
            }
            $output['suggested_actions'][] = [
                'title' => mb_substr($title, 0, 200),
                'description' => trim((string) ($action['description'] ?? '')),
                'priority' => in_array($action['priority'] ?? null, ['high', 'medium', 'low'], true) ? $action['priority'] : 'medium',
                'citations' => $ids,
            ];
            $kept++;
        }

        $confidence = $this->confidence((string) $input['confidence'], $kept, $output);

        return [
            'output' => $output + [
                'model_confidence' => $input['confidence'],
                'confidence_reason' => trim((string) ($input['confidence_reason'] ?? '')),
            ],
            'validation' => [
                'removed' => $this->removed,
                'kept' => $kept,
            ],
            'confidence' => $confidence,
        ];
    }

    /**
     * @param  array<string, int>  $refs
     * @param  array<int, string>  $passageText
     * @return list<array<string, mixed>>
     */
    private function claims(string $section, mixed $claims, array $refs, array $passageText): array
    {
        $out = [];
        foreach (is_array($claims) ? $claims : [] as $claim) {
            $text = trim((string) ($claim['text'] ?? ''));
            $basis = $claim['basis'] ?? null;
            if ($text === '' || ! in_array($basis, ['regulatory_text', 'ai_interpretation'], true)) {
                $this->remove($section, $text, 'The statement was empty or had no basis.');

                continue;
            }

            $ids = $this->resolve($claim['citations'] ?? [], $refs);
            if ($ids === null) {
                $this->remove($section, $text, 'It cited a passage that was not provided.');

                continue;
            }
            if ($basis === 'regulatory_text' && $ids === []) {
                $this->remove($section, $text, 'It describes the regulation but cites no passage.');

                continue;
            }

            $quote = trim((string) ($claim['quote'] ?? ''));
            if ($quote !== '' && ! $this->quoteInPassages($quote, $ids, $passageText)) {
                $this->remove($section, $text, 'Its quotation does not appear in the cited passages.');

                continue;
            }

            $out[] = array_filter([
                'text' => mb_substr($text, 0, 2000),
                'basis' => $basis,
                'citations' => $ids,
                'quote' => $quote !== '' ? $quote : null,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }

    /**
     * Map refs to passage ids. Null when any ref was not provided.
     *
     * @param  array<string, int>  $refs
     * @return list<int>|null
     */
    private function resolve(mixed $citations, array $refs): ?array
    {
        if (! is_array($citations)) {
            return null;
        }
        $ids = [];
        foreach ($citations as $ref) {
            if (! is_string($ref) || ! isset($refs[$ref])) {
                return null;
            }
            $ids[] = $refs[$ref];
        }

        return array_values(array_unique($ids));
    }

    /** @param list<int> $ids @param array<int, string> $passageText */
    private function quoteInPassages(string $quote, array $ids, array $passageText): bool
    {
        $needle = self::normalize($quote);
        foreach ($ids as $id) {
            if ($needle !== '' && str_contains(self::normalize($passageText[$id] ?? ''), $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<int> $ids @param array<int, string> $passageText */
    private function dateInPassages(string $date, array $ids, array $passageText): bool
    {
        $d = Carbon::parse($date);
        $forms = [$d->format('F j, Y'), $d->format('F j,Y'), $d->format('n/j/Y'), $d->format('m/d/Y'), $date];
        foreach ($ids as $id) {
            $text = self::normalize($passageText[$id] ?? '');
            foreach ($forms as $form) {
                if (str_contains($text, self::normalize($form))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
    }

    /** @param array<string, mixed> $output */
    private function confidence(string $model, int $kept, array $output): string
    {
        $rank = ['low' => 0, 'medium' => 1, 'high' => 2];
        $level = $rank[$model] ?? 0;

        $removed = count($this->removed);
        if ($removed > 0) {
            $level = min($level, 1);
        }
        $citations = collect($output)->flatten()->filter(fn ($v) => is_int($v))->count();
        if ($kept === 0 || $removed > max(1, intdiv($kept + $removed, 3)) || $citations < 2) {
            $level = 0;
        }

        return array_search($level, $rank, true) ?: 'low';
    }

    private function remove(string $section, string $text, string $reason): void
    {
        $this->removed[] = ['section' => $section, 'text' => mb_substr($text, 0, 300), 'reason' => $reason];
    }

    /** @param array<string, mixed> $input */
    private function assertShape(array $input): void
    {
        foreach ([...self::CLAIM_SECTIONS, 'affected_functions', 'deadlines', 'suggested_actions'] as $key) {
            if (! isset($input[$key]) || ! is_array($input[$key])) {
                throw new InvalidAnalysis("The analysis is missing the \"{$key}\" list.");
            }
        }
        if (! is_array($input['applicability'] ?? null) || ! in_array($input['applicability']['level'] ?? null, ['likely', 'possible', 'unlikely'], true)) {
            throw new InvalidAnalysis('The analysis has no valid applicability level.');
        }
        if (! in_array($input['confidence'] ?? null, ['high', 'medium', 'low'], true)) {
            throw new InvalidAnalysis('The analysis has no valid confidence level.');
        }
    }

    /** Lowercase, straight quotes, single spaces: tolerant of wrapping, strict about words. */
    public static function normalize(string $text): string
    {
        $text = str_replace(['“', '”', '‘', '’', '``', "''"], ['"', '"', "'", "'", '"', '"'], $text);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text)) ?? '');
    }
}
