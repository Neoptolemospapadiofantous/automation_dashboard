<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bring-your-own-key: a team on the Operator rung may supply its own
     * provider API key, and chat turns on that key cost 0 credits while
     * counting against a monthly message cap instead.
     *
     * One key per (team, provider) — a team with both an Anthropic and an
     * OpenAI key covers whichever provider its agent's tier resolves to.
     *
     * `api_key` is an encrypted cast on the model, so the column holds
     * ciphertext under APP_KEY and never a readable secret. `key_hint` is
     * the only thing ever rendered back to the user.
     *
     * NOTE: this is unrelated to the vestigial `agents.mode` default of
     * 'byok', which is Voiceflow-era and branches nothing.
     */
    public function up(): void
    {
        Schema::create('team_provider_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // 'anthropic' | 'openai' — matches runtime.tiers.*.provider.
            $table->string('provider', 32);

            // Encrypted at rest (model cast). Long: ciphertext is ~3x the key.
            $table->text('api_key');

            // Last 4 characters, for the UI. Never the key itself.
            $table->string('key_hint', 16)->nullable();

            // A key is only stored after a live probe; these carry the result
            // of that probe and of every scheduled re-verification, so a
            // revoked key surfaces as the customer's problem before a visitor
            // hits it.
            $table->timestamp('last_verified_at')->nullable();
            $table->string('last_error')->nullable();

            $table->timestamps();

            $table->unique(['team_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_provider_keys');
    }
};
