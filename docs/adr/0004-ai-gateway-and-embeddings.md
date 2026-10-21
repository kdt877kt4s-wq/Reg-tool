# ADR 0004: Server-side AI gateway, citation-validated output

Status: Accepted (2026-10-20), updated 2026-10-21 with the Stage 3 implementation

## Decision
- All model calls go through one `AiGateway` service, run from queued jobs in the API.
- Claude Sonnet handles summaries, impact and applicability; Claude Haiku handles classification.
  Model names are configuration, not code.
- Output is structured JSON validated against a schema. Every claim cites stored passage IDs;
  output whose citations do not resolve to the analysed document version is rejected.
- Embeddings default to Voyage AI behind an `Embedder` interface (Anthropic offers no embedding
  endpoint). Azure OpenAI embeddings are the alternative if a single-vendor Azure setup is
  preferred.

## As built (Stage 3)
- Model `claude-sonnet-5-5` via `POST /v1/messages`, `anthropic-version: 2023-06-01`, tool forced
  with `tool_choice: {type: "tool"}`; retries on 429, 529 and 5xx.
- Embeddings: Voyage `voyage-4` at 1024 dimensions, optional; without a key, retrieval uses
  PostgreSQL full-text ranking. Rankings are combined with reciprocal-rank fusion.
- Passages are sent as `<passage ref="P1">` blocks; delimiter-like text inside is escaped.
- Validation removes unverifiable statements and records why; output with the wrong shape is
  retried once, then the analysis is marked failed for manual review.
- Analyses run in the requesting organization's tenant context, so row-level security applies.
- One analysis per document version, profile version and prompt version unless rerun.

## Why
Traceability is the product's core promise. Validation in code, not just instructions in a
prompt, is what makes "every claim is cited" true.

## Consequences
- Prompt templates are versioned in the repo and the version is stored on every analysis.
- AI output is always a draft until a Compliance Officer approves it; it is labelled
  "AI analysis, not legal advice".
