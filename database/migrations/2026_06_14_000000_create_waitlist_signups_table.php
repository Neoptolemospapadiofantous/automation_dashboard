<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-launch waitlist captured by the coming-soon page. One row per email
 * (deduped). `source` records which surface captured it; `ip` is kept only
 * for light abuse triage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_signups', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('source')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_signups');
    }
};
