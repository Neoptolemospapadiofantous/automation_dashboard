<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each Agent row holds the Voiceflow credentials a single team uses to
     * talk to one Voiceflow project. Teams can have many agents (sales bot,
     * support bot, etc.); the team's current_agent_id picks which one the
     * dashboard is looking at right now.
     */
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // URL-safe identifier used in the per-agent webhook path.
            // Globally unique so the webhook router doesn't need the team id.
            $table->string('slug')->unique();

            // Credentials. Stored encrypted at rest via the model cast.
            $table->text('voiceflow_api_key')->nullable();
            $table->string('voiceflow_project_id')->nullable();
            $table->string('voiceflow_environment')->default('main');

            // Optional richer-features key (transcripts/analytics/KB).
            // The app degrades gracefully when missing.
            $table->text('voiceflow_workspace_api_key')->nullable();

            // High-entropy secret the Voiceflow agent must send back as
            // X-Webhook-Secret. Per-agent so a leak doesn't compromise others.
            $table->string('webhook_secret');

            // draft = created but health-check not green yet; active = ready;
            // disabled = user paused it. We render the right empty-state per status.
            $table->string('status')->default('draft');

            $table->timestamp('last_health_check_at')->nullable();
            $table->boolean('last_health_ok')->default(false);

            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
