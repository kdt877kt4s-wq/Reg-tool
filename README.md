# RuleRadar

AI-assisted regulatory intelligence and compliance workflow for US healthcare organizations.
Know what changed. Understand what matters. Stay ahead of compliance.

RuleRadar is not legal advice and makes no SOC 2, HIPAA or other attestation claims.
The MVP does not process protected health information.

## Repository layout

| Path | What it is |
| --- | --- |
| `apps/api` | Laravel 13 API, queue workers and scheduler |
| `apps/web` | Next.js web app (TypeScript, Tailwind CSS, shadcn/ui) |
| `infra/main.bicep` | Azure resources per environment |
| `docs/adr` | Architecture decision records |
| `docker-compose.yml` | Local Postgres (pgvector), Redis, Mailpit, Azurite |

The full architecture and MVP plan lives in the shared planning doc.

## Run it locally

Requirements: PHP 8.3 with `pdo_pgsql` and `redis`, Composer 2, Node 22, Docker.

```bash
docker compose up -d        # first run creates the ruleradar_app role and databases

cd apps/api
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve            # http://localhost:8000

cd ../web
cp .env.example .env.local
npm install
npm run dev                  # http://localhost:3000
```

Mail sent locally appears at http://localhost:8025.

## Tests

```bash
cd apps/api && vendor/bin/pest      # needs the docker Postgres (database ruleradar_test)
cd apps/web && npm test && npm run typecheck && npm run lint
```

CI runs both suites, a Bicep compile and dependency audits on every push and pull request.

## Implementation stages

| Stage | Scope | Status |
| --- | --- | --- |
| 0 | Foundations: monorepo, dev stack, CI, taxonomy, ADRs | Done |
| 1 | Tenancy, auth, MFA, roles, audit log, profile wizard | Done |
| 2 | Federal Register ingestion and Regulatory Intelligence | Planned |
| 3 | AI analysis with citations, review and approval | Planned |
| 4 | Tasks, evidence, calendar, notifications, dashboard | Planned |
| 5 | Reports, hardening, Azure deploy, pilot | Planned |

## Security model in brief

- The web server holds API tokens; the browser only has an HttpOnly cookie (ADR 0005).
- The API connects to PostgreSQL as `ruleradar_app`, which is not a superuser, so row-level
  security isolates every organization's rows (ADR 0002). Tests run the same way.
- Organization administrators and compliance officers must use two-factor login.
- Organization actions are written to a hash-chained, append-only audit log.

If your local database was created before Stage 1, recreate it so the app role exists:
`docker compose down -v && docker compose up -d`.

## Data integrity rules

- Regulations shown in RuleRadar come from stored official records with source URLs.
- Demonstration data, when enabled, is flagged `is_demo` and labelled in the interface.
- AI-generated analysis is labelled and stays a draft until a person approves it.

## Known gaps in Stage 0

- No lockfiles yet (`composer.lock`, `package-lock.json`): they were authored without registry
  access. Generate and commit them on the first local install so CI builds are reproducible.
