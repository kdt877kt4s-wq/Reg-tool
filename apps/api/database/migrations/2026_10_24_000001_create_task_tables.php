<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance tasks, their history, evidence files and notifications.
 * All tenant data, all under row-level security.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['files', 'tasks', 'task_events', 'task_evidence', 'notifications', 'notification_preferences'];

    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('storage_key', 300);
            $table->string('filename', 255);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->string('scan_status', 20)->default('not_scanned'); // not_scanned, clean, infected
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->nullable()->constrained('impact_assessments')->nullOnDelete();
            $table->foreignId('action_id')->nullable()->unique()->constrained('suggested_actions')->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('regulatory_documents')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('priority', 10)->default('medium');
            $table->date('due_date')->nullable();
            $table->string('due_date_type', 10)->nullable();  // official, internal
            $table->string('due_date_basis', 200)->nullable(); // e.g. "Effective date of 2026-20134"
            $table->string('status', 20)->default('open');     // open, in_progress, submitted, returned, done, cancelled
            $table->text('status_note')->nullable();           // latest return or cancel reason
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'due_date']);
            $table->index(['organization_id', 'owner_id']);
            $table->index(['organization_id', 'department_id']);
        });

        Schema::create('task_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('type', 30);
            $table->jsonb('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['task_id', 'id']);
        });

        Schema::create('task_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('submitted_by')->constrained('users');
            $table->timestamp('created_at');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('dedupe_key', 200)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['organization_id', 'user_id', 'read_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);
            $table->boolean('email')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'user_id', 'kind']);
        });

        // Daily checks remember what they already reported.
        Schema::table('impact_assessments', function (Blueprint $table) {
            $table->unsignedInteger('change_notified_version')->nullable();
        });
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('match_digest_through')->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TENANT_TABLES as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
                CREATE POLICY tenant_isolation ON {$table}
                  USING (rr_bypass() OR organization_id = rr_org())
                  WITH CHECK (rr_bypass() OR organization_id = rr_org());
            SQL);
        }

        // History rows are never rewritten.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER task_events_no_change
                BEFORE UPDATE OR DELETE ON task_events
                FOR EACH ROW EXECUTE FUNCTION rr_audit_append_only();
        SQL);
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('match_digest_through'));
        Schema::table('impact_assessments', fn (Blueprint $t) => $t->dropColumn('change_notified_version'));
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
