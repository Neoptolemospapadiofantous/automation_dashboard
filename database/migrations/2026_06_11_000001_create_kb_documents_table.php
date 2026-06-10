<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge base documents for the native runtime.
 *
 * One row per uploaded source (URL, PDF, pasted text). Chunks of the
 * document live in kb_chunks (next migration) and are what RAG actually
 * retrieves; this table is the parent so we can show "20 documents
 * uploaded" in the dashboard and delete a whole document at once.
 *
 * source: 'url' | 'file' | 'text' — matches the Knowledge page's three
 * upload paths. raw_content is the full text BEFORE chunking, kept so
 * we can re-chunk with a new strategy without re-fetching the original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kb_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
            $table->string('title');
            $table->string('source', 16); // 'url' | 'file' | 'text'
            $table->string('source_url')->nullable();
            $table->longText('raw_content');
            $table->json('metadata')->nullable();
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamps();

            $table->index(['agent_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_documents');
    }
};
