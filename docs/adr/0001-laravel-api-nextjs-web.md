# ADR 0001: Laravel API with a separate Next.js web app

Status: Accepted (2026-10-20)

## Decision
The backend is a Laravel 13 API (`apps/api`) with queue workers and the scheduler.
The frontend is a Next.js App Router app (`apps/web`) in TypeScript with Tailwind CSS and shadcn/ui.
They live in one monorepo and deploy as separate containers.

## Why
- Laravel provides authentication (Sanctum), authorization policies, form-request validation,
  queues with retries (Horizon) and a scheduler out of the box. Most of RuleRadar's MVP is
  workflow and records, which this covers well.
- Next.js server components let the web app call the API server-side, so the API location and
  session handling stay off the client bundle.

## Consequences
- Two languages in the repo (PHP and TypeScript). Shared contracts are kept in the API's
  versioned REST surface (`/api/v1`), with typed clients in `apps/web/src/lib`.
- AI calls are made only by API workers. The web app never holds model or embedding keys.
