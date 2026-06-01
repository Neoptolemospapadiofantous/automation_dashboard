<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // Denormalized for team-scoped reads/search without a join.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('role');              // user | agent | system
            $table->longText('text')->nullable();
            $table->string('trace_type')->nullable();
            $table->json('payload')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'sequence']);
            $table->index(['team_id', 'created_at']);
        });

        // MySQL gets a FULLTEXT index as the zero-infra search fallback before
        // Typesense is provisioned. SQLite (local/CI) skips this gracefully.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE messages ADD FULLTEXT messages_text_fulltext (text)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
