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

        // Optional FULLTEXT index on MySQL/MariaDB for the DB search fallback
        // (before Typesense is provisioned). It is purely a search optimization:
        // message recording must NEVER depend on it, so a failure here is
        // swallowed and logged rather than aborting the migration.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            try {
                Schema::table('messages', function (Blueprint $table) {
                    $table->fullText('text', 'messages_text_fulltext');
                });
            } catch (Throwable $e) {
                report($e);
            }
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
