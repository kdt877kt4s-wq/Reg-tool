<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global catalog of subscription plans (not tenant data).
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();     // starter, professional, enterprise
            $table->string('name', 80);
            $table->jsonb('limits');                   // feature flags and quotas
            $table->timestamps();
        });

        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 80)->unique();
            $table->string('industry', 40)->default('healthcare');
            $table->unsignedInteger('data_retention_days')->nullable();
            $table->jsonb('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 30);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('active'); // active, suspended, removed
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('role', 30);
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('token_hash', 64)->unique(); // sha256 of the emailed token
            $table->foreignId('invited_by')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'email']);
        });

        Schema::create('organization_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->jsonb('org_types');
            $table->jsonb('states');
            $table->jsonb('programs');
            $table->jsonb('functions');      // business activities / operational functions
            $table->jsonb('products');       // free-text products and services
            $table->jsonb('obligations');    // free-text known obligations
            $table->unsignedInteger('version')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        // Append-only history: every applicability screening records the
        // profile version it used, so past decisions stay explainable.
        Schema::create('organization_profile_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('snapshot');
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->timestamp('created_at');
            $table->unique(['organization_id', 'version']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            $table->string('status', 20);    // trialing, active, past_due, canceled
            $table->timestamp('current_period_end')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['subscriptions', 'organization_profile_versions', 'organization_profiles', 'invitations', 'memberships', 'departments', 'organizations', 'plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
