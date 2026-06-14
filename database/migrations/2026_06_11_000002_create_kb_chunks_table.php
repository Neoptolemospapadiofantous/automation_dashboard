<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge base chunks — the unit of RAG retrieval.
 *
 * Each parent document is split into ~500-token chunks (Chunker.php).
 * Per chunk we store the text, position within the document, and the
 * embedding vector. Similarity search hits this table.
 *
 * Embedding storage strategy: text column holding a JSON array of floats.
 * This works on SQLite (dev/test) AND Postgres without pgvector. Cosine
 * similarity runs in PHP — fine up to ~10k chunks per agent. For
 * production we add a follow-up migration that introduces a Postgres-only
 * vector column with HNSW index, and VectorSearch.php branches based on
 * the driver. Keeping the dev path JSON-only means tests don't need
 * pgvector installed, and feature parity is preserved.
 *
 * embedding_model is denormalized onto the chunk so we can rebuild the
 * vector when the model changes (e.g. swap text-embedding-3-small for
 * a different one) without needing to track a separate table.
 *
 * agent_id is denormalized for fast scoping — every query filters on
 * agent_id first, so embedding-level joins through kb_documents would
 * just add a JOIN that the index lookup avoids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('kb_documents')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->unsignedInteger('position'); // 0-indexed chunk order within document
            $table->text('content'); // the chunk text itself
            $table->json('embedding'); // JSON array of floats (dev) — pgvector column added in a follow-up migration
            $table->string('embedding_model', 64); // e.g. 'text-embedding-3-small'
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_chunks');
    }
};
