<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase K — pre-provisioned Voiceflow project pool.
 *
 * Background: our original managed-mode design cloned environments inside
 * one master Voiceflow project per tenant. The Voiceflow docs we mirrored
 * later confirm (a) a HARD cap of 10 environments per project across all
 * tiers, and (b) KB documents, integrations, secrets, and analytics are
 * SHARED across environments in the same project — so the clone approach
 * leaked customer data across tenants in addition to capping at 10.
 *
 * The replacement: WE manually create N Voiceflow projects up front (one
 * per tenant slot), each with its own API key + KB + analytics. On signup
 * the app allocates the next available row from this table. Real isolation
 * (each customer gets their own project), zero per-signup latency.
 *
 * Re-population is a manual operator step until/unless Voiceflow opens
 * a project-creation API to us via their Partner Program.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voiceflow_project_pool', function (Blueprint $table) {
            $table->id();

            // The Voiceflow project this entry represents. Unique because a
            // single VF project can only ever be assigned to one tenant
            // (sharing would re-create the KB-leak problem).
            $table->string('voiceflow_project_id')->unique();
            $table->text('voiceflow_api_key'); // encrypted via model cast
            $table->text('voiceflow_workspace_api_key')->nullable();
            $table->string('voiceflow_environment')->default('main');

            // available | assigned | retired
            //   available = ready to allocate on next signup
            //   assigned  = currently in use by an agent (FK below)
            //   retired   = old assignment ended; not reused without a manual wipe
            //               (KB / conversation state are still in the VF project)
            $table->string('status')->default('available');

            $table->foreignId('assigned_to_agent_id')
                ->nullable()
                ->after('status')
                ->constrained('agents')
                ->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            // Free-form operator notes (e.g. "Created 2026-06-04, project name
            // 'Tenant Slot 47' in VF workspace 'Production'").
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voiceflow_project_pool');
    }
};
