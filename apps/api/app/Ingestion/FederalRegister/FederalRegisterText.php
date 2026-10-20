<?php

namespace App\Ingestion\FederalRegister;

/**
 * Cleans the Federal Register "raw text" rendition (GPO text wrapped in
 * <pre>) into plain text. Page markers like [[Page 62323]] are kept: the
 * chunker uses them so citations can name the printed page.
 */
final class FederalRegisterText
{
    public static function clean(string $body): string
    {
        // Keep only the <pre> body when present.
        if (preg_match('/<pre>(.*)<\/pre>/is', $body, $m)) {
            $body = $m[1];
        }

        // Obfuscated email links become a neutral placeholder.
        $body = preg_replace('/<a[^>]*cdn-cgi\/l\/email-protection[^>]*>.*?<\/a>/is', '[email address]', $body) ?? $body;
        $body = strip_tags($body);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // GPO uses `` and '' for quotation marks.
        $body = str_replace(["``", "''"], ['"', '"'], $body);
        $body = str_replace(["\r\n", "\r"], "\n", $body);

        return trim($body);
    }
}
