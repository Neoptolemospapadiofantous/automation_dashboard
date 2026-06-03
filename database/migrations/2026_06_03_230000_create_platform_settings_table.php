<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable platform-wide key/value settings.
 *
 * Use cases:
 *   - Marketing scarcity counters surfaced on the landing page
 *     (founder_slots_remaining, beta_cohort_size, etc.) that the operator
 *     adjusts by hand as signups come in.
 *   - Feature flags / kill switches we want togglable without a deploy.
 *
 * NOT for: per-tenant settings (those live on teams), per-agent settings
 * (those live on agents), or computed metrics (those derive from existing
 * tables — see PublicStatsController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
