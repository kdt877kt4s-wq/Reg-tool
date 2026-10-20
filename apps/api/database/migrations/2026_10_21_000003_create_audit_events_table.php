<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('action', 80);
            $table->string('subject_type', 80)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at');
            // Hash chain per organization: each row's hash covers its own
            // content and the previous row's hash.
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->index(['organization_id', 'id']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Append-only at the database level: updates, deletes and truncates
        // are rejected even for the table owner.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rr_audit_append_only() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'audit_events is append-only';
            END
            $$;

            CREATE TRIGGER audit_events_no_change
                BEFORE UPDATE OR DELETE ON audit_events
                FOR EACH ROW EXECUTE FUNCTION rr_audit_append_only();

            CREATE TRIGGER audit_events_no_truncate
                BEFORE TRUNCATE ON audit_events
                FOR EACH STATEMENT EXECUTE FUNCTION rr_audit_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS rr_audit_append_only()');
        }
    }
};
