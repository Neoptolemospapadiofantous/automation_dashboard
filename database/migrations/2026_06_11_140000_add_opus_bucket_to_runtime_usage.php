<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third tier bucket for the full model lineup. Column lineage:
 * tokens_in/out = haiku (née standard), *_enhanced = sonnet,
 * *_opus = opus. runtime:costs prices each bucket at its tier's rates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runtime_usage', function (Blueprint $table): void {
            $table->unsignedBigInteger('tokens_in_opus')->default(0)->after('tokens_out_enhanced');
            $table->unsignedBigInteger('tokens_out_opus')->default(0)->after('tokens_in_opus');
        });
    }

    public function down(): void
    {
        Schema::table('runtime_usage', function (Blueprint $table): void {
            $table->dropColumn(['tokens_in_opus', 'tokens_out_opus']);
        });
    }
};
