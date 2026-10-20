# ADR 0002: Shared-database multi-tenancy, enforced three times

Status: Accepted (2026-10-20)

## Decision
All tenants share one PostgreSQL database. Every tenant-owned row carries `organization_id`.
Isolation is enforced by:
1. a global Eloquent scope on every tenant model,
2. a Laravel Policy on every endpoint,
3. PostgreSQL row-level security keyed on a per-request `app.organization_id` setting.

Missing tenant context fails closed. Regulatory source content (documents, versions, passages)
is global and shared, because it is public and ingesting it once per tenant would waste cost.

## Why
One database keeps operations, migrations and backups simple at MVP scale. RLS means a missed
scope in application code still cannot return another tenant's rows.

## Consequences
- CI includes cross-tenant access tests that try to read another organization's records (Stage 1).
- A dedicated-database option for Enterprise can be added behind the same tenant resolver later.
