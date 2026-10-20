<?php

namespace App\Ingestion\Contracts;

use App\Ingestion\NormalizedDocument;
use App\Ingestion\SourceUnavailable;
use Carbon\CarbonImmutable;

/**
 * Every monitored source (Federal Register now; eCFR and state regulators
 * later) implements this. The ingestion pipeline after normalization is
 * shared.
 */
interface SourceAdapter
{
    public function code(): string;

    /**
     * Publications in [from, to], normalized.
     *
     * @return iterable<NormalizedDocument>
     *
     * @throws SourceUnavailable when the source cannot be read
     */
    public function discover(CarbonImmutable $from, CarbonImmutable $to): iterable;

    /**
     * The publication's full text as plain text, or null when the source
     * offers none.
     *
     * @throws SourceUnavailable
     */
    public function fetchText(string $rawTextUrl): ?string;
}
