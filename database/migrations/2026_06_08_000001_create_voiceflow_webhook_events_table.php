<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voiceflow_webhook_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $t->unsignedBigInteger('team_id')->nullable()->index();
            $t->string('event_type', 80)->index();
            $t->string('event_id', 120)->nullable()->index();
            $t->string('voiceflow_user_id')->nullable()->index();
            $t->string('voiceflow_session_key')->nullable();
            $t->string('voiceflow_transcript_id')->nullable()->index();
            $t->json('payload');
            $t->boolean('signature_valid')->default(false);
            $t->timestamp('received_at')->useCurrent()->index();
            $t->timestamp('processed_at')->nullable();
            $t->text('processing_error')->nullable();

            // Idempotency: same event delivered twice should not be stored twice.
            $t->unique(['agent_id', 'event_id'], 'vf_webhook_events_unique_per_agent');

            $t->index(['agent_id', 'event_type', 'received_at'], 'vf_webhook_events_agent_type_at');

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voiceflow_webhook_events');
    }
};
