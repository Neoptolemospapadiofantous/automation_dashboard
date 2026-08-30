<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §3.1 findings tree, persisted. The on-disk tree under data/agents/ lives
 * inside the release directory on Forge and is wiped by every deploy, and
 * the grid reads a different box's copy anyway — so the DB is the record
 * and the files become a mirror.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_findings', function (Blueprint $table) {
            $table->id();
            $table->string('collector', 64);
            // The collector's own timestamp, not inserted_at — a stale run
            // must read as stale.
            $table->dateTime('ts');
            $table->string('overall', 8);
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['collector', 'ts']);
            $table->index(['collector', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_findings');
    }
};
