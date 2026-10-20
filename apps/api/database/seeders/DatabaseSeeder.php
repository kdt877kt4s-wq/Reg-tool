<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference data only. No regulatory documents are seeded here:
        // regulations come from authoritative sources (Stage 2) or from a
        // separate, clearly flagged demonstration seeder.
        $this->call(HealthcareTaxonomySeeder::class);
    }
}
