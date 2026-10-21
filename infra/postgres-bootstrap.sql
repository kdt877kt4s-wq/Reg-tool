-- One-time database setup for a deployed environment. Run as the server
-- administrator, connected to the "ruleradar" database:
--
--   psql "host=<server> dbname=ruleradar user=<admin> sslmode=require" \
--        -v app_password="<from Key Vault>" -f infra/postgres-bootstrap.sql
--
-- Safe to run again. It creates the role the API connects as. That role
-- owns the schema but is not a superuser and cannot bypass row-level
-- security, so tenant isolation applies to every query the API makes
-- (ADR 0002). The API's health check and tests rely on this.

\set ON_ERROR_STOP on

SELECT format('CREATE ROLE ruleradar_app LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB PASSWORD %L', :'app_password')
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'ruleradar_app') \gexec

SELECT format('ALTER ROLE ruleradar_app LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEROLE NOCREATEDB PASSWORD %L', :'app_password') \gexec

-- Extensions need administrator rights (they are allow-listed in Bicep).
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- The app role owns the database and its public schema, so migrations
-- create tables it owns; FORCE ROW LEVEL SECURITY applies to owners too.
GRANT ruleradar_app TO CURRENT_USER;
ALTER DATABASE ruleradar OWNER TO ruleradar_app;
ALTER SCHEMA public OWNER TO ruleradar_app;
