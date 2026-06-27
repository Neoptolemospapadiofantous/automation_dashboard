<?php

use App\Models\Agent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Per-agent secret for signing OUTBOUND automation webhooks (agent → n8n).
 *
 * This is NOT the old Voiceflow `webhook_secret` (that one authenticated
 * INBOUND VF callbacks and was dropped with the Voiceflow schema). Automations
 * sign the request body with HMAC-SHA256 using this secret; the n8n workflow
 * verifies it before running, so a leaked webhook URL alone can't trigger it.
 *
 * Stored encrypted at rest via the model's `automation_secret => 'encrypted'`
 * cast — TEXT because the encrypted envelope overflows VARCHAR(255). Lives on
 * the agent (not the versioned config) because a credential is infrastructure,
 * not behavior: it must not roll back when an operator reverts a config version.
 *
 * Existing agents are backfilled with a fresh high-entropy secret so the
 * subsystem works the moment an operator configures their first action.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->text('automation_secret')->nullable()->after('slug');
        });

        // Backfill: one random secret per existing agent, encrypted via the
        // same cast the model uses (encrypt() here, model handles it after).
        DB::table('agents')->select('id')->orderBy('id')->each(function (object $row): void {
            DB::table('agents')->where('id', $row->id)->update([
                'automation_secret' => encrypt(Str::random(40)),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->dropColumn('automation_secret');
        });
    }
};
