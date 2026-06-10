<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime sessions — the equivalent of Voiceflow's session_key concept,
 * scoped to one visitor per agent.
 *
 * One row per (agent, visitor_id) tracks:
 *   - current state in the Flow (e.g. 'greeting', 'discovery', 'capture')
 *   - variables collected so far ({name, email, score, ...})
 *   - last activity timestamp for idle-cleanup
 *
 * The conversations + messages tables already store the actual transcript
 * — this table just holds the runtime's WORKING STATE between turns. A
 * conversation row links to a runtime_session via a foreign key (next
 * follow-up migration) so we can replay a session for QA.
 *
 * Idle sessions older than 30 days get pruned by a scheduled cleanup
 * (matches the visitor cookie TTL — re-engagement after 30 days starts
 * a fresh session).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runtime_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('visitor_id', 64);
            // Width allows hierarchical state IDs like 'flow.subflow.node_xyz'
            // for Phase 4's nested flow design.
            $table->string('flow_state', 128)->default('greeting');
            $table->json('variables')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'visitor_id']);
            $table->index('last_activity_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runtime_sessions');
    }
};
