<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomy_terms', function (Blueprint $table) {
            $table->id();
            $table->string('industry', 40)->default('healthcare');
            $table->string('kind', 30);          // org_type, program, function, topic, state
            $table->string('code', 80);
            $table->string('label', 160);
            $table->text('description')->nullable();
            $table->jsonb('metadata')->nullable(); // e.g. cfr_parts screening hints
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['industry', 'kind', 'code']);
            $table->index(['industry', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomy_terms');
    }
};
