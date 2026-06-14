<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Five tiers (and counting) made bucket-columns untenable — generalize
 * to one row per (team, agent, date, TIER). Pre-launch: dev rollups are
 * disposable, so rebuild instead of migrating buckets into rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('runtime_usage');

        Schema::create('runtime_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // No FK: usage history must survive agent deletion.
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->date('date');
            $table->string('tier', 32)->default('haiku');
            $table->unsignedInteger('turns')->default(0);
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'agent_id', 'date', 'tier']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        // Shape-only restore of the bucket-column variant.
        Schema::dropIfExists('runtime_usage');

        Schema::create('runtime_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->date('date');
            $table->unsignedInteger('turns')->default(0);
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->unsignedBigInteger('tokens_in_enhanced')->default(0);
            $table->unsignedBigInteger('tokens_out_enhanced')->default(0);
            $table->unsignedBigInteger('tokens_in_opus')->default(0);
            $table->unsignedBigInteger('tokens_out_opus')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'agent_id', 'date']);
            $table->index('date');
        });
    }
};
