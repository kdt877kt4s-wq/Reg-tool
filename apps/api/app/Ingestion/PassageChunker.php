<?php

namespace App\Ingestion;

/**
 * Splits regulatory text into passages: the unit every citation points at.
 *
 * Federal Register text is hard-wrapped at about 70 characters, starts
 * paragraphs with a four-space indent, numbers sections ("I.", "A.", "1.")
 * and marks printed pages as [[Page N]]. Passages follow paragraph
 * boundaries, carry the heading path they sit under and the printed pages
 * they span, and record offsets into the normalized text.
 */
final class PassageChunker
{
    public const TARGET_CHARS = 2400;

    public const MAX_CHARS = 4000;

    /**
     * @return array{text: string, passages: list<array{ordinal: int, heading_path: ?string, text: string, char_start: int, char_end: int, page_start: ?int, page_end: ?int}>}
     */
    public static function chunk(string $raw): array
    {
        $paragraphs = self::paragraphs($raw);

        // Normalized text = paragraphs joined by blank lines; offsets refer to it.
        $text = '';
        $passages = [];
        $buffer = [];
        $bufferLen = 0;
        $headings = [];          // level => heading text
        $bufferHeading = null;

        $flush = function () use (&$buffer, &$bufferLen, &$passages, &$bufferHeading) {
            if ($buffer === []) {
                return;
            }
            $first = $buffer[0];
            $last = $buffer[count($buffer) - 1];
            $passages[] = [
                'ordinal' => count($passages) + 1,
                'heading_path' => $bufferHeading,
                'text' => implode("\n\n", array_column($buffer, 'text')),
                'char_start' => $first['start'],
                'char_end' => $last['end'],
                'page_start' => $first['page'],
                'page_end' => $last['page_end'],
            ];
            $buffer = [];
            $bufferLen = 0;
        };

        foreach ($paragraphs as $p) {
            foreach (self::splitLong($p['text']) as $piece) {
                $start = $text === '' ? 0 : strlen($text) + 2;
                $text .= ($text === '' ? '' : "\n\n").$piece;
                $item = ['text' => $piece, 'start' => $start, 'end' => strlen($text), 'page' => $p['page'], 'page_end' => $p['page_end']];

                $level = self::headingLevel($piece);
                if ($level !== null) {
                    // A heading starts a new passage and updates the path.
                    $flush();
                    $headings = array_filter($headings, fn ($l) => $l < $level, ARRAY_FILTER_USE_KEY);
                    $headings[$level] = $piece;
                    ksort($headings);
                    $bufferHeading = substr(implode(' > ', $headings), 0, 500);
                }

                if ($bufferLen > 0 && $bufferLen + strlen($piece) > self::TARGET_CHARS) {
                    $flush();
                }
                if ($buffer === []) {
                    $bufferHeading = $headings === [] ? null : substr(implode(' > ', $headings), 0, 500);
                }
                $buffer[] = $item;
                $bufferLen += strlen($piece);
            }
        }
        $flush();

        return ['text' => $text, 'passages' => $passages];
    }

    /**
     * Unwrap hard line breaks into paragraphs, tracking printed pages.
     *
     * @return list<array{text: string, page: ?int, page_end: ?int}>
     */
    private static function paragraphs(string $raw): array
    {
        $out = [];
        $lines = [];
        $page = null;
        $startPage = null;

        $close = function () use (&$lines, &$out, &$startPage, &$page) {
            $joined = trim(preg_replace('/\s+/', ' ', implode(' ', $lines)) ?? '');
            if ($joined !== '') {
                $out[] = ['text' => $joined, 'page' => $startPage ?? $page, 'page_end' => $page];
            }
            $lines = [];
            $startPage = null;
        };

        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^\s*\[\[Page (\d+)\]\]\s*$/', $line, $m)) {
                $page = (int) $m[1];

                continue;
            }
            if (preg_match('/^[=\-]{10,}$/', trim($line))) {
                $close();

                continue;
            }
            if (trim($line) === '') {
                $close();

                continue;
            }
            // A four-space indent starts a new paragraph.
            if (preg_match('/^ {4}\S/', $line) && $lines !== []) {
                $close();
            }
            if ($lines === []) {
                $startPage = $page;
            }
            $lines[] = trim($line);
        }
        $close();

        return $out;
    }

    /** Split a paragraph longer than MAX_CHARS at sentence ends. @return list<string> */
    private static function splitLong(string $paragraph): array
    {
        if (strlen($paragraph) <= self::MAX_CHARS) {
            return [$paragraph];
        }
        $sentences = preg_split('/(?<=[.;:])\s+(?=[A-Z(§"])/', $paragraph) ?: [$paragraph];
        $pieces = [];
        $current = '';
        foreach ($sentences as $s) {
            if ($current !== '' && strlen($current) + strlen($s) + 1 > self::TARGET_CHARS) {
                $pieces[] = $current;
                $current = '';
            }
            $current = $current === '' ? $s : $current.' '.$s;
        }
        if ($current !== '') {
            $pieces[] = $current;
        }

        return $pieces;
    }

    /**
     * Heading levels: 1 = "I." (roman), 2 = "A.", 3 = "1.", 4 = "§ 422.111".
     * Headings are short and do not end like a sentence.
     */
    public static function headingLevel(string $paragraph): ?int
    {
        if (strlen($paragraph) > 160) {
            return null;
        }
        if (preg_match('/^§+\s*\d+[\w.\-]*\s+\S/u', $paragraph)) {
            return 4;
        }
        if (str_ends_with($paragraph, '.') && ! preg_match('/^[IVXLC]+\.\s+\S+$/', $paragraph)) {
            return null;
        }

        return match (true) {
            (bool) preg_match('/^[IVXLC]+\.\s+[A-Z]/', $paragraph) => 1,
            (bool) preg_match('/^[A-Z]\.\s+[A-Z]/', $paragraph) => 2,
            (bool) preg_match('/^\d{1,2}\.\s+[A-Z]/', $paragraph) => 3,
            default => null,
        };
    }
}
