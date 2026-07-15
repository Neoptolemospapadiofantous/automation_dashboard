<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questions the knowledge base could not answer confidently (retrieval
 * top score below runtime.rag.answer_confidence). Written by the
 * FlowExecutor confidence gate, deduped per agent on a normalized hash of
 * the question so repeats increment a counter instead of piling up rows.
 * Surfaced on the Knowledge page as the operator's "fill these KB holes"
 * work list; resolving deletes the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->string('question', 500);
            $table->string('question_hash', 40);
            $table->float('top_score')->default(0);
            $table->unsignedInteger('asked_count')->default(1);
            $table->timestamp('last_asked_at');
            $table->timestamps();

            $table->unique(['agent_id', 'question_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_gaps');
    }
};
