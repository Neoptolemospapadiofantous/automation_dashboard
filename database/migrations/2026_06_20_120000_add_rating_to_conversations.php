<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Visitor's post-chat rating: bad | ok | good (null = unrated).
            $table->string('rating')->nullable()->after('status');
            $table->text('feedback_comment')->nullable()->after('rating');
            $table->timestamp('rated_at')->nullable()->after('feedback_comment');

            // Powers the operator's "last 5 rated" reference panel (per agent).
            $table->index(['agent_id', 'rated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['agent_id', 'rated_at']);
            $table->dropColumn(['rating', 'feedback_comment', 'rated_at']);
        });
    }
};
