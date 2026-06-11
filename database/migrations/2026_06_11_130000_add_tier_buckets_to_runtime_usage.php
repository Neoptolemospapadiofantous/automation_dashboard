<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quality tiers (standard=Haiku, enhanced=Sonnet) price tokens
 * differently, so the margin report needs per-tier buckets. The
 * existing tokens_in/tokens_out columns become the STANDARD bucket;
 * enhanced turns land in the new columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runtime_usage', function (Blueprint $table): void {
            $table->unsignedBigInteger('tokens_in_enhanced')->default(0)->after('tokens_out');
            $table->unsignedBigInteger('tokens_out_enhanced')->default(0)->after('tokens_in_enhanced');
        });
    }

    public function down(): void
    {
        Schema::table('runtime_usage', function (Blueprint $table): void {
            $table->dropColumn(['tokens_in_enhanced', 'tokens_out_enhanced']);
        });
    }
};
