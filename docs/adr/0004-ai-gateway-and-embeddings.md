# ADR 0004: Server-side AI gateway, citation-validated output

Status: Accepted (2026-10-20); embeddings vendor open for confirmation

## Decision
- All model calls go through one `AiGateway` service, run from queued jobs in the API.
- Claude Sonnet handles summaries, impact and applicability; Claude Haiku handles classification.
  Model names are configuration, not code.
- Output is structured JSON validated against a schema. Every claim cites stored passage IDs;
  output whose citations do not resolve to the analysed document version is rejected.
- Embeddings default to Voyage AI behind an `Embedder` interface (Anthropic offers no embedding
  endpoint). Azure OpenAI embeddings are the alternative if a single-vendor Azure setup is
  preferred.

## Why
Traceability is the product's core promise. Validation in code, not just instructions in a
prompt, is what makes "every claim is cited" true.

## Consequences
- Prompt templates are versioned in the repo and the version is stored on every analysis.
- AI output is always a draft until a Compliance Officer approves it; it is labelled
  "AI analysis, not legal advice".
