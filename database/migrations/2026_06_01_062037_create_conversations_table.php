<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();

            // Voiceflow linkage.
            $table->string('voiceflow_user_id')->index();
            $table->string('voiceflow_session_key')->nullable();
            $table->string('voiceflow_transcript_id')->nullable();

            $table->string('channel')->default('agent');     // agent | widget | webhook
            $table->string('status')->default('active');     // active | ended
            $table->unsignedInteger('message_count')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'last_message_at']);
            $table->index(['team_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
