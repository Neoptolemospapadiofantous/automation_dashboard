<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Free-form tags on leads and labels on conversations — the first way to
 * categorise anything in the app. Stored as a JSON array of short strings
 * (lower-cased, trimmed, deduped by the model); filtered with
 * whereJsonContains, which MySQL, MariaDB and SQLite all support. No
 * vocabulary table: a team's tag set is whatever it has typed, surfaced
 * as suggestions from the rows on screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->json('tags')->nullable()->after('notes');
        });
        Schema::table('conversations', function (Blueprint $table): void {
            $table->json('labels')->nullable()->after('meta');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropColumn('tags');
        });
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropColumn('labels');
        });
    }
};
