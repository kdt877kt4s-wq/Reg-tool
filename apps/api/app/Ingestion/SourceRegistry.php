<?php

namespace App\Ingestion;

use App\Ingestion\Contracts\SourceAdapter;
use App\Ingestion\FederalRegister\FederalRegisterAdapter;
use InvalidArgumentException;

/** Maps a source code to its adapter. New sources are registered here. */
final class SourceRegistry
{
    public function adapter(string $code): SourceAdapter
    {
        return match ($code) {
            'federal_register' => new FederalRegisterAdapter(config('sources.federal_register')),
            default => throw new InvalidArgumentException("Unknown source: {$code}"),
        };
    }
}
