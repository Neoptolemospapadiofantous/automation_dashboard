<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (provider, provider user id) linked to a Flowstack user —
 * "Continue with Google / Microsoft". A user may have several; a provider
 * identity belongs to exactly one user. The provider's email is kept for
 * support ("which Google account is this?"), never used as a login key
 * after the first link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_id');
            $table->string('email')->nullable();
            $table->string('name')->nullable();
            $table->string('avatar', 2000)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
            $table->index(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
