<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per credit movement (granted from a plan renewal, consumed by
     * a message, topped up by a Stripe charge). The team.credit_balance is
     * the cached sum; this table is the audit log + ground truth for
     * reconciliation if the cache ever drifts.
     *
     * amount is signed: positive for grants/top-ups, negative for consumption.
     */
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // Optional agent — most consumption is per-agent (chat message)
            // but a renewal grant has no agent.
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();

            $table->integer('amount');
            // grant_renewal | grant_topup | consume_message | refund | adjustment
            $table->string('reason');
            // Free-form context (Stripe invoice id, message conversation id, ...).
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
