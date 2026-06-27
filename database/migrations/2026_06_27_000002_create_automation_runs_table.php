<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log for every agent → n8n automation invocation. One row per call,
 * written before the request fires (status='pending') and updated on
 * completion. Doubles as the seed of the #14 structured data layer and the
 * source for the dashboard's automation activity view.
 *
 * idempotency_key is unique: the LLM (or a queue retry) re-issuing the same
 * logical call lands on the existing row instead of double-firing the webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();

            // Which session/turn triggered it (nullable: backfills, manual tests).
            $table->foreignId('runtime_session_id')->nullable()->constrained()->nullOnDelete();

            // Operator-defined action name from the published config.
            $table->string('action');

            // sync (request-response) | async (fire-and-forget queued job).
            $table->string('mode', 16);

            // pending → success | failed | blocked (SSRF) | timeout | out_of_credits.
            $table->string('status', 24)->default('pending');

            // De-dupe key: same logical invocation never fires twice.
            $table->string('idempotency_key')->unique();

            $table->unsignedInteger('credits_charged')->default(0);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Trimmed request args + response snippet for the activity view /
            // data layer. Capped at the application layer before write.
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['agent_id', 'created_at']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
