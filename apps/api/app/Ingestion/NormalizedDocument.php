<?php

namespace App\Ingestion;

/**
 * A regulatory publication in RuleRadar's own shape, independent of the
 * source it came from. Every value comes from the source; nothing is
 * inferred (a missing effective date stays null).
 */
final readonly class NormalizedDocument
{
    /**
     * @param  list<array{slug: string, name: string}>  $agencies
     * @param  list<string>  $cfrParts  e.g. ["42 CFR 422"]
     * @param  list<string>  $docketIds
     * @param  list<string>  $rins
     * @param  ?string  $relatesTo  document number named in a structured field
     */
    public function __construct(
        public string $externalId,
        public string $type,
        public ?string $action,
        public string $title,
        public ?string $abstract,
        public ?string $datesText,
        public array $agencies,
        public array $cfrParts,
        public array $docketIds,
        public array $rins,
        public ?string $citation,
        public ?int $startPage,
        public ?int $endPage,
        public ?bool $significant,
        public string $publicationDate,
        public ?string $effectiveDate,
        public ?string $commentsCloseDate,
        public ?string $officialPdfUrl,
        public ?string $htmlUrl,
        public ?string $rawTextUrl,
        public ?string $relation = null,
        public ?string $relatesTo = null,
    ) {}

    /** The fields whose change makes a new version. @return array<string, mixed> */
    public function metadata(): array
    {
        return [
            'type' => $this->type,
            'action' => $this->action,
            'title' => $this->title,
            'abstract' => $this->abstract,
            'dates_text' => $this->datesText,
            'agencies' => $this->agencies,
            'cfr_parts' => $this->cfrParts,
            'docket_ids' => $this->docketIds,
            'rins' => $this->rins,
            'citation' => $this->citation,
            'start_page' => $this->startPage,
            'end_page' => $this->endPage,
            'significant' => $this->significant,
            'publication_date' => $this->publicationDate,
            'effective_date' => $this->effectiveDate,
            'comments_close_date' => $this->commentsCloseDate,
            'official_pdf_url' => $this->officialPdfUrl,
            'html_url' => $this->htmlUrl,
            'raw_text_url' => $this->rawTextUrl,
        ];
    }

    public function hash(): string
    {
        return hash('sha256', json_encode($this->metadata(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
