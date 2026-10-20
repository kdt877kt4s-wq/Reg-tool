<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-level security: the database's own guarantee of tenant isolation,
 * behind the application's global scopes and policies.
 *
 * Every request sets three session settings (see App\Tenancy\TenantContext):
 *   app.user_id          the authenticated user, or ''
 *   app.organization_id  the organization of this request, or ''
 *   app.bypass_rls       'on' only for system work (migrations, seeders,
 *                        registration, scheduled jobs), otherwise 'off'
 *
 * FORCE makes the policies apply to the table owner too. The application
 * must connect as a role without SUPERUSER or BYPASSRLS, otherwise
 * PostgreSQL skips these policies entirely.
 */
return new class extends Migration
{
    private const TENANT_TABLES = [
        'departments',
        'invitations',
        'organization_profiles',
        'organization_profile_versions',
        'subscriptions',
        'audit_events',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rr_org() RETURNS bigint LANGUAGE sql STABLE AS
              $$ SELECT NULLIF(current_setting('app.organization_id', true), '')::bigint $$;
            CREATE OR REPLACE FUNCTION rr_user() RETURNS bigint LANGUAGE sql STABLE AS
              $$ SELECT NULLIF(current_setting('app.user_id', true), '')::bigint $$;
            CREATE OR REPLACE FUNCTION rr_bypass() RETURNS boolean LANGUAGE sql STABLE AS
              $$ SELECT COALESCE(current_setting('app.bypass_rls', true), '') = 'on' $$;
        SQL);

        foreach (self::TENANT_TABLES as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY tenant_isolation ON {$table}
                  USING (rr_bypass() OR organization_id = rr_org())
                  WITH CHECK (rr_bypass() OR organization_id = rr_org());
            SQL);
        }

        // A user can always see their own memberships (to list and switch
        // organizations) but can only write inside the current organization.
        DB::unprepared(<<<'SQL'
            ALTER TABLE memberships ENABLE ROW LEVEL SECURITY;
            ALTER TABLE memberships FORCE ROW LEVEL SECURITY;
            CREATE POLICY membership_read ON memberships FOR SELECT
              USING (rr_bypass() OR organization_id = rr_org() OR user_id = rr_user());
            CREATE POLICY membership_write ON memberships FOR ALL
              USING (rr_bypass() OR organization_id = rr_org())
              WITH CHECK (rr_bypass() OR organization_id = rr_org());

            ALTER TABLE organizations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE organizations FORCE ROW LEVEL SECURITY;
            CREATE POLICY organization_read ON organizations FOR SELECT
              USING (
                rr_bypass() OR id = rr_org() OR EXISTS (
                  SELECT 1 FROM memberships m
                  WHERE m.organization_id = organizations.id
                    AND m.user_id = rr_user()
                    AND m.status = 'active'
                )
              );
            CREATE POLICY organization_write ON organizations FOR ALL
              USING (rr_bypass() OR id = rr_org())
              WITH CHECK (rr_bypass() OR id = rr_org());
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([...self::TENANT_TABLES, 'memberships', 'organizations'] as $table) {
            DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
            DB::unprepared("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        }
        DB::unprepared('DROP POLICY IF EXISTS membership_read ON memberships; DROP POLICY IF EXISTS membership_write ON memberships;');
        DB::unprepared('DROP POLICY IF EXISTS organization_read ON organizations; DROP POLICY IF EXISTS organization_write ON organizations;');
        DB::unprepared('DROP FUNCTION IF EXISTS rr_org(); DROP FUNCTION IF EXISTS rr_user(); DROP FUNCTION IF EXISTS rr_bypass();');
    }
};
