<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grounded-answers feature (phase 1).
 *
 * - agents.auto_escalate_low_confidence: per-agent toggle for the
 *   confidence-gated auto-escalate. Default on — trustworthy by default;
 *   an operator can opt a specific agent out.
 * - messages.citations: which KB sources grounded an answer
 *   ([{document_id, document_title, chunk_id, score}, ...]). Dedicated
 *   column (not folded into payload) so phase 3 can query ungrounded /
 *   low-confidence answers cheaply.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->boolean('auto_escalate_low_confidence')->default(true)->after('runtime_mode');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->json('citations')->nullable()->after('payload');
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropColumn('auto_escalate_low_confidence');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('citations');
        });
    }
};
