<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pre-launch waitlist is gone (feature removed 2026-08-10 — the app
 * launched in June and COMING_SOON was false everywhere since; the only
 * rows were the founder's own test signups). Drops the orphaned table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('waitlist_signups');
    }

    public function down(): void
    {
        Schema::create('waitlist_signups', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('source')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }
};
