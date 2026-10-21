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

## Regulatory monitoring

```bash
cd apps/api
php artisan ruleradar:poll federal_register            # from the last check (first run: 60 days)
php artisan ruleradar:poll federal_register --days=30  # look back a fixed window
php artisan queue:work                                 # fetches full text into passages
php artisan schedule:work                              # weekday 6:30 and 12:30 CT, nightly 30-day look back
php artisan ruleradar:source-report                    # what has been ingested
```

RuleRadar monitors rules and proposed rules from the whole HHS family (the HHS Office for Civil
Rights and ONC/ASTP publish under the department) plus CMS and OIG notices. Agency identifiers and
response fields are verified against recorded real responses (`apps/api/tests/Fixtures/federal_register`,
refreshed by the "Record Federal Register fixtures" workflow), and every CI run also polls the
live Federal Register and reports the result.

## AI impact analysis

A compliance officer or administrator starts an analysis from a publication's page. Claude
(`claude-sonnet-5-5`, Messages API with a forced tool call) receives only stored passages, each
labelled P1, P2, ... inside data delimiters, plus the organization's profile. Its structured
answer is then checked in code (`app/Analysis/AnalysisValidator.php`): unknown citations,
uncited claims about the regulation, quotes that are not in the cited passage, deadlines not
stated in the source and uncited actions are removed, reported on the page, and lower the
confidence. A compliance officer approves the human assessment; the AI output is never edited.

Configuration (server-side only, never in the web app):

| Variable | Purpose |
| --- | --- |
| `ANTHROPIC_API_KEY` | Required for analyses. Without it the page says AI is not set up. |
| `VOYAGE_API_KEY` | Optional. Adds semantic passage retrieval (`voyage-4`, 1024 dims). |
| `AI_ANALYSIS_MODEL` | Optional override of the model ID. |

To try it against the real Claude before deployment, add `ANTHROPIC_API_KEY` (and optionally
`VOYAGE_API_KEY`) as GitHub repository secrets, then run the **AI live check** workflow. It
analyzes a real publication in a throwaway CI database and reports what the validator kept and
removed.

## Tasks, evidence and notifications

Approving an impact assessment turns each accepted action into a task. When the publication
has a published effective date in the future, it becomes the task's **official** due date, with
its source recorded; otherwise the task has no due date until someone sets one (marked
**internal** unless they cite the rule that sets it). Tasks move open, in progress, submitted,
returned or done; submitting needs at least one piece of evidence, and the person who submitted
cannot accept their own work. Every change is kept in the task history and the audit log.

Evidence files go to a private disk (`EVIDENCE_DISK`, local in development), limited to 25 MB
and an allowlist of document, image, email and archive types, with a SHA-256 recorded. Files
are downloaded only through the API by people who can see the task. Set
`EVIDENCE_REQUIRE_SCAN=true` to hold files back until a scanner marks them clean (Stage 5 wires
Microsoft Defender for Storage); until then the interface labels files "Not scanned".

Notifications appear in the app and, unless a person turns a kind off, by email. A daily job
(`php artisan ruleradar:daily-notifications`, scheduled 07:00 Central) sends due-soon and
overdue reminders, a digest of new publications that match the profile, and alerts when a
document changes after its assessment was approved. Each is sent once.

## Deployment

RuleRadar has two deployable parts: the web app (`apps/web`, can run on Vercel) and the API
(`apps/api`, a container with PostgreSQL, Redis, a worker and a scheduler). The web app needs
the API: set `API_URL` in the web app's hosting to the API's HTTPS address.
**[docs/deploy.md](docs/deploy.md)** walks through deploying the API to Azure with the
"Deploy API to Azure" workflow and connecting Vercel to it.

## Reports and security operations

- Reports (administrators, compliance officers, auditors): regulatory activity, task status
  and a per-assessment evidence pack with an audit-chain check. Print or save as PDF; CSV
  for the first two. Every report generated is audit-logged.
- `ruleradar:audit-verify` (daily 03:30 Central) re-checks every organization's audit hash
  chain; `ruleradar:prune` (03:45) removes read notifications older than
  `NOTIFICATION_RETENTION_DAYS` (180) and expired sign-in tokens.
- Evidence scanning: Defender for Storage results arrive at
  `POST /api/v1/webhooks/malware-scan?key=...` (Event Grid). Infected files are blocked.
- Web pages send a nonce-based Content Security Policy; the API sends restrictive headers.

## Tests

```bash
cd apps/api && vendor/bin/pest      # needs the docker Postgres (database ruleradar_test)
cd apps/web && npm test && npm run typecheck && npm run lint
```

CI runs both suites, a browser test of the whole MVP path (Playwright, `apps/web/e2e`) against
the real API and database, image builds, a Bicep compile and dependency audits on every push.
The browser test uses recorded Federal Register responses and an `e2e`-only scripted model,
labelled as test output, instead of Claude.

## Implementation stages

| Stage | Scope | Status |
| --- | --- | --- |
| 0 | Foundations: monorepo, dev stack, CI, taxonomy, ADRs | Done |
| 1 | Tenancy, auth, MFA, roles, audit log, profile wizard | Done |
| 2 | Federal Register ingestion and Regulatory Intelligence | Done |
| 3 | AI analysis with citations, review and approval | Done |
| 4 | Tasks, evidence, calendar, notifications, dashboard | Done |
| 5 | Reports, hardening, Azure deploy, end-to-end tests | Done (pilot deployment pending) |

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
