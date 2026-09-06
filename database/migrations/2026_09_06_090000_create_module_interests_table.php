<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per team per suite module they asked for. This is the demand
 * signal for modules that are not built yet: the Suite page offers a
 * "request it" action on every `coming` module, and the count here is
 * what decides what gets built next — not a guess about which bundle
 * customers would want.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_interests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module_key', 40);
            $table->timestamps();

            $table->unique(['team_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_interests');
    }
};
