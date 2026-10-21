<?php

namespace App\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV downloads that open safely in spreadsheet software. */
final class Csv
{
    /**
     * @param  list<string>  $header
     * @param  iterable<list<scalar|null>>  $rows
     */
    public static function download(string $filename, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 byte order mark for Excel
            fputcsv($out, $header, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Neutralize spreadsheet formulas (CSV injection): text starting with = + - @ tab or CR. */
    public static function cell(mixed $value): string
    {
        $text = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };

        return preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }
}
