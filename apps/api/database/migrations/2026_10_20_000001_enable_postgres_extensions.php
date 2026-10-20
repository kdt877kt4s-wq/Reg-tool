<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * pgvector powers passage retrieval (Stage 2); pg_trgm supports fuzzy
     * search on titles. Both are available on Azure Database for PostgreSQL
     * Flexible Server once allow-listed in the azure.extensions parameter.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
    }

    public function down(): void
    {
        // Extensions are left in place: other objects may depend on them.
    }
};
