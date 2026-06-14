<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable per-day token usage rollups, incremented by the FlowExecutor
 * after every turn. This exists because runtime_sessions is EPHEMERAL
 * (reset-on-launch deletes rows; pruning removes idle ones after 30
 * days) — its _tokens_in/_tokens_out variables show per-session burn
 * but can't answer "what did this team cost us in March".
 *
 * Consumed by `php artisan runtime:costs` (platform margin view).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // No FK: usage history must survive agent deletion (the cost
            // was still incurred). Nullable for the same reason.
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->date('date');
            $table->unsignedInteger('turns')->default(0);
            $table->unsignedBigInteger('tokens_in')->default(0);
            $table->unsignedBigInteger('tokens_out')->default(0);
            $table->timestamps();

            $table->unique(['team_id', 'agent_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_usage');
    }
};
