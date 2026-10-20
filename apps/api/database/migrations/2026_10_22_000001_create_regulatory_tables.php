<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Regulatory content is global: public documents ingested once and shared
 * by every organization. No tenant data lives in these tables, so they
 * carry no row-level security; what each organization decides about a
 * document is stored in tenant tables (Stage 3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();          // federal_register
            $table->string('name', 120);
            $table->string('base_url');
            $table->string('status', 20)->default('unknown'); // unknown, healthy, degraded
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->date('cursor_date')->nullable();        // newest publication date seen
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('source_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained();
            $table->string('status', 20);                    // running, succeeded, failed
            $table->date('window_start');
            $table->date('window_end');
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->index(['source_id', 'id']);
        });

        Schema::create('regulatory_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained();
            $table->string('external_id', 40);               // Federal Register document number
            $table->string('type', 30);                      // rule, proposed_rule, notice, presidential
            $table->string('action', 255)->nullable();       // as published, e.g. "Final rule; correction."
            $table->text('title');
            $table->text('abstract')->nullable();
            $table->text('dates_text')->nullable();
            $table->jsonb('agencies');                       // [{slug, name}]
            $table->jsonb('cfr_parts');                      // ["42 CFR 422", ...]
            $table->jsonb('docket_ids');
            $table->jsonb('rins');
            $table->string('citation', 40)->nullable();      // "91 FR 62323"
            $table->unsignedInteger('start_page')->nullable();
            $table->unsignedInteger('end_page')->nullable();
            $table->boolean('significant')->nullable();
            $table->date('publication_date');
            $table->date('effective_date')->nullable();      // never inferred from publication_date
            $table->date('comments_close_date')->nullable();
            $table->string('official_pdf_url')->nullable();  // GovInfo: the official edition
            $table->string('html_url')->nullable();          // FederalRegister.gov rendition
            $table->string('raw_text_url')->nullable();
            $table->string('status', 20)->default('active'); // active, corrected, withdrawn
            $table->string('text_status', 20)->default('pending'); // pending, available, unavailable
            $table->char('content_hash', 64);
            $table->unsignedInteger('current_version')->default(1);
            $table->boolean('is_demo')->default(false);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_changed_at');
            $table->timestamps();

            $table->unique(['source_id', 'external_id']);
            $table->index('publication_date');
            $table->index(['type', 'publication_date']);
        });

        Schema::create('regulatory_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('regulatory_documents')->cascadeOnDelete();
            $table->unsignedInteger('version_no');
            $table->char('content_hash', 64);
            $table->jsonb('metadata');                       // normalized snapshot at retrieval
            $table->jsonb('changes')->nullable();            // {field: {from, to}} vs previous version
            $table->char('text_sha256', 64)->nullable();
            $table->unsignedInteger('text_chars')->nullable();
            $table->timestamp('retrieved_at');
            $table->unique(['document_id', 'version_no']);
        });

        Schema::create('regulatory_passages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('regulatory_documents')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('regulatory_document_versions')->cascadeOnDelete();
            $table->unsignedInteger('ordinal');
            $table->string('heading_path', 500)->nullable();
            $table->text('text');
            $table->unsignedInteger('char_start');
            $table->unsignedInteger('char_end');
            $table->unsignedInteger('page_start')->nullable();
            $table->unsignedInteger('page_end')->nullable();
            $table->unique(['version_id', 'ordinal']);
        });

        Schema::create('regulatory_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_document_id')->constrained('regulatory_documents')->cascadeOnDelete();
            $table->string('relation', 20);                  // corrects, withdraws
            $table->string('to_external_id', 40);            // target document number as published
            $table->foreignId('to_document_id')->nullable()->constrained('regulatory_documents')->nullOnDelete();
            $table->string('method', 30);                    // structured_field, parsed_from_text
            $table->timestamps();
            $table->unique(['from_document_id', 'relation', 'to_external_id']);
            $table->index('to_external_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE regulatory_documents ADD COLUMN search_tsv tsvector
                  GENERATED ALWAYS AS (
                    setweight(to_tsvector('english', coalesce(title, '')), 'A') ||
                    setweight(to_tsvector('english', coalesce(abstract, '')), 'B') ||
                    setweight(to_tsvector('english', coalesce(action, '')), 'C')
                  ) STORED;
                CREATE INDEX regulatory_documents_search_idx ON regulatory_documents USING gin (search_tsv);
                CREATE INDEX regulatory_documents_cfr_idx ON regulatory_documents USING gin (cfr_parts);
                CREATE INDEX regulatory_documents_agencies_idx ON regulatory_documents USING gin (agencies jsonb_path_ops);

                ALTER TABLE regulatory_passages ADD COLUMN tsv tsvector
                  GENERATED ALWAYS AS (
                    to_tsvector('english', coalesce(heading_path, '') || ' ' || text)
                  ) STORED;
                CREATE INDEX regulatory_passages_tsv_idx ON regulatory_passages USING gin (tsv);

                -- Filled in Stage 3 (Voyage AI, 1024 dimensions).
                ALTER TABLE regulatory_passages ADD COLUMN embedding vector(1024);
            SQL);
        }

        DB::table('sources')->insert([
            'code' => 'federal_register',
            'name' => 'Federal Register',
            'base_url' => 'https://www.federalregister.gov/api/v1',
            'status' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (['regulatory_relations', 'regulatory_passages', 'regulatory_document_versions', 'regulatory_documents', 'source_runs', 'sources'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
