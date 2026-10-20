<?php

namespace App\Ingestion;

use App\Ingestion\FederalRegister\FederalRegisterAdapter;

/** Finds which earlier Federal Register document a correction or withdrawal refers to. */
final class FederalRegisterReferences
{
    public static function relationFor(?string $action): ?string
    {
        return FederalRegisterAdapter::relationFromAction($action);
    }

    /**
     * The first "FR Doc. YYYY-NNNNN" in the text that is not the document's
     * own number (its own number appears in the filing footer).
     */
    public static function firstReferencedDocument(string $text, string $ownNumber): ?string
    {
        if (! preg_match_all('/FR\s+Doc\.?\s+(?:No\.?\s+)?(\d{4}-\d{4,6})/i', $text, $matches)) {
            return null;
        }

        foreach ($matches[1] as $number) {
            if ($number !== $ownNumber) {
                return $number;
            }
        }

        return null;
    }
}
