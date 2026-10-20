-- Runs once, as the postgres superuser, when the local container is first created.
--
-- The application connects as ruleradar_app: it owns the schema but is not a
-- superuser and cannot bypass row-level security, so the tenant-isolation
-- policies apply to every query it makes. Extensions are created here
-- because creating them needs superuser rights.

CREATE ROLE ruleradar_app LOGIN PASSWORD 'ruleradar_app' NOSUPERUSER NOBYPASSRLS NOCREATEROLE;

CREATE DATABASE ruleradar OWNER ruleradar_app;
CREATE DATABASE ruleradar_test OWNER ruleradar_app;

\connect ruleradar
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

\connect ruleradar_test
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
