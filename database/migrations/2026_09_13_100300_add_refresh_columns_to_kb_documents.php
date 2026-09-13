<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * URL documents were ingested once and never looked at again, so a
 * customer whose site changed served stale answers with no warning.
 * knowledge:refresh-urls re-fetches every URL document weekly and
 * re-ingests the ones whose text changed; these columns record when it
 * last looked, when the content last changed, and the last fetch error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kb_documents', function (Blueprint $table): void {
            $table->timestamp('refresh_checked_at')->nullable()->after('chunk_count');
            $table->timestamp('refreshed_at')->nullable()->after('refresh_checked_at');
            $table->string('refresh_error', 500)->nullable()->after('refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::table('kb_documents', function (Blueprint $table): void {
            $table->dropColumn(['refresh_checked_at', 'refreshed_at', 'refresh_error']);
        });
    }
};
