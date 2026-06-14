<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Voiceflow is fully removed: the native runtime (app/Runtime) is the only
 * engine. This migration drops the Voiceflow-specific schema:
 *
 *  - voiceflow_project_pool        (operator-stocked project inventory)
 *  - voiceflow_webhook_events      (inbound VF webhook audit log)
 *  - agents.voiceflow_*            (per-agent credentials, encrypted)
 *  - agents.webhook_secret         (authenticated inbound VF webhooks)
 *
 * Kept on purpose:
 *  - leads.voiceflow_user_id + conversations.voiceflow_* columns — they're
 *    the generic "external chat user/session id" both engines used; the
 *    native runtime reuses them for continuity and historical rows keep
 *    their linkage. (Renaming them is pure churn; documented in the models.)
 *
 * Also flips every agent to runtime_mode='native' and makes that the
 * column default — there is no other engine.
 *
 * down() restores the SHAPE only (tables/columns empty) — the data is
 * gone by design; the code that wrote it no longer exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('agents')->update(['runtime_mode' => 'native']);

        Schema::table('agents', function (Blueprint $table): void {
            $table->dropColumn([
                'voiceflow_api_key',
                'voiceflow_project_id',
                'voiceflow_environment',
                'voiceflow_workspace_api_key',
                'webhook_secret',
            ]);
        });

        Schema::table('agents', function (Blueprint $table): void {
            $table->string('runtime_mode', 32)->default('native')->change();
        });

        Schema::dropIfExists('voiceflow_webhook_events');
        Schema::dropIfExists('voiceflow_project_pool');
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->text('voiceflow_api_key')->nullable();
            $table->string('voiceflow_project_id')->nullable();
            $table->string('voiceflow_environment')->default('main');
            $table->text('voiceflow_workspace_api_key')->nullable();
            $table->text('webhook_secret')->nullable();
        });

        Schema::table('agents', function (Blueprint $table): void {
            $table->string('runtime_mode', 32)->default('voiceflow')->change();
        });
    }
};
