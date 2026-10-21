<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI analyses and the human assessments built from them. Tenant data:
 * row-level security as in 2026_10_21_000004.
 */
return new class extends Migration
{
    private const TENANT_TABLES = ['ai_analyses', 'impact_assessments', 'suggested_actions'];

    public function up(): void
    {
        // Raw AI output is kept as produced (after validation) and never
        // edited; people edit the assessment instead.
        Schema::create('ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('regulatory_documents')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('regulatory_document_versions')->cascadeOnDelete();
            $table->unsignedInteger('profile_version')->default(0);
            $table->string('kind', 30)->default('impact');
            $table->string('status', 20);          // queued, running, completed, failed
            $table->string('model', 80)->nullable();
            $table->string('prompt_version', 40);
            $table->char('input_hash', 64)->nullable();
            $table->jsonb('passage_refs')->nullable();   // {"P1": passage_id, ...}
            $table->jsonb('output')->nullable();         // validated, refs resolved to passage ids
            $table->jsonb('validation')->nullable();     // removed statements, warnings
            $table->string('confidence', 10)->nullable(); // high, medium, low
            $table->text('error')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'document_id', 'id']);
        });

        // The human decision. One per organization and document.
        Schema::create('impact_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained('regulatory_documents')->cascadeOnDelete();
            $table->foreignId('analysis_id')->nullable()->constrained('ai_analyses')->nullOnDelete();
            $table->unsignedInteger('document_version');  // version the decision was made on
            $table->string('decision', 20)->nullable();    // applicable, not_applicable, monitor
            $table->text('rationale')->nullable();
            $table->jsonb('affected_functions');
            $table->string('status', 20)->default('draft'); // draft, approved
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'document_id']);
        });

        Schema::create('suggested_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained('impact_assessments')->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('medium'); // high, medium, low
            $table->jsonb('citation_passage_ids');
            $table->string('origin', 10);                     // ai, person
            $table->string('state', 20)->default('proposed'); // proposed, accepted, dismissed
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index(['assessment_id', 'position']);
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

        // Semantic search over passages (Voyage embeddings, cosine distance).
        DB::unprepared('CREATE INDEX regulatory_passages_embedding_idx ON regulatory_passages USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP INDEX IF EXISTS regulatory_passages_embedding_idx');
        }
        foreach (array_reverse(self::TENANT_TABLES) as $t) {
            Schema::dropIfExists($t);
        }
    }
};
