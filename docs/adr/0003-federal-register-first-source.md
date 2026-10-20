# ADR 0003: Federal Register API is the first monitored source

Status: Accepted (2026-10-20)

## Decision
Stage 2 ingests from the Federal Register API (`https://www.federalregister.gov/api/v1`), which
needs no API key, filtered to healthcare agencies (CMS, HHS, HHS OCR, OIG, ONC/ASTP).
Other sources (eCFR, state Medicaid agencies, state insurance regulators) implement the same
`SourceAdapter` contract later.

## Provenance rule
FederalRegister.gov states that its XML rendition is not the official legal edition; the GovInfo
PDF is. RuleRadar labels the GovInfo PDF as the official source and the FederalRegister.gov page
as the readable rendition, and records the retrieval time for each document version.

## Integrity rules
- Upsert by (source, document number): no duplicate documents, so no duplicate alerts.
- Publication date and effective date are stored separately and never inferred from each other.
- When a source is unreachable the run is recorded as failed and the source is marked degraded.
  RuleRadar never shows placeholder or invented updates.
