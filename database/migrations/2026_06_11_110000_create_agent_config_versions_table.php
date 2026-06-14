<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned agent behavior — the native analog of the old (Voiceflow)
 * Environments page. One row per saved version of an agent's
 * operator-editable config:
 *
 *   config JSON: { instructions: string, greeting: string }
 *     instructions — appended to the engine's system prompt every turn
 *     greeting     — extra guidance for the greeting state's first message
 *
 * Lifecycle: at most ONE draft and ONE published per agent at a time.
 *   draft     — being edited; the engine ignores it
 *   published — live; FlowExecutor injects it into every turn
 *   archived  — history; restorable into the draft
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_config_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft'); // draft | published | archived
            $table->json('config');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'version']);
            // The engine looks up (agent_id, status='published') every turn.
            $table->index(['agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_config_versions');
    }
};
