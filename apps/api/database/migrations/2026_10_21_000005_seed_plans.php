<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plans are reference data the app needs to create an organization, so
 * they ship with the schema. Limits gate features later; there is no
 * payment processing in the MVP.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $plans = [
            ['starter', 'Starter', ['seats' => 5, 'ai_analysis' => false, 'policy_analysis' => false, 'sources' => ['federal_register']]],
            ['professional', 'Professional', ['seats' => 25, 'ai_analysis' => true, 'policy_analysis' => true, 'sources' => ['federal_register']]],
            ['enterprise', 'Enterprise', ['seats' => null, 'ai_analysis' => true, 'policy_analysis' => true, 'multi_entity' => true, 'sources' => ['federal_register']]],
        ];

        foreach ($plans as [$code, $name, $limits]) {
            DB::table('plans')->insert([
                'code' => $code,
                'name' => $name,
                'limits' => json_encode($limits),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('plans')->whereIn('code', ['starter', 'professional', 'enterprise'])->delete();
    }
};
